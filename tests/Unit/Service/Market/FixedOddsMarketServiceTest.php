<?php

declare(strict_types=1);

namespace Tests\Unit\Service\Market;

use App\Domain\Bet\Bet;
use App\Domain\Bet\BetOption;
use App\Domain\Bet\BetStatus;
use App\Domain\Bet\BettingMode;
use App\Domain\Bet\OddsEvolutionMode;
use App\Domain\Stake\Stake;
use App\Service\Market\FixedOddsMarketService;
use App\Service\Market\MarketSettings;
use App\Service\Market\ProbabilityNormalizer;
use PHPUnit\Framework\TestCase;

final class FixedOddsMarketServiceTest extends TestCase
{
    private FixedOddsMarketService $service;

    protected function setUp(): void
    {
        $settings = new MarketSettings();
        $this->service = new FixedOddsMarketService($settings, new ProbabilityNormalizer($settings));
    }

    public function testOfferedOddsDeriveFromTheInitialProbabilitiesAndTheBookmakerMargin(): void
    {
        $bet = $this->bet(OddsEvolutionMode::Fixed, 1000, [0.80, 0.20]);

        $quote = $this->service->quote($bet, []);

        // 1 / (0.80 * 1.10) and 1 / (0.20 * 1.10), rounded to two decimals.
        self::assertSame(1.14, $quote->odds(10));
        self::assertSame(4.55, $quote->odds(11));
    }

    public function testOptionsWithoutDeclaredProbabilitiesAreEquiprobable(): void
    {
        $bet = $this->bet(OddsEvolutionMode::Fixed, 0, [null, null]);

        $quote = $this->service->quote($bet, []);

        self::assertSame(2.0, $quote->odds(10));
        self::assertSame(2.0, $quote->odds(11));
    }

    public function testOddsNeverFallBelowTheStakeItself(): void
    {
        $bet = $this->bet(OddsEvolutionMode::Fixed, 2500, [0.98, 0.02]);

        $quote = $this->service->quote($bet, []);

        self::assertSame(1.0, $quote->odds(10));
    }

    public function testFixedEvolutionIgnoresTheStakesAlreadyPlaced(): void
    {
        $bet = $this->bet(OddsEvolutionMode::Fixed, 1000, [0.50, 0.50]);
        $stakes = [$this->stake(1, 10, 5000, true), $this->stake(2, 11, 100, true)];

        self::assertSame(
            $this->service->quote($bet, [])->oddsByOptionId,
            $this->service->quote($bet, $stakes)->oddsByOptionId,
        );
    }

    public function testDynamicEvolutionShortensTheOddsOfTheMostBackedOption(): void
    {
        $bet = $this->bet(OddsEvolutionMode::DynamicNormal, 1000, [0.50, 0.50]);
        $stakes = [$this->stake(1, 10, 5000, true)];

        $quote = $this->service->quote($bet, $stakes);

        self::assertLessThan(1.82, (float) $quote->odds(10));
        self::assertGreaterThan(1.82, (float) $quote->odds(11));
        self::assertEqualsWithDelta(1.0, array_sum($quote->probabilitiesByOptionId), 0.000_001);
        self::assertSame(5000.0, $quote->effectivePool);
    }

    public function testAHigherEvolutionModeMovesTheOddsFurther(): void
    {
        $stakes = [$this->stake(1, 10, 5000, true)];

        $low = $this->service->quote($this->bet(OddsEvolutionMode::DynamicLow, 1000, [0.50, 0.50]), $stakes);
        $high = $this->service->quote($this->bet(OddsEvolutionMode::DynamicHigh, 1000, [0.50, 0.50]), $stakes);

        self::assertLessThan((float) $low->odds(10), (float) $high->odds(10));
    }

    public function testUnpaidStakesWeighLessOnTheMarketThanPaidOnes(): void
    {
        $bet = $this->bet(OddsEvolutionMode::DynamicNormal, 1000, [0.50, 0.50]);

        $unpaid = $this->service->quote($bet, [$this->stake(1, 10, 1000, false)]);
        $paid = $this->service->quote($bet, [$this->stake(1, 10, 1000, true)]);

        self::assertSame(500.0, $unpaid->effectivePool);
        self::assertSame(1000.0, $paid->effectivePool);
        self::assertGreaterThan((float) $paid->odds(10), (float) $unpaid->odds(10));
    }

    public function testCancelledStakesAreIgnoredByTheMarket(): void
    {
        $bet = $this->bet(OddsEvolutionMode::DynamicNormal, 1000, [0.50, 0.50]);
        $cancelled = new Stake(1, 1, 10, 20, 5000, 'Alice', 'Blue', false, true, true);

        self::assertSame(
            $this->service->quote($bet, [])->oddsByOptionId,
            $this->service->quote($bet, [$cancelled])->oddsByOptionId,
        );
    }

    public function testWinnersArePaidAtTheirContractualOddsWithoutLevyOnThePot(): void
    {
        $bet = $this->bet(OddsEvolutionMode::Fixed, 1000, [0.50, 0.50]);
        $stakes = [
            $this->stake(1, 10, 100, true, 2.0),
            $this->stake(2, 10, 200, true, 1.5),
            $this->stake(3, 11, 700, true, 2.0),
        ];

        $financials = $this->service->settle($bet, $stakes, 10);

        self::assertSame(1000, $financials->pot);
        self::assertSame(0, $financials->bookmakerShare);
        self::assertSame(500, $financials->redistributed);
        self::assertSame(500, $financials->bookmakerResult);
        self::assertSame($financials->pot - $financials->redistributed, $financials->bookmakerResult);
        self::assertSame([1 => 200, 2 => 300], $financials->payoutsByStakeId);
    }

    public function testUnpaidAndCancelledStakesAreExcludedFromTheSettlement(): void
    {
        $bet = $this->bet(OddsEvolutionMode::Fixed, 1000, [0.50, 0.50]);
        $stakes = [
            $this->stake(1, 10, 100, true, 2.0),
            $this->stake(2, 10, 500, false, 2.0),
            new Stake(3, 1, 10, 22, 400, 'Carol', 'Blue', false, true, true, null, 2.0, 2.0),
        ];

        $financials = $this->service->settle($bet, $stakes, 10);

        self::assertSame(100, $financials->pot);
        self::assertSame([1 => 200], $financials->payoutsByStakeId);
        self::assertSame(-100, $financials->bookmakerResult);
    }

    public function testStakesWithoutContractualOddsAreRefunded(): void
    {
        $bet = $this->bet(OddsEvolutionMode::Fixed, 1000, [0.50, 0.50]);
        $stakes = [$this->stake(1, 10, 300, true)];

        $financials = $this->service->settle($bet, $stakes, 10);

        self::assertSame([1 => 300], $financials->payoutsByStakeId);
        self::assertSame(0, $financials->bookmakerResult);
    }

    public function testTheWholePotIsKeptWhenTheWinningOptionHasNoPaidStake(): void
    {
        $bet = $this->bet(OddsEvolutionMode::Fixed, 1000, [0.50, 0.50]);
        $stakes = [$this->stake(1, 11, 700, true, 2.0)];

        $financials = $this->service->settle($bet, $stakes, 10);

        self::assertSame(700, $financials->pot);
        self::assertSame(0, $financials->redistributed);
        self::assertSame(700, $financials->bookmakerResult);
        self::assertSame([], $financials->payoutsByStakeId);
    }

    /** @param list<float|null> $probabilities */
    private function bet(OddsEvolutionMode $evolution, int $bookmakerRateBps, array $probabilities): Bet
    {
        return new Bet(1, 7, 'Winner?', null, null, BetStatus::Open, null, [
            new BetOption(10, 'Blue', 1, null, $probabilities[0]),
            new BetOption(11, 'Red', 2, null, $probabilities[1]),
        ], $bookmakerRateBps, null, null, null, BettingMode::FixedOdds, $evolution);
    }

    private function stake(int $id, int $optionId, int $amount, bool $isPaid, ?float $oddsAtBet = null): Stake
    {
        return new Stake($id, 1, $optionId, 20 + $id, $amount, 'Alice', 'Blue', false, $isPaid, false, null, $oddsAtBet, $oddsAtBet);
    }
}

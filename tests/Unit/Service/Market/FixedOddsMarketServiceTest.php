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
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class FixedOddsMarketServiceTest extends TestCase
{
    private FixedOddsMarketService $service;

    protected function setUp(): void
    {
        $this->service = new FixedOddsMarketService(new MarketSettings());
    }

    public function testOfferedOddsAreTheOnesPricedByTheBookmaker(): void
    {
        $bet = $this->bet(OddsEvolutionMode::Fixed, [1.14, 4.55]);

        $quote = $this->service->quote($bet, []);

        self::assertSame(1.14, $quote->odds(10));
        self::assertSame(4.55, $quote->odds(11));
    }

    public function testAnUnpricedOptionStaysUnpriced(): void
    {
        $bet = $this->bet(OddsEvolutionMode::DynamicNormal, [2.00, null]);

        $quote = $this->service->quote($bet, [$this->stake(1, 10, 5000, true, 2.00)]);

        // A partially priced book carries no implied share, so nothing drifts.
        self::assertSame(2.00, $quote->odds(10));
        self::assertNull($quote->odds(11));
    }

    public function testFixedEvolutionIgnoresTheStakesAlreadyPlaced(): void
    {
        $bet = $this->bet(OddsEvolutionMode::Fixed, [1.82, 1.82]);
        $stakes = [$this->stake(1, 10, 5000, true, 1.82), $this->stake(2, 11, 100, true, 1.82)];

        self::assertSame(
            $this->service->quote($bet, [])->oddsByOptionId,
            $this->service->quote($bet, $stakes)->oddsByOptionId,
        );
    }

    public function testDynamicEvolutionShortensTheOddsOfTheMostBackedOption(): void
    {
        $bet = $this->bet(OddsEvolutionMode::DynamicNormal, [1.82, 1.82]);
        $stakes = [$this->stake(1, 10, 5000, true, 1.82)];

        $quote = $this->service->quote($bet, $stakes);

        self::assertLessThan(1.82, (float) $quote->odds(10));
        self::assertGreaterThan(1.82, (float) $quote->odds(11));
        self::assertSame(5000.0, $quote->effectivePool);
    }

    public function testAHigherEvolutionModeMovesTheOddsFurther(): void
    {
        $stakes = [$this->stake(1, 10, 5000, true, 1.82)];

        $low = $this->service->quote($this->bet(OddsEvolutionMode::DynamicLow, [1.82, 1.82]), $stakes);
        $high = $this->service->quote($this->bet(OddsEvolutionMode::DynamicHigh, [1.82, 1.82]), $stakes);

        self::assertLessThan((float) $low->odds(10), (float) $high->odds(10));
    }

    public function testTheDriftNeverExceedsTheCeilingOfItsMode(): void
    {
        $bet = $this->bet(OddsEvolutionMode::DynamicHigh, [1.82, 1.82]);
        $stakes = [$this->stake(1, 10, 10_000_000, true, 1.82)];

        $quote = $this->service->quote($bet, $stakes);

        // 25% is the widest drift the strongest mode may ever apply.
        self::assertGreaterThanOrEqual(1.82 * 0.75, (float) $quote->odds(10));
    }

    public function testAThinMarketBarelyMovesThePricedOdds(): void
    {
        $bet = $this->bet(OddsEvolutionMode::DynamicNormal, [1.82, 1.82]);

        $thin = $this->service->quote($bet, [$this->stake(1, 10, 10, true, 1.82)]);
        $deep = $this->service->quote($bet, [$this->stake(1, 10, 10_000, true, 1.82)]);

        self::assertEqualsWithDelta(1.82, (float) $thin->odds(10), 0.02);
        self::assertLessThan((float) $thin->odds(10), (float) $deep->odds(10));
    }

    public function testUnpaidStakesWeighLessOnTheMarketThanPaidOnes(): void
    {
        $bet = $this->bet(OddsEvolutionMode::DynamicNormal, [1.82, 1.82]);

        $unpaid = $this->service->quote($bet, [$this->stake(1, 10, 1000, false, 1.82)]);
        $paid = $this->service->quote($bet, [$this->stake(1, 10, 1000, true, 1.82)]);

        self::assertGreaterThan((float) $paid->odds(10), (float) $unpaid->odds(10));
    }

    public function testCancelledStakesAreIgnoredByTheMarket(): void
    {
        $bet = $this->bet(OddsEvolutionMode::DynamicNormal, [1.82, 1.82]);
        $cancelled = new Stake(1, 1, 10, 20, 5000, 'Alice', 'Blue', false, true, true, null, 1.82, new DateTimeImmutable());

        self::assertSame(
            $this->service->quote($bet, [])->oddsByOptionId,
            $this->service->quote($bet, [$cancelled])->oddsByOptionId,
        );
    }

    public function testOnlyTheStakesTakenAfterThePricingFeedTheDrift(): void
    {
        $anchor = new DateTimeImmutable('2026-08-30 12:00:00');
        $bet = $this->bet(OddsEvolutionMode::DynamicNormal, [1.82, 1.82], $anchor);
        $before = $this->stake(1, 10, 5000, true, 1.82, new DateTimeImmutable('2026-08-30 11:00:00'));

        self::assertSame(
            $this->service->quote($bet, [])->oddsByOptionId,
            $this->service->quote($bet, [$before])->oddsByOptionId,
        );
    }

    public function testWinnersArePaidAtTheirContractualOddsWithoutLevyOnThePot(): void
    {
        $bet = $this->bet(OddsEvolutionMode::Fixed, [2.00, 2.00]);
        $stakes = [
            $this->stake(1, 10, 100, true, 2.0),
            $this->stake(2, 10, 200, true, 1.5),
            $this->stake(3, 11, 700, true, 2.0),
        ];

        $financials = $this->service->settle($bet, $stakes, 10);

        self::assertSame(1000, $financials->pot);
        self::assertSame(0, $financials->bookmakerShare);
        self::assertSame(500, $financials->redistributed);
        self::assertSame($financials->pot - $financials->redistributed, $financials->bookmakerResult);
        self::assertSame([1 => 200, 2 => 300], $financials->payoutsByStakeId);
    }

    public function testUnpaidAndCancelledStakesAreExcludedFromTheSettlement(): void
    {
        $bet = $this->bet(OddsEvolutionMode::Fixed, [2.00, 2.00]);
        $stakes = [
            $this->stake(1, 10, 100, true, 2.0),
            $this->stake(2, 10, 500, false, 2.0),
            new Stake(3, 1, 10, 22, 400, 'Carol', 'Blue', false, true, true, null, 2.0),
        ];

        $financials = $this->service->settle($bet, $stakes, 10);

        self::assertSame(100, $financials->pot);
        self::assertSame([1 => 200], $financials->payoutsByStakeId);
        self::assertSame(-100, $financials->bookmakerResult);
    }

    public function testStakesWithoutContractualOddsAreRefunded(): void
    {
        $bet = $this->bet(OddsEvolutionMode::Fixed, [2.00, 2.00]);
        $stakes = [$this->stake(1, 10, 300, true)];

        $financials = $this->service->settle($bet, $stakes, 10);

        self::assertSame([1 => 300], $financials->payoutsByStakeId);
        self::assertSame(0, $financials->bookmakerResult);
    }

    public function testTheWholePotIsKeptWhenTheWinningOptionHasNoPaidStake(): void
    {
        $bet = $this->bet(OddsEvolutionMode::Fixed, [2.00, 2.00]);
        $stakes = [$this->stake(1, 11, 700, true, 2.0)];

        $financials = $this->service->settle($bet, $stakes, 10);

        self::assertSame(700, $financials->pot);
        self::assertSame(0, $financials->redistributed);
        self::assertSame(700, $financials->bookmakerResult);
        self::assertSame([], $financials->payoutsByStakeId);
    }

    public function testSettlementRecordsThePricedOddsOfEachOption(): void
    {
        $bet = $this->bet(OddsEvolutionMode::Fixed, [2.50, 1.60]);

        $financials = $this->service->settle($bet, [], 10);

        self::assertSame([10 => 2.50, 11 => 1.60], $financials->oddsByOptionId);
    }

    /** @param list<float|null> $odds */
    private function bet(OddsEvolutionMode $evolution, array $odds, ?DateTimeImmutable $anchoredAt = null): Bet
    {
        return new Bet(1, 7, 'Winner?', null, null, BetStatus::Open, null, [
            new BetOption(10, 'Blue', 1, $odds[0]),
            new BetOption(11, 'Red', 2, $odds[1]),
        ], null, null, null, BettingMode::FixedOdds, $evolution, 1000, null, $anchoredAt);
    }

    private function stake(
        int $id,
        int $optionId,
        int $amount,
        bool $isPaid,
        ?float $oddsAtBet = null,
        ?DateTimeImmutable $createdAt = null,
    ): Stake {
        return new Stake(
            $id,
            1,
            $optionId,
            20 + $id,
            $amount,
            'Alice',
            'Blue',
            false,
            $isPaid,
            false,
            null,
            $oddsAtBet,
            $createdAt ?? new DateTimeImmutable(),
        );
    }
}

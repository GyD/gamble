<?php

declare(strict_types=1);

namespace Tests\Unit\Service\Market;

use App\Domain\Bet\Bet;
use App\Domain\Bet\BetOption;
use App\Domain\Bet\BetStatus;
use App\Domain\Bet\BettingMode;
use App\Domain\Bet\OddsEvolutionMode;
use App\Domain\Stake\Stake;
use App\Service\Market\MarketSettings;
use App\Service\Market\PariMutuelMarketService;
use PHPUnit\Framework\TestCase;

final class PariMutuelMarketServiceTest extends TestCase
{
    private PariMutuelMarketService $service;

    protected function setUp(): void
    {
        $this->service = new PariMutuelMarketService(new MarketSettings());
    }

    public function testIndicativeOddsComeFromTheEstimatedNetPool(): void
    {
        $bet = $this->bet(1000);
        $stakes = [$this->stake(1, 10, 1000, true), $this->stake(2, 11, 3000, true)];

        $quote = $this->service->quote($bet, $stakes);

        // Net pool of 3600 shared by the money on each option.
        self::assertSame(3.6, $quote->odds(10));
        self::assertSame(1.2, $quote->odds(11));
        self::assertSame(4000.0, $quote->effectivePool);
    }

    public function testAnOptionWithoutStakeHasNoIndicativeOdds(): void
    {
        $quote = $this->service->quote($this->bet(1000), [$this->stake(1, 10, 1000, true)]);

        self::assertNull($quote->odds(11));
    }

    public function testUnpaidStakesOnlyCountPartiallyInTheIndicativeOdds(): void
    {
        $bet = $this->bet(0);

        $quote = $this->service->quote($bet, [$this->stake(1, 10, 1000, false), $this->stake(2, 11, 500, true)]);

        self::assertSame(1000.0, $quote->effectivePool);
        // The unpaid stake of 1000 only weighs 500, as much as the paid 500.
        self::assertSame(2.0, $quote->odds(10));
        self::assertSame(2.0, $quote->odds(11));
    }

    public function testCommissionIsLeviedOnThePoolAndTheRestIsRedistributed(): void
    {
        $bet = $this->bet(1000);
        $stakes = [
            $this->stake(1, 10, 100, true),
            $this->stake(2, 10, 200, true),
            $this->stake(3, 11, 700, true),
        ];

        $financials = $this->service->settle($bet, $stakes, 10);

        self::assertSame(1000, $financials->pot);
        self::assertSame(100, $financials->bookmakerShare);
        self::assertSame(900, $financials->redistributed);
        self::assertSame(100, $financials->bookmakerResult);
        self::assertSame($financials->pot - $financials->redistributed, $financials->bookmakerResult);
        self::assertSame([1 => 300, 2 => 600], $financials->payoutsByStakeId);
        self::assertSame(3.0, $financials->oddsByOptionId[10]);
    }

    public function testUnpaidAndCancelledStakesAreExcludedFromThePool(): void
    {
        $bet = $this->bet(1000);
        $stakes = [
            $this->stake(1, 10, 300, true),
            $this->stake(2, 10, 500, false),
            new Stake(3, 1, 11, 22, 400, 'Carol', 'Red', false, true, true),
        ];

        $financials = $this->service->settle($bet, $stakes, 10);

        self::assertSame(300, $financials->pot);
        self::assertSame(30, $financials->bookmakerShare);
        self::assertSame(270, $financials->redistributed);
        self::assertSame([1 => 270], $financials->payoutsByStakeId);
    }

    public function testWithoutWinnerNothingIsRedistributedAndTheWholePoolIsKept(): void
    {
        $bet = $this->bet(1000);
        $stakes = [$this->stake(1, 11, 700, true)];

        $financials = $this->service->settle($bet, $stakes, 10);

        self::assertSame(700, $financials->pot);
        self::assertSame(70, $financials->bookmakerShare);
        self::assertSame(0, $financials->redistributed);
        self::assertSame(700, $financials->bookmakerResult);
        self::assertSame($financials->pot, $financials->bookmakerResult);
        self::assertSame([], $financials->payoutsByStakeId);
        self::assertNull($financials->oddsByOptionId[10]);
    }

    public function testTheRedistributedPoolIsFullySplitBetweenTheWinners(): void
    {
        $bet = $this->bet(1000);
        $stakes = [
            $this->stake(1, 10, 333, true),
            $this->stake(2, 10, 333, true),
            $this->stake(3, 10, 334, true),
        ];

        $financials = $this->service->settle($bet, $stakes, 10);

        self::assertSame($financials->redistributed, array_sum($financials->payoutsByStakeId));
        self::assertSame([1, 2, 3], array_keys($financials->payoutsByStakeId));
    }

    public function testWithoutCommissionTheWholePoolIsRedistributed(): void
    {
        $bet = $this->bet(0);
        $stakes = [$this->stake(1, 10, 500, true), $this->stake(2, 11, 500, true)];

        $financials = $this->service->settle($bet, $stakes, 10);

        self::assertSame(0, $financials->bookmakerShare);
        self::assertSame(1000, $financials->redistributed);
        self::assertSame(0, $financials->bookmakerResult);
        self::assertSame([1 => 1000], $financials->payoutsByStakeId);
    }

    private function bet(int $commissionRateBps): Bet
    {
        return new Bet(1, 7, 'Winner?', null, null, BetStatus::Open, null, [
            new BetOption(10, 'Blue', 1),
            new BetOption(11, 'Red', 2),
        ], null, null, null, BettingMode::PariMutuel, OddsEvolutionMode::Fixed, $commissionRateBps);
    }

    private function stake(int $id, int $optionId, int $amount, bool $isPaid): Stake
    {
        return new Stake($id, 1, $optionId, 20 + $id, $amount, 'Alice', 'Blue', false, $isPaid);
    }
}

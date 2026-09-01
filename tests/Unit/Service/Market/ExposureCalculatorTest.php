<?php

declare(strict_types=1);

namespace Tests\Unit\Service\Market;

use App\Domain\Bet\Bet;
use App\Domain\Bet\BetOption;
use App\Domain\Bet\BetStatus;
use App\Domain\Bet\BettingMode;
use App\Domain\Bet\OddsEvolutionMode;
use App\Domain\Stake\Stake;
use App\Service\Market\ExposureCalculator;
use PHPUnit\Framework\TestCase;

final class ExposureCalculatorTest extends TestCase
{
    private ExposureCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new ExposureCalculator();
    }

    public function testPaidAndUnpaidStakesAreReportedSeparately(): void
    {
        $exposure = $this->calculator->calculate($this->bet(), [
            $this->stake(1, 10, 100, true, 2.00),
            $this->unpaidStake(2, 10, 200, 3.00),
        ]);

        $option = $exposure->option(10);

        self::assertSame(100, $option?->paidStake);
        self::assertSame(200, $option?->unpaidStake);
        self::assertSame(200, $option?->paidPayout);
        // Projected at the 2.00 currently offered, not at the 3.00 announced.
        self::assertSame(400, $option?->unpaidPayout);
        self::assertSame(300, $option?->totalStake());
        self::assertSame(600, $option?->totalPayout());
    }

    public function testContractualDebtOnlyCountsThePaidStakes(): void
    {
        $exposure = $this->calculator->calculate($this->bet(), [
            $this->stake(1, 10, 100, true, 2.00),
            $this->unpaidStake(2, 11, 300, 2.00),
        ]);

        // 200 is owed for real; the 600 of the unpaid stake is only a projection.
        self::assertSame(200, $exposure->contractualPayout());
        self::assertSame(600, $exposure->indicativePayout());
    }

    public function testTheIndicativeProjectionFollowsTheOfferedOdds(): void
    {
        $stakes = [$this->unpaidStake(1, 10, 100, 4.00)];

        $atFour = $this->calculator->calculate($this->bet(4.00), $stakes);
        $atOnePointOne = $this->calculator->calculate($this->bet(1.10), $stakes);

        // Nothing is contracted, so the projection moves with the market.
        self::assertSame(0, $atFour->contractualPayout());
        self::assertSame(400, $atFour->indicativePayout());
        self::assertSame(110, $atOnePointOne->indicativePayout());
    }

    public function testAnUnpaidStakeOnAnUnpricedOptionIsOnlyWorthItsOwnAmount(): void
    {
        $exposure = $this->calculator->calculate($this->bet(null), [$this->unpaidStake(1, 10, 250, 2.00)]);

        self::assertSame(250, $exposure->indicativePayout());
    }

    public function testPayoutsComeFromTheOddsFrozenOnEachStake(): void
    {
        // The option is now priced 1.10 but the stake was taken at 4.00: the
        // bookmaker owes the contract, not the current price.
        $exposure = $this->calculator->calculate($this->bet(1.10), [$this->stake(1, 10, 100, true, 4.00)]);

        self::assertSame(400, $exposure->option(10)?->paidPayout);
    }

    public function testCancelledStakesCarryNoExposure(): void
    {
        $cancelled = new Stake(1, 1, 10, 21, 500, 'Alice', 'Blue', false, true, true, null, 2.00);

        $exposure = $this->calculator->calculate($this->bet(), [$cancelled]);

        self::assertSame(0, $exposure->totalStake());
        self::assertSame(0, $exposure->option(10)?->totalPayout());
    }

    public function testAStakeWithoutFrozenOddsIsOnlyWorthItsOwnAmount(): void
    {
        $exposure = $this->calculator->calculate($this->bet(), [$this->stake(1, 10, 250, true)]);

        self::assertSame(250, $exposure->option(10)?->paidPayout);
    }

    public function testResultOfAnOptionComparesWhatIsCollectedToWhatIsOwed(): void
    {
        $exposure = $this->calculator->calculate($this->bet(), [
            $this->stake(1, 10, 300, true, 2.00),
            $this->stake(2, 11, 700, true, 2.00),
        ]);

        // 1000 collected, 600 owed if Blue wins, 1400 owed if Red wins.
        self::assertSame(400, $exposure->paidResult(10));
        self::assertSame(-400, $exposure->paidResult(11));
        self::assertSame(-400, $exposure->worstPaidResult());
    }

    public function testThePotentialResultAlsoCountsTheStakesStillPending(): void
    {
        $exposure = $this->calculator->calculate($this->bet(), [
            $this->stake(1, 10, 300, true, 2.00),
            $this->unpaidStake(2, 11, 700, 2.00),
        ]);

        // Only 300 is collected today, but 1000 will be once everything is paid.
        self::assertSame(-300, $exposure->paidResult(10));
        self::assertSame(400, $exposure->potentialResult(10));
        self::assertSame(-400, $exposure->worstPotentialResult());
    }

    public function testABetWithoutStakeCarriesNoExposureAtAll(): void
    {
        $exposure = $this->calculator->calculate($this->bet(), []);

        self::assertSame(0, $exposure->paidStake());
        self::assertSame(0, $exposure->unpaidStake());
        self::assertSame(0, $exposure->worstPaidResult());
        self::assertSame(0, $exposure->worstPotentialResult());
    }

    public function testEachUnpaidStakeIsProjectedAtItsOwnPaymentOdds(): void
    {
        // Two unpaid stakes on the same option, each quoted its own payment odds:
        // no stake is ever valued at a price it degraded itself.
        $exposure = $this->calculator->calculate(
            $this->bet(),
            [$this->unpaidStake(1, 10, 100, 2.50), $this->unpaidStake(2, 10, 200, 2.50)],
            [1 => 2.50, 2 => 2.40],
        );

        // 100 x 2.50 + 200 x 2.40 = 730, not 300 x the public 2.00.
        self::assertSame(730, $exposure->option(10)?->unpaidPayout);
        self::assertSame(730, $exposure->indicativePayout());
    }

    public function testAnUnpaidStakeWithoutPaymentOddsFallsBackOnThePublicPrice(): void
    {
        $exposure = $this->calculator->calculate($this->bet(), [$this->unpaidStake(1, 10, 100, 2.50)], []);

        // Nothing quoted for that stake: the offered odds are used instead.
        self::assertSame(200, $exposure->option(10)?->unpaidPayout);
    }

    public function testAnUnpaidStakeQuotedOnAnUnpricedOptionIsOnlyWorthItsAmount(): void
    {
        $exposure = $this->calculator->calculate(
            $this->bet(),
            [$this->unpaidStake(1, 10, 250, 2.50)],
            [1 => null],
        );

        self::assertSame(250, $exposure->option(10)?->unpaidPayout);
    }

    public function testPaymentOddsNeverTouchTheContractualExposure(): void
    {
        $exposure = $this->calculator->calculate(
            $this->bet(),
            [$this->stake(1, 10, 100, true, 3.00)],
            // A paid stake owes its frozen odds, whatever is quoted elsewhere.
            [1 => 1.10],
        );

        self::assertSame(300, $exposure->option(10)?->paidPayout);
        self::assertSame(300, $exposure->contractualPayout());
        self::assertSame(0, $exposure->indicativePayout());
    }

    private function bet(?float $odds = 2.00): Bet
    {
        return new Bet(1, 7, 'Winner?', null, null, BetStatus::Open, null, [
            new BetOption(10, 'Blue', 1, $odds, $odds),
            new BetOption(11, 'Red', 2, $odds, $odds),
        ], null, null, null, BettingMode::FixedOdds, OddsEvolutionMode::Fixed);
    }

    private function stake(int $id, int $optionId, int $amount, bool $isPaid, ?float $oddsAtBet = null): Stake
    {
        return new Stake($id, 1, $optionId, 20 + $id, $amount, 'Alice', 'Blue', false, $isPaid, false, null, $oddsAtBet);
    }

    /** An unpaid stake only carries the odds announced to the bettor. */
    private function unpaidStake(int $id, int $optionId, int $amount, ?float $quotedOdds = null): Stake
    {
        return new Stake($id, 1, $optionId, 20 + $id, $amount, 'Alice', 'Blue', false, false, false, null, null, null, $quotedOdds);
    }
}

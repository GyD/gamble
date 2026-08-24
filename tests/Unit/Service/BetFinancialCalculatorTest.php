<?php

declare(strict_types=1);

namespace Tests\Unit\Service;

use App\Domain\Stake\Stake;
use App\Service\BetFinancialCalculator;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class BetFinancialCalculatorTest extends TestCase
{
    private BetFinancialCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new BetFinancialCalculator();
    }

    public function testItAppliesCommissionAndLargestRemainderDeterministically(): void
    {
        $result = $this->calculator->calculate([10, 20], [
            $this->stake(2, 10, 100),
            $this->stake(1, 10, 100),
            $this->stake(3, 20, 101),
        ], 1000, 10);

        self::assertSame(301, $result->potCents);
        self::assertSame(30, $result->bookmakerShareCents);
        self::assertSame(271, $result->redistributedCents);
        self::assertSame([1 => 136, 2 => 135], $result->payoutsByStakeId);
    }

    public function testCommissionIsCappedAtLosingStakesAndOddsNeverDropBelowOne(): void
    {
        $result = $this->calculator->calculate([10, 20], [
            $this->stake(1, 10, 990),
            $this->stake(2, 20, 10),
        ], 2500, 10);

        self::assertSame(10, $result->bookmakerShareCents);
        self::assertSame(990, $result->redistributedCents);
        self::assertSame(1.0, $result->oddsByOptionId[10]);
    }

    public function testCancelledStakesAreIgnored(): void
    {
        $result = $this->calculator->calculate([10, 20], [
            $this->stake(1, 10, 100),
            $this->stake(2, 20, 900, true),
        ], 1000, 10);

        self::assertSame(100, $result->potCents);
        self::assertSame(0, $result->bookmakerShareCents);
        self::assertSame([1 => 100], $result->payoutsByStakeId);
    }

    public function testUnpaidStakesAreIgnoredFromPotOddsAndPayouts(): void
    {
        $result = $this->calculator->calculate([10, 20], [
            $this->stake(1, 10, 100),
            $this->stake(2, 20, 100),
            $this->stake(3, 20, 800, false, false),
            $this->stake(4, 10, 400, false, false),
        ], 1000, 10);

        self::assertSame(200, $result->potCents);
        self::assertSame(20, $result->bookmakerShareCents);
        self::assertSame(180, $result->redistributedCents);
        self::assertSame([10 => 1.8, 20 => 1.8], $result->oddsByOptionId);
        self::assertSame([1 => 180], $result->payoutsByStakeId);
    }

    public function testUnpaidStakesCanBeIncludedInIndicativeOddsButNotInPot(): void
    {
        $result = $this->calculator->calculate([10, 20], [
            $this->stake(1, 10, 100),
            $this->stake(2, 20, 100),
            $this->stake(3, 20, 800, false, false),
            $this->stake(4, 10, 400, true, false),
        ], 1000, includeUnpaidInOdds: true);

        self::assertSame(200, $result->potCents);
        self::assertSame([10 => 9.0, 20 => 1.0], $result->oddsByOptionId);
        self::assertSame([], $result->payoutsByStakeId);
    }

    public function testNoWinnerLeavesTheEntirePotToBookmaker(): void
    {
        $result = $this->calculator->calculate([10, 20], [$this->stake(1, 10, 500)], 1000, 20);

        self::assertSame(500, $result->bookmakerShareCents);
        self::assertSame(0, $result->redistributedCents);
        self::assertSame([], $result->payoutsByStakeId);
    }

    public function testItRejectsRateAboveTwentyFivePercent(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->calculator->calculate([10], [], 2501);
    }

    private function stake(int $id, int $optionId, int $amount, bool $cancelled = false, bool $paid = true): Stake
    {
        return new Stake($id, 1, $optionId, $id, $amount, 'Contact', 'Option', false, $paid, $cancelled);
    }
}
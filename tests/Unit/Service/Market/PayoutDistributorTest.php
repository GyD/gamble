<?php

declare(strict_types=1);

namespace Tests\Unit\Service\Market;

use App\Domain\Stake\Stake;
use App\Service\Market\PayoutDistributor;
use PHPUnit\Framework\TestCase;

final class PayoutDistributorTest extends TestCase
{
    private PayoutDistributor $distributor;

    protected function setUp(): void
    {
        $this->distributor = new PayoutDistributor();
    }

    public function testPayoutIsSplitProportionallyToTheStakes(): void
    {
        $payouts = $this->distributor->distribute([$this->stake(1, 100), $this->stake(2, 300)], 400, 800);

        self::assertSame([1 => 200, 2 => 600], $payouts);
    }

    public function testRemainingUnitsGoToTheLargestRemaindersFirst(): void
    {
        $stakes = [$this->stake(1, 1), $this->stake(2, 1), $this->stake(3, 1)];

        $payouts = $this->distributor->distribute($stakes, 3, 10);

        self::assertSame(10, array_sum($payouts));
        self::assertSame([1 => 4, 2 => 3, 3 => 3], $payouts);
    }

    public function testDistributionIsDeterministicAndKeyedByStakeId(): void
    {
        $stakes = [$this->stake(7, 333), $this->stake(3, 333), $this->stake(5, 334)];

        $payouts = $this->distributor->distribute($stakes, 1000, 1001);

        self::assertSame([3, 5, 7], array_keys($payouts));
        self::assertSame(1001, array_sum($payouts));
        self::assertSame($payouts, $this->distributor->distribute($stakes, 1000, 1001));
    }

    public function testNothingIsDistributedWithoutStakes(): void
    {
        self::assertSame([], $this->distributor->distribute([], 0, 900));
        self::assertSame([], $this->distributor->distribute([$this->stake(1, 100)], 0, 900));
    }

    public function testEveryStakeGetsNothingWhenThereIsNothingToDistribute(): void
    {
        $payouts = $this->distributor->distribute([$this->stake(1, 100), $this->stake(2, 300)], 400, 0);

        self::assertSame([1 => 0, 2 => 0], $payouts);
    }

    private function stake(int $id, int $amount): Stake
    {
        return new Stake($id, 1, 10, 20, $amount, 'Alice', 'Blue', false, true);
    }
}

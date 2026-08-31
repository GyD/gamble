<?php

declare(strict_types=1);

namespace Tests\Unit\Service\Market;

use App\Service\Market\OddsDrift;
use PHPUnit\Framework\TestCase;

final class OddsDriftTest extends TestCase
{
    private OddsDrift $drift;

    protected function setUp(): void
    {
        $this->drift = new OddsDrift();
    }

    public function testWithoutBoundThePricedOddsArePublishedUntouched(): void
    {
        $offered = $this->drift->apply([10 => 2.00, 11 => 2.00], [10 => 1000.0, 11 => 0.0], 0.0, 1.01);

        self::assertSame([10 => 2.00, 11 => 2.00], $offered);
    }

    public function testWithoutExposureThePricedOddsArePublishedUntouched(): void
    {
        $offered = $this->drift->apply([10 => 2.00, 11 => 2.00], [10 => 0.0, 11 => 0.0], 0.12, 1.01);

        self::assertSame([10 => 2.00, 11 => 2.00], $offered);
    }

    public function testTheOverExposedOptionShortensAndTheOtherLengthens(): void
    {
        $offered = $this->drift->apply([10 => 2.00, 11 => 2.00], [10 => 900.0, 11 => 100.0], 0.12, 1.01);

        self::assertLessThan(2.00, $offered[10]);
        self::assertGreaterThan(2.00, $offered[11]);
    }

    public function testAnExposurePerfectlyMatchingThePricesLeavesThemUntouched(): void
    {
        // Odds 1.25 and 5.00 imply an 80/20 book: an exposure split the same way
        // is already balanced.
        $offered = $this->drift->apply([10 => 1.25, 11 => 5.00], [10 => 800.0, 11 => 200.0], 0.12, 1.01);

        self::assertSame([10 => 1.25, 11 => 5.00], $offered);
    }

    public function testTheDriftNeverExceedsTheBoundInEitherDirection(): void
    {
        $bound = 0.25;

        $offered = $this->drift->apply([10 => 2.00, 11 => 2.00], [10 => 1000.0, 11 => 0.0], $bound, 1.01);

        self::assertSame(2.00 * (1.0 - $bound), $offered[10]);
        self::assertSame(2.00 * (1.0 + $bound), $offered[11]);
    }

    public function testAPartiallyPricedBookIsPublishedAsPriced(): void
    {
        $offered = $this->drift->apply([10 => 2.00, 11 => null], [10 => 1000.0, 11 => 0.0], 0.12, 1.01);

        self::assertSame(2.00, $offered[10]);
        self::assertNull($offered[11]);
    }

    public function testOfferedOddsNeverFallBelowTheMinimum(): void
    {
        $offered = $this->drift->apply([10 => 1.02, 11 => 50.00], [10 => 1000.0, 11 => 0.0], 0.25, 1.01);

        self::assertSame(1.01, $offered[10]);
    }

    public function testOfferedOddsArePublishedWithTwoDecimals(): void
    {
        $offered = $this->drift->apply([10 => 3.33, 11 => 1.43], [10 => 700.0, 11 => 300.0], 0.12, 1.01);

        foreach ($offered as $odds) {
            self::assertSame(round((float) $odds, 2), $odds);
        }
    }
}

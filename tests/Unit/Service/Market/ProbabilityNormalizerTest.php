<?php

declare(strict_types=1);

namespace Tests\Unit\Service\Market;

use App\Service\Market\MarketSettings;
use App\Service\Market\ProbabilityNormalizer;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ProbabilityNormalizerTest extends TestCase
{
    private ProbabilityNormalizer $normalizer;

    protected function setUp(): void
    {
        $this->normalizer = new ProbabilityNormalizer(new MarketSettings());
    }

    public function testEquiprobableSpreadsTheProbabilityEqually(): void
    {
        $probabilities = $this->normalizer->equiprobable([10, 11, 12, 13]);

        self::assertSame([10, 11, 12, 13], array_keys($probabilities));
        foreach ($probabilities as $probability) {
            self::assertEqualsWithDelta(0.25, $probability, 0.000_001);
        }
    }

    public function testEquiprobableRequiresAtLeastOneOption(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('At least one option is required.');

        $this->normalizer->equiprobable([]);
    }

    public function testNormalizeRescalesToOneHundredPercent(): void
    {
        $probabilities = $this->normalizer->normalize([10 => 30.0, 11 => 10.0]);

        self::assertEqualsWithDelta(0.75, $probabilities[10], 0.000_001);
        self::assertEqualsWithDelta(0.25, $probabilities[11], 0.000_001);
        self::assertEqualsWithDelta(1.0, array_sum($probabilities), 0.000_001);
    }

    public function testNormalizeKeepsEveryProbabilityWithinTheConfiguredBounds(): void
    {
        $settings = new MarketSettings();
        $probabilities = $this->normalizer->normalize([10 => 0.999, 11 => 0.001]);

        self::assertGreaterThanOrEqual($settings->minimumProbability - 0.000_001, $probabilities[11]);
        self::assertLessThanOrEqual($settings->maximumProbability + 0.000_001, $probabilities[10]);
        self::assertEqualsWithDelta(1.0, array_sum($probabilities), 0.000_001);
    }

    public function testNormalizeFallsBackToEquiprobableWhenNothingIsPositive(): void
    {
        $probabilities = $this->normalizer->normalize([10 => 0.0, 11 => 0.0]);

        self::assertEqualsWithDelta(0.5, $probabilities[10], 0.000_001);
        self::assertEqualsWithDelta(0.5, $probabilities[11], 0.000_001);
    }

    public function testNormalizeRequiresAtLeastOneProbability(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('At least one probability is required.');

        $this->normalizer->normalize([]);
    }

    public function testBlendKeepsTheInitialProbabilitiesWithoutMarketWeight(): void
    {
        $blended = $this->normalizer->blend([10 => 0.7, 11 => 0.3], [10 => 0.2, 11 => 0.8], 0.0);

        self::assertEqualsWithDelta(0.7, $blended[10], 0.000_001);
        self::assertEqualsWithDelta(0.3, $blended[11], 0.000_001);
    }

    public function testBlendMovesTowardsTheMarketProportionallyToItsWeight(): void
    {
        $blended = $this->normalizer->blend([10 => 0.8, 11 => 0.2], [10 => 0.4, 11 => 0.6], 0.5);

        self::assertEqualsWithDelta(0.6, $blended[10], 0.000_001);
        self::assertEqualsWithDelta(0.4, $blended[11], 0.000_001);
    }

    public function testBlendLimitsTheVariationFromThePreviousProbabilities(): void
    {
        $settings = new MarketSettings();
        $previous = [10 => 0.50, 11 => 0.50];

        $blended = $this->normalizer->blend([10 => 0.50, 11 => 0.50], [10 => 0.95, 11 => 0.05], 1.0, $previous);

        self::assertEqualsWithDelta(
            $previous[10] + $settings->maxProbabilityChangePerRecalculation,
            $blended[10],
            0.000_001,
        );
        self::assertEqualsWithDelta(1.0, array_sum($blended), 0.000_001);
    }

    public function testBlendRequiresInitialProbabilities(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('At least one probability is required.');

        $this->normalizer->blend([], [10 => 1.0], 0.5);
    }
}

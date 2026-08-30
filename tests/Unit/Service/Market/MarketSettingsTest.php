<?php

declare(strict_types=1);

namespace Tests\Unit\Service\Market;

use App\Domain\Bet\OddsEvolutionMode;
use App\Service\Market\MarketSettings;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MarketSettingsTest extends TestCase
{
    public function testDefaultsMatchTheDocumentedMarketParameters(): void
    {
        $settings = new MarketSettings();

        self::assertSame(0.50, $settings->unpaidBetMarketWeight);
        self::assertSame(500, $settings->liquidityReference);
        self::assertSame(0.02, $settings->minimumProbability);
        self::assertSame(0.98, $settings->maximumProbability);
        self::assertSame(0.05, $settings->maxProbabilityChangePerRecalculation);
    }

    public function testSettingsAreReadFromTheConfigurationArray(): void
    {
        $settings = MarketSettings::fromArray([
            'unpaid_bet_market_weight' => 0.25,
            'liquidity_reference' => 1000,
            'minimum_probability' => 0.05,
            'maximum_probability' => 0.95,
            'max_probability_change_per_recalculation' => 0.10,
            'max_market_weight' => [
                'fixed' => 0.0,
                'dynamic_low' => 0.10,
                'dynamic_normal' => 0.30,
                'dynamic_high' => 0.50,
            ],
        ]);

        self::assertSame(0.25, $settings->unpaidBetMarketWeight);
        self::assertSame(1000, $settings->liquidityReference);
        self::assertSame(0.30, $settings->maxMarketWeight(OddsEvolutionMode::DynamicNormal));
    }

    public function testMissingSettingsFallBackToTheDefaults(): void
    {
        $settings = MarketSettings::fromArray([]);

        self::assertEquals(new MarketSettings(), $settings);
    }

    /** @return list<array{array<string, mixed>, string}> */
    public static function invalidSettings(): array
    {
        return [
            [['unpaid_bet_market_weight' => 1.5], 'Unpaid bet market weight must be between 0 and 1.'],
            [['liquidity_reference' => 0], 'Liquidity reference must be a positive amount.'],
            [['minimum_probability' => 0.0], 'Minimum probability must be greater than 0 and below the maximum.'],
            [['minimum_probability' => 0.99], 'Minimum probability must be greater than 0 and below the maximum.'],
            [['maximum_probability' => 1.0], 'Maximum probability must be below 1.'],
            [['max_probability_change_per_recalculation' => 0.0], 'Maximum probability change must be positive.'],
        ];
    }

    /** @param array<string, mixed> $settings */
    #[DataProvider('invalidSettings')]
    public function testInvalidSettingsAreRejected(array $settings, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        MarketSettings::fromArray($settings);
    }

    public function testFixedEvolutionNeverGivesAnyWeightToTheMarket(): void
    {
        $settings = new MarketSettings();

        self::assertSame(0.0, $settings->maxMarketWeight(OddsEvolutionMode::Fixed));
        self::assertSame(0.0, $settings->marketWeight(OddsEvolutionMode::Fixed, 10_000.0));
    }

    public function testMarketWeightGrowsWithTheTradedVolume(): void
    {
        $settings = new MarketSettings();
        $mode = OddsEvolutionMode::DynamicNormal;

        $thin = $settings->marketWeight($mode, 100.0);
        $liquid = $settings->marketWeight($mode, 5_000.0);

        self::assertSame(0.0, $settings->marketWeight($mode, 0.0));
        self::assertGreaterThan($thin, $liquid);
        self::assertLessThan($settings->maxMarketWeight($mode), $liquid);
        // At the liquidity reference exactly half of the maximum weight applies.
        self::assertEqualsWithDelta(
            $settings->maxMarketWeight($mode) / 2,
            $settings->marketWeight($mode, (float) $settings->liquidityReference),
            0.000_001,
        );
    }
}

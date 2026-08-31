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
        self::assertSame(1.01, $settings->minimumOdds);
        self::assertSame(0.12, $settings->maxOddsDrift(OddsEvolutionMode::DynamicNormal));
    }

    public function testSettingsAreReadFromTheConfigurationArray(): void
    {
        $settings = MarketSettings::fromArray([
            'unpaid_bet_market_weight' => 0.25,
            'liquidity_reference' => 1000,
            'minimum_odds' => 1.05,
            'max_odds_drift_bps' => [
                'fixed' => 0,
                'dynamic_low' => 300,
                'dynamic_normal' => 800,
                'dynamic_high' => 2000,
            ],
        ]);

        self::assertSame(0.25, $settings->unpaidBetMarketWeight);
        self::assertSame(1000, $settings->liquidityReference);
        self::assertSame(1.05, $settings->minimumOdds);
        self::assertSame(0.08, $settings->maxOddsDrift(OddsEvolutionMode::DynamicNormal));
    }

    public function testMissingSettingsFallBackToTheDefaults(): void
    {
        self::assertEquals(new MarketSettings(), MarketSettings::fromArray([]));
    }

    /** @return list<array{array<string, mixed>, string}> */
    public static function invalidSettings(): array
    {
        return [
            [['unpaid_bet_market_weight' => 1.5], 'Unpaid bet market weight must be between 0 and 1.'],
            [['liquidity_reference' => 0], 'Liquidity reference must be a positive amount.'],
            [['minimum_odds' => 0.9], 'Minimum odds must be at least 1.'],
            [['max_odds_drift_bps' => ['dynamic_high' => 3000]], 'Maximum odds drift must be between 0 and 25%.'],
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

    public function testNoModeMayEverExceedTheAbsoluteDriftCeiling(): void
    {
        $settings = new MarketSettings();

        foreach (OddsEvolutionMode::cases() as $mode) {
            self::assertLessThanOrEqual(
                MarketSettings::ABSOLUTE_MAX_ODDS_DRIFT_BPS / 10_000,
                $settings->maxOddsDrift($mode),
            );
        }
    }

    public function testFixedEvolutionNeverDriftsThePricedOdds(): void
    {
        $settings = new MarketSettings();

        self::assertSame(0.0, $settings->maxOddsDrift(OddsEvolutionMode::Fixed));
        self::assertSame(0.0, $settings->oddsDrift(OddsEvolutionMode::Fixed, 10_000.0));
    }

    public function testDriftGrowsWithTheTradedVolume(): void
    {
        $settings = new MarketSettings();
        $mode = OddsEvolutionMode::DynamicNormal;

        $thin = $settings->oddsDrift($mode, 100.0);
        $liquid = $settings->oddsDrift($mode, 5_000.0);

        self::assertSame(0.0, $settings->oddsDrift($mode, 0.0));
        self::assertGreaterThan($thin, $liquid);
        self::assertLessThan($settings->maxOddsDrift($mode), $liquid);
        // At the liquidity reference exactly half of the maximum drift applies.
        self::assertEqualsWithDelta(
            $settings->maxOddsDrift($mode) / 2,
            $settings->oddsDrift($mode, (float) $settings->liquidityReference),
            0.000_001,
        );
    }
}

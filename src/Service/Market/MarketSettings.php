<?php

declare(strict_types=1);

namespace App\Service\Market;

use App\Domain\Bet\OddsEvolutionMode;
use InvalidArgumentException;

/**
 * Centralised market parameters.
 *
 * These values are configured once in config/settings.php and must never be
 * duplicated or hardcoded inside the market services.
 */
final readonly class MarketSettings
{
    /** No evolution mode may ever move the priced odds by more than a quarter. */
    public const ABSOLUTE_MAX_ODDS_DRIFT_BPS = 2500;

    /** @param array<string, int> $maxOddsDriftBps */
    public function __construct(
        public float $unpaidBetMarketWeight = 0.50,
        public int $liquidityReference = 500,
        public float $minimumOdds = 1.01,
        private array $maxOddsDriftBps = [
            'fixed' => 0,
            'dynamic_low' => 500,
            'dynamic_normal' => 1200,
            'dynamic_high' => 2500,
        ],
    ) {
        if ($this->unpaidBetMarketWeight < 0.0 || $this->unpaidBetMarketWeight > 1.0) {
            throw new InvalidArgumentException('Unpaid bet market weight must be between 0 and 1.');
        }
        if ($this->liquidityReference < 1) {
            throw new InvalidArgumentException('Liquidity reference must be a positive amount.');
        }
        if ($this->minimumOdds < 1.0) {
            throw new InvalidArgumentException('Minimum odds must be at least 1.');
        }
        foreach ($this->maxOddsDriftBps as $driftBps) {
            if ($driftBps < 0 || $driftBps > self::ABSOLUTE_MAX_ODDS_DRIFT_BPS) {
                throw new InvalidArgumentException('Maximum odds drift must be between 0 and 25%.');
            }
        }
    }

    /** @param array<string, mixed> $settings */
    public static function fromArray(array $settings): self
    {
        $defaults = new self();
        /** @var array<string, int> $drifts */
        $drifts = isset($settings['max_odds_drift_bps']) && is_array($settings['max_odds_drift_bps'])
            ? array_map(static fn(mixed $drift): int => (int) $drift, $settings['max_odds_drift_bps'])
            : $defaults->maxOddsDriftBps;

        return new self(
            (float) ($settings['unpaid_bet_market_weight'] ?? $defaults->unpaidBetMarketWeight),
            (int) ($settings['liquidity_reference'] ?? $defaults->liquidityReference),
            (float) ($settings['minimum_odds'] ?? $defaults->minimumOdds),
            $drifts,
        );
    }

    /** Widest drift the evolution mode allows, as a ratio of the priced odds. */
    public function maxOddsDrift(OddsEvolutionMode $mode): float
    {
        return (float) ($this->maxOddsDriftBps[$mode->value] ?? 0) / 10_000;
    }

    /**
     * Share of the maximum drift unlocked by the traded volume.
     *
     * It reaches half at the liquidity reference and approaches one on a deep
     * market, so a thin market barely moves the priced odds.
     */
    public function volumeFactor(float $effectiveVolume): float
    {
        if ($effectiveVolume <= 0.0) {
            return 0.0;
        }

        return $effectiveVolume / ($effectiveVolume + (float) $this->liquidityReference);
    }

    /** Drift actually applicable to the priced odds, as a ratio. */
    public function oddsDrift(OddsEvolutionMode $mode, float $effectiveVolume): float
    {
        return $this->maxOddsDrift($mode) * $this->volumeFactor($effectiveVolume);
    }
}

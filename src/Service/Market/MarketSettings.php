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
    /** @param array<string, float> $maxMarketWeights */
    public function __construct(
        public float $unpaidBetMarketWeight = 0.50,
        public int $liquidityReference = 500,
        public float $minimumProbability = 0.02,
        public float $maximumProbability = 0.98,
        public float $maxProbabilityChangePerRecalculation = 0.05,
        private array $maxMarketWeights = [
            'fixed' => 0.0,
            'dynamic_low' => 0.20,
            'dynamic_normal' => 0.40,
            'dynamic_high' => 0.65,
        ],
    ) {
        if ($this->unpaidBetMarketWeight < 0.0 || $this->unpaidBetMarketWeight > 1.0) {
            throw new InvalidArgumentException('Unpaid bet market weight must be between 0 and 1.');
        }
        if ($this->liquidityReference < 1) {
            throw new InvalidArgumentException('Liquidity reference must be a positive amount.');
        }
        if ($this->minimumProbability <= 0.0 || $this->minimumProbability >= $this->maximumProbability) {
            throw new InvalidArgumentException('Minimum probability must be greater than 0 and below the maximum.');
        }
        if ($this->maximumProbability >= 1.0) {
            throw new InvalidArgumentException('Maximum probability must be below 1.');
        }
        if ($this->maxProbabilityChangePerRecalculation <= 0.0) {
            throw new InvalidArgumentException('Maximum probability change must be positive.');
        }
    }

    /** @param array<string, mixed> $settings */
    public static function fromArray(array $settings): self
    {
        $defaults = new self();
        /** @var array<string, float> $weights */
        $weights = isset($settings['max_market_weight']) && is_array($settings['max_market_weight'])
            ? array_map(static fn(mixed $weight): float => (float) $weight, $settings['max_market_weight'])
            : $defaults->maxMarketWeights;

        return new self(
            (float) ($settings['unpaid_bet_market_weight'] ?? $defaults->unpaidBetMarketWeight),
            (int) ($settings['liquidity_reference'] ?? $defaults->liquidityReference),
            (float) ($settings['minimum_probability'] ?? $defaults->minimumProbability),
            (float) ($settings['maximum_probability'] ?? $defaults->maximumProbability),
            (float) ($settings['max_probability_change_per_recalculation'] ?? $defaults->maxProbabilityChangePerRecalculation),
            $weights,
        );
    }

    public function maxMarketWeight(OddsEvolutionMode $mode): float
    {
        return (float) ($this->maxMarketWeights[$mode->value] ?? 0.0);
    }

    /**
     * Share of the market probability blended into the current probability.
     *
     * The weight grows with the traded volume so a thin market stays close to
     * the initial probabilities.
     */
    public function marketWeight(OddsEvolutionMode $mode, float $totalEffectiveStake): float
    {
        $maximum = $this->maxMarketWeight($mode);
        if ($maximum <= 0.0 || $totalEffectiveStake <= 0.0) {
            return 0.0;
        }
        $volumeFactor = $totalEffectiveStake / ($totalEffectiveStake + (float) $this->liquidityReference);

        return $maximum * $volumeFactor;
    }
}

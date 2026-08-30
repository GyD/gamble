<?php

declare(strict_types=1);

namespace App\Domain\Bet;

use InvalidArgumentException;

final readonly class MarketConfiguration
{
    /** @param array<string, float> $maxMarketWeights */
    public function __construct(
        public float $liquidityReference,
        public float $unpaidBetMarketWeight,
        public float $minimumProbability,
        public float $maximumProbability,
        public float $maxProbabilityChangePerRecalculation,
        public array $maxMarketWeights,
    ) {
        if ($liquidityReference <= 0
            || $unpaidBetMarketWeight < 0 || $unpaidBetMarketWeight > 1
            || $minimumProbability <= 0 || $maximumProbability >= 1
            || $minimumProbability >= $maximumProbability
            || $maxProbabilityChangePerRecalculation <= 0) {
            throw new InvalidArgumentException('Invalid betting market configuration.');
        }
    }

    public function maxMarketWeight(OddsEvolutionMode $mode): float
    {
        return $mode === OddsEvolutionMode::Fixed ? 0.0 : ($this->maxMarketWeights[$mode->value] ?? 0.0);
    }
}
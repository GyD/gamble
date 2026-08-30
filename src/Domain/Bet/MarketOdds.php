<?php

declare(strict_types=1);

namespace App\Domain\Bet;

final readonly class MarketOdds
{
    /** @param array<int, float> $probabilitiesByOptionId @param array<int, float|null> $oddsByOptionId */
    public function __construct(
        public array $probabilitiesByOptionId,
        public array $oddsByOptionId,
        public float $effectivePool,
    ) {
    }
}
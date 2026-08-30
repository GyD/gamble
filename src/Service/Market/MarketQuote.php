<?php

declare(strict_types=1);

namespace App\Service\Market;

/**
 * Indicative state of a market: the odds offered to the next stakes and the
 * probabilities they are derived from.
 *
 * Nothing here is contractual: only `Stake::$oddsAtBet` and the settlement are.
 */
final readonly class MarketQuote
{
    /**
     * @param array<int, float|null> $oddsByOptionId
     * @param array<int, float> $probabilitiesByOptionId
     */
    public function __construct(
        public array $oddsByOptionId,
        public array $probabilitiesByOptionId = [],
        public float $effectivePool = 0.0,
    ) {
    }

    public function odds(int $optionId): ?float
    {
        return $this->oddsByOptionId[$optionId] ?? null;
    }
}

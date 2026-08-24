<?php

declare(strict_types=1);

namespace App\Domain\Bet;

final readonly class BetFinancials
{
    /**
     * @param array<int, float|null> $oddsByOptionId
     * @param array<int, int> $payoutsByStakeId
     */
    public function __construct(
        public int $potCents,
        public int $bookmakerShareCents,
        public int $redistributedCents,
        public array $oddsByOptionId,
        public array $payoutsByStakeId = [],
    ) {
    }
}
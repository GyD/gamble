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
        public int   $pot,
        public int   $bookmakerShare,
        public int   $redistributed,
        public array $oddsByOptionId,
        public array $payoutsByStakeId = [],
    ) {
    }
}
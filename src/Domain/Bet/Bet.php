<?php

declare(strict_types=1);

namespace App\Domain\Bet;

use DateTimeImmutable;

final readonly class Bet
{
    /** @param list<BetOption> $options */
    public function __construct(
        public int                $id,
        public int                $ownerUserId,
        public string             $question,
        public ?string            $description,
        public ?DateTimeImmutable $closesAt,
        public BetStatus          $status,
        public ?int               $winningOptionId,
        public array              $options,
        public int                $bookmakerRateBps = 1000,
        public ?int               $finalPot = null,
        public ?int               $finalBookmakerShare = null,
        public ?int               $finalRedistributed = null,
    ) {
    }
}
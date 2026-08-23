<?php

declare(strict_types=1);

namespace App\Domain\Stake;

final readonly class Stake
{
    public function __construct(
        public int    $id,
        public int    $betId,
        public int    $betOptionId,
        public int    $contactId,
        public int    $amountCents,
        public string $contactName,
        public string $optionLabel,
        public bool   $contactArchived,
        public bool   $isPaid,
        public bool   $isCancelled = false,
    )
    {
    }
}
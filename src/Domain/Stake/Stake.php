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
        public int    $amount,
        public string $contactName,
        public string $optionLabel,
        public bool   $contactArchived,
        public bool   $isPaid,
        public bool   $isCancelled = false,
        public ?int   $finalPayout = null,
        public ?float $quotedOdds = null,
        public ?float $oddsAtBet = null,
    )
    {
    }

    /** A stake only takes part in the financial settlement once paid and not cancelled. */
    public function isFinanciallyEligible(): bool
    {
        return $this->isPaid && !$this->isCancelled;
    }

    /** An active stake influences the indicative market, with a reduced weight when unpaid. */
    public function marketWeight(float $unpaidWeight): float
    {
        if ($this->isCancelled) {
            return 0.0;
        }

        return $this->isPaid ? 1.0 : $unpaidWeight;
    }
}
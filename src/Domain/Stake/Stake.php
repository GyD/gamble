<?php

declare(strict_types=1);

namespace App\Domain\Stake;

use DateTimeImmutable;

final readonly class Stake
{
    /**
     * @param float|null $oddsAtBet odds captured when the stake was paid; they
     *        are the contract passed with the bettor and are never recomputed
     * @param float|null $quotedOdds odds announced to the bettor at creation;
     *        purely informative, they never take part in a settlement
     */
    public function __construct(
        public int                $id,
        public int                $betId,
        public int                $betOptionId,
        public int                $contactId,
        public int                $amount,
        public string             $contactName,
        public string             $optionLabel,
        public bool               $contactArchived,
        public bool               $isPaid,
        public bool               $isCancelled = false,
        public ?int               $finalPayout = null,
        public ?float             $oddsAtBet = null,
        public ?DateTimeImmutable $createdAt = null,
        public ?float             $quotedOdds = null,
    )
    {
    }

    /** A stake only takes part in the financial settlement once paid and not cancelled. */
    public function isFinanciallyEligible(): bool
    {
        return $this->isPaid && !$this->isCancelled;
    }

    /** An active stake influences the offered odds, with a reduced weight when unpaid. */
    public function marketWeight(float $unpaidWeight): float
    {
        if ($this->isCancelled) {
            return 0.0;
        }

        return $this->isPaid ? 1.0 : $unpaidWeight;
    }

    /**
     * Amount owed to the bettor if the stake wins.
     *
     * Only a paid stake carries a contract. A stake without contractual odds is
     * refunded rather than silently multiplied, which keeps the legacy stakes
     * migrated without odds harmless. A won stake can never be worth less than
     * its own amount.
     */
    public function potentialPayout(): int
    {
        return $this->payoutAt($this->oddsAtBet);
    }

    /** Whether the stake already signed its contractual odds. */
    public function hasContractualOdds(): bool
    {
        return $this->oddsAtBet !== null;
    }

    /**
     * Amount the stake would be worth at the given odds.
     *
     * Used to project an unpaid stake at the price currently offered: that
     * figure is an estimation, never a debt already contracted.
     */
    public function payoutAt(?float $odds): int
    {
        if ($odds === null) {
            return $this->amount;
        }

        return max($this->amount, (int) round((float) $this->amount * $odds));
    }

    /**
     * Whether the stake was placed after the given instant.
     *
     * A stake of unknown creation date is considered older than any instant, so
     * it never feeds a drift it may predate.
     */
    public function isPlacedAfter(?DateTimeImmutable $instant): bool
    {
        if ($instant === null) {
            return true;
        }

        return $this->createdAt !== null && $this->createdAt >= $instant;
    }
}

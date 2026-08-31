<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\Stake\Stake;

interface StakeStore
{
    /** @return list<Stake> */
    public function findByBet(int $betId): array;

    public function findById(int $id): ?Stake;

    /** Must be called inside a transaction, after the bet has been locked. */
    public function findByIdForUpdate(int $id): ?Stake;

    /**
     * @param float|null $oddsAtBet odds frozen on the stake, the contract passed
     *        with the bettor; they are never recomputed afterwards
     */
    public function create(int $betId, int $betOptionId, int $contactId, int $amount, ?float $oddsAtBet = null): Stake;

    public function update(int $id, int $betOptionId, int $contactId, int $amount): Stake;

    public function setPaid(int $id, bool $isPaid): Stake;

    public function setCancelled(int $id, bool $isCancelled): Stake;

    /** @param array<int, int> $payoutsByStakeId */
    public function setFinalPayouts(int $betId, array $payoutsByStakeId): void;

    /**
     * @return list<array{
     *     contact_id: int,
     *     contact_name: string,
     *     winning_stake: int,
     *     payout: int,
     *     is_winnings_paid: bool
     * }>
     */
    public function findWinnersByBet(int $betId, int $winningOptionId): array;

    public function setWinningsPaid(int $betId, int $winningOptionId, int $contactId, bool $isPaid): void;

    public function delete(int $id): void;
}
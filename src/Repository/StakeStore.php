<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\Stake\Stake;

interface StakeStore
{
    /** @return list<Stake> */
    public function findByBet(int $betId): array;

    public function findById(int $id): ?Stake;

    public function create(int $betId, int $betOptionId, int $contactId, int $amountCents): Stake;

    public function update(int $id, int $betOptionId, int $contactId, int $amountCents): Stake;

    public function setPaid(int $id, bool $isPaid): Stake;

    public function setCancelled(int $id, bool $isCancelled): Stake;

    public function delete(int $id): void;
}
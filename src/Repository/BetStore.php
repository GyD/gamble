<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\Bet\Bet;
use App\Domain\Bet\BetStatus;
use DateTimeImmutable;

interface BetStore
{
    /** @return list<Bet> */
    public function findAll(): array;

    /** @return list<Bet> */
    public function findByOwner(int $ownerUserId): array;

    public function findById(int $id): ?Bet;

    /** Must be called inside a transaction. */
    public function findByIdForUpdate(int $id): ?Bet;

    /** @param list<string> $options */
    public function create(
        int $ownerUserId,
        string $question,
        ?string $description,
        ?DateTimeImmutable $closesAt,
        array $options,
    ): Bet;

    /** @param list<string> $options */
    public function update(
        int $id,
        string $question,
        ?string $description,
        ?DateTimeImmutable $closesAt,
        array $options,
    ): Bet;

    public function changeStatus(int $id, BetStatus $status, ?int $winningOptionId): Bet;

    public function setBookmakerRate(int $id, int $rateBps): Bet;

    /** @param array<int, float|null> $oddsByOptionId */
    public function settleFinancials(int $id, int $winningOptionId, int $potCents, int $bookmakerShareCents, int $redistributedCents, array $oddsByOptionId): Bet;

    public function delete(int $id): void;
}
<?php

declare(strict_types=1);

namespace App\Repository;

use DateTimeImmutable;

interface StatisticsStore
{
    /**
     * One row represents one contact's active stakes on one settled bet.
     *
     * @return list<array{
     *     contact_id: int,
     *     contact_name: string,
     *     bet_id: int,
     *     question: string,
     *     settled_at: string,
     *     stake_count: int,
     *     total_staked_cents: int,
     *     winning_staked_cents: int,
     *     returned_cents: int,
     *     largest_stake_cents: int
     * }>
     */
    public function settledContactBets(
        ?int $ownerUserId,
        ?DateTimeImmutable $from,
        ?int $contactId = null,
    ): array;

    /**
     * @return list<array{
     *     option_id: int,
     *     option_label: string,
     *     option_position: int,
     *     stake_id: int|null,
     *     contact_id: int|null,
     *     amount_cents: int|null
     * }>
     */
    public function betStakes(int $betId): array;
}

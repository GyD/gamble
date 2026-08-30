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
     *     total_staked: int,
     *     winning_staked: int,
     *     returned: int,
     *     largest_stake: int
     * }>
     */
    public function settledContactBets(
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
     *     amount: int|null
     * }>
     */
    public function betStakes(int $betId): array;
}

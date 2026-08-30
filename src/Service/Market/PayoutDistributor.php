<?php

declare(strict_types=1);

namespace App\Service\Market;

use App\Domain\Stake\Stake;

/**
 * Splits a payout between stakes proportionally to their amount, using the
 * largest remainder method so the distribution stays deterministic and the
 * distributed total matches the payout exactly.
 */
final readonly class PayoutDistributor
{
    /**
     * @param list<Stake> $stakes
     * @return array<int, int>
     */
    public function distribute(array $stakes, int $totalStake, int $totalPayout): array
    {
        if ($stakes === [] || $totalStake <= 0) {
            return [];
        }

        $rows = [];
        $allocated = 0;
        foreach ($stakes as $stake) {
            $numerator = $totalPayout * $stake->amount;
            $payout = intdiv($numerator, $totalStake);
            $rows[] = ['id' => $stake->id, 'payout' => $payout, 'remainder' => $numerator % $totalStake];
            $allocated += $payout;
        }
        usort($rows, static fn(array $a, array $b): int => $b['remainder'] <=> $a['remainder'] ?: $a['id'] <=> $b['id']);
        for ($index = 0; $index < $totalPayout - $allocated; ++$index) {
            ++$rows[$index]['payout'];
        }

        $payouts = [];
        foreach ($rows as $row) {
            $payouts[$row['id']] = $row['payout'];
        }
        ksort($payouts);

        return $payouts;
    }
}

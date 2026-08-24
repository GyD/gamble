<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\Bet\BetFinancials;
use App\Domain\Stake\Stake;
use InvalidArgumentException;

final class BetFinancialCalculator
{
    /**
     * @param list<int> $optionIds
     * @param list<Stake> $stakes
     */
    public function calculate(array $optionIds, array $stakes, int $rateBps, ?int $winningOptionId = null): BetFinancials
    {
        if ($rateBps < 0 || $rateBps > 2500) {
            throw new InvalidArgumentException('Bookmaker rate must be between 0% and 25%.');
        }

        $active = array_values(array_filter($stakes, static fn(Stake $stake): bool => !$stake->isCancelled));
        $pot = array_sum(array_column($active, 'amountCents'));
        $amounts = array_fill_keys($optionIds, 0);
        foreach ($active as $stake) {
            $amounts[$stake->betOptionId] = ($amounts[$stake->betOptionId] ?? 0) + $stake->amountCents;
        }

        $odds = [];
        foreach ($optionIds as $optionId) {
            $stakeAmount = $amounts[$optionId];
            if ($stakeAmount === 0) {
                $odds[$optionId] = null;
                continue;
            }
            $share = min($this->roundedRate($pot, $rateBps), $pot - $stakeAmount);
            $odds[$optionId] = (float) (($pot - $share) / $stakeAmount);
        }

        if ($winningOptionId === null) {
            return new BetFinancials($pot, 0, 0, $odds);
        }

        $winningStakes = array_values(array_filter(
            $active,
            static fn(Stake $stake): bool => $stake->betOptionId === $winningOptionId,
        ));
        $winningAmount = $amounts[$winningOptionId] ?? 0;
        $bookmakerShare = $winningAmount === 0
            ? $pot
            : min($this->roundedRate($pot, $rateBps), $pot - $winningAmount);
        $redistributed = $pot - $bookmakerShare;

        return new BetFinancials(
            $pot,
            $bookmakerShare,
            $redistributed,
            $odds,
            $this->distribute($winningStakes, $winningAmount, $redistributed),
        );
    }

    private function roundedRate(int $pot, int $rateBps): int
    {
        return intdiv(($pot * $rateBps) + 5000, 10000);
    }

    /** @param list<Stake> $stakes @return array<int, int> */
    private function distribute(array $stakes, int $totalStake, int $totalPayout): array
    {
        if ($totalStake === 0) {
            return [];
        }
        $rows = [];
        $allocated = 0;
        foreach ($stakes as $stake) {
            $numerator = $totalPayout * $stake->amountCents;
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
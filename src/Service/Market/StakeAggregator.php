<?php

declare(strict_types=1);

namespace App\Service\Market;

use App\Domain\Stake\Stake;

/**
 * Splits stakes between the indicative market and the financial settlement.
 *
 * An unpaid stake may move the indicative market but is never money available
 * for the settlement.
 */
final readonly class StakeAggregator
{
    /**
     * Weighted stakes used by the indicative market.
     *
     * @param list<int> $optionIds
     * @param list<Stake> $stakes
     * @return array<int, float>
     */
    public function effectiveByOption(array $optionIds, array $stakes, float $unpaidWeight): array
    {
        $totals = array_fill_keys($optionIds, 0.0);
        foreach ($stakes as $stake) {
            if (!array_key_exists($stake->betOptionId, $totals)) {
                continue;
            }
            $totals[$stake->betOptionId] += (float) $stake->amount * $stake->marketWeight($unpaidWeight);
        }

        return $totals;
    }

    /**
     * Stakes taken into account by the financial settlement.
     *
     * @param list<Stake> $stakes
     * @return list<Stake>
     */
    public function eligible(array $stakes): array
    {
        return array_values(array_filter($stakes, static fn(Stake $stake): bool => $stake->isFinanciallyEligible()));
    }

    /**
     * @param list<Stake> $stakes
     * @return list<Stake>
     */
    public function eligibleOnOption(array $stakes, int $optionId): array
    {
        return array_values(array_filter(
            $this->eligible($stakes),
            static fn(Stake $stake): bool => $stake->betOptionId === $optionId,
        ));
    }

    /** @param list<Stake> $stakes */
    public function total(array $stakes): int
    {
        $total = 0;
        foreach ($stakes as $stake) {
            $total += $stake->amount;
        }

        return $total;
    }
}

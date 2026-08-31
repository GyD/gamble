<?php

declare(strict_types=1);

namespace App\Service\Market;

use App\Domain\Stake\Stake;
use DateTimeImmutable;

/**
 * Splits stakes between the offered odds and the financial settlement.
 *
 * An unpaid stake may move the offered odds but is never money available for
 * the settlement.
 */
final readonly class StakeAggregator
{
    /**
     * Weighted stakes moving the offered odds.
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
     * Weighted potential payout carried by each option.
     *
     * The drift direction is read from what the bookmaker owes, not from what
     * was staked: two stakes of the same amount taken at different odds do not
     * expose the book the same way. A stake still without contractual odds is
     * projected at the odds given for its option, so an unpaid stake keeps
     * orienting the drift instead of counting for its bare amount. Only the
     * stakes placed after the odds were priced count, so correcting the odds
     * restarts the drift.
     *
     * @param list<int> $optionIds
     * @param list<Stake> $stakes
     * @param array<int, float|null> $projectedOdds odds used for the stakes without contract
     * @return array<int, float>
     */
    public function potentialPayoutByOption(
        array $optionIds,
        array $stakes,
        float $unpaidWeight,
        ?DateTimeImmutable $since = null,
        array $projectedOdds = [],
    ): array {
        $totals = array_fill_keys($optionIds, 0.0);
        foreach ($stakes as $stake) {
            if (!array_key_exists($stake->betOptionId, $totals) || !$stake->isPlacedAfter($since)) {
                continue;
            }
            $payout = $stake->hasContractualOdds()
                ? $stake->potentialPayout()
                : $stake->payoutAt($projectedOdds[$stake->betOptionId] ?? null);
            $totals[$stake->betOptionId] += (float) $payout * $stake->marketWeight($unpaidWeight);
        }

        return $totals;
    }

    /**
     * Weighted volume traded since the given instant, driving the drift intensity.
     *
     * @param list<int> $optionIds
     * @param list<Stake> $stakes
     */
    public function effectiveVolume(
        array $optionIds,
        array $stakes,
        float $unpaidWeight,
        ?DateTimeImmutable $since = null,
    ): float {
        $volume = 0.0;
        foreach ($stakes as $stake) {
            if (!in_array($stake->betOptionId, $optionIds, true) || !$stake->isPlacedAfter($since)) {
                continue;
            }
            $volume += (float) $stake->amount * $stake->marketWeight($unpaidWeight);
        }

        return $volume;
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

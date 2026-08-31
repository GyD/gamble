<?php

declare(strict_types=1);

namespace App\Service\Market;

/**
 * Drift applied to the odds priced by the bookmaker.
 *
 * The bookmaker types the odds; the drift only nudges the odds offered to the
 * next stakes so the book rebalances itself. It never touches the odds already
 * frozen on a stake.
 *
 * Direction comes from the exposure: an option that already concentrates more
 * potential payout than its own price implies sees its odds shorten, the others
 * lengthen. Intensity comes from the traded volume, so a thin market barely
 * moves.
 */
final readonly class OddsDrift
{
    /** Offered odds are published with two decimals, as displayed to the bettors. */
    private const ODDS_SCALE = 2;

    /**
     * Odds offered on each option once the drift is applied.
     *
     * @param array<int, float|null> $pricedOdds odds typed by the bookmaker, per option
     * @param array<int, float> $exposureByOptionId weighted potential payout carried by each option
     * @return array<int, float|null> unpriced options stay unpriced
     */
    public function apply(array $pricedOdds, array $exposureByOptionId, float $bound, float $minimumOdds): array
    {
        $impliedShares = $this->impliedShares($pricedOdds);
        $exposureShares = $this->shares($exposureByOptionId);
        if ($bound <= 0.0 || $impliedShares === [] || $exposureShares === []) {
            return $this->publish($pricedOdds, $minimumOdds);
        }

        $offered = [];
        foreach ($pricedOdds as $optionId => $odds) {
            if ($odds === null) {
                $offered[$optionId] = null;
                continue;
            }
            $intensity = $this->intensity(
                $exposureShares[$optionId] ?? 0.0,
                $impliedShares[$optionId] ?? 0.0,
            );
            $offered[$optionId] = $odds * (1.0 - ($bound * $intensity));
        }

        return $this->publish($offered, $minimumOdds);
    }

    /**
     * Signed deviation between the exposure actually taken on an option and the
     * exposure its own price implies, normalised to [-1, 1] so the whole bound
     * is reachable in both directions.
     */
    private function intensity(float $exposureShare, float $impliedShare): float
    {
        $deviation = $exposureShare - $impliedShare;
        if ($deviation > 0.0) {
            $headroom = 1.0 - $impliedShare;

            return $headroom <= 0.0 ? 0.0 : $deviation / $headroom;
        }

        return $impliedShare <= 0.0 ? 0.0 : $deviation / $impliedShare;
    }

    /**
     * Share of the book implied by the priced odds themselves.
     *
     * A partially priced book cannot be balanced: no share is implied and the
     * odds are published untouched.
     *
     * @param array<int, float|null> $pricedOdds
     * @return array<int, float>
     */
    private function impliedShares(array $pricedOdds): array
    {
        $inverses = [];
        foreach ($pricedOdds as $optionId => $odds) {
            if ($odds === null || $odds <= 0.0) {
                return [];
            }
            $inverses[$optionId] = 1.0 / $odds;
        }

        return $this->shares($inverses);
    }

    /**
     * @param array<int, float> $values
     * @return array<int, float>
     */
    private function shares(array $values): array
    {
        $total = array_sum($values);
        if ($total <= 0.0) {
            return [];
        }

        return array_map(static fn(float $value): float => $value / $total, $values);
    }

    /**
     * @param array<int, float|null> $odds
     * @return array<int, float|null>
     */
    private function publish(array $odds, float $minimumOdds): array
    {
        return array_map(
            static fn(?float $value): ?float => $value === null
                ? null
                : max($minimumOdds, round($value, self::ODDS_SCALE)),
            $odds,
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Service\Market;

use App\Domain\Bet\Bet;
use App\Domain\Stake\Stake;

/**
 * Builds the exposure of the bookmaker on a bet.
 *
 * Two natures of engagement are kept strictly apart. A paid stake owes its
 * frozen `odds_at_bet`: that debt is contractual and never recomputed. An unpaid
 * stake owes nothing yet, so it is only projected at the odds it would actually
 * capture if it were paid now, its own influence excluded: that figure is an
 * estimation, and it moves with the market.
 */
final readonly class ExposureCalculator
{
    /**
     * @param list<Stake> $stakes
     * @param array<int, float|null> $paymentOddsByStakeId payment odds of each
     *        unpaid stake, self-excluded; an unlisted stake falls back to the
     *        odds publicly offered on its option
     */
    public function calculate(Bet $bet, array $stakes, array $paymentOddsByStakeId = []): BetExposure
    {
        $options = [];
        foreach ($bet->options as $option) {
            $paidStake = 0;
            $unpaidStake = 0;
            $paidPayout = 0;
            $unpaidPayout = 0;
            foreach ($stakes as $stake) {
                if ($stake->betOptionId !== $option->id || $stake->isCancelled) {
                    continue;
                }
                if ($stake->isPaid) {
                    $paidStake += $stake->amount;
                    $paidPayout += $stake->potentialPayout();
                    continue;
                }
                $unpaidStake += $stake->amount;
                // Projected at the price this very stake would capture: it has
                // no contract yet, and it never prices itself.
                $unpaidPayout += $stake->payoutAt(
                    array_key_exists($stake->id, $paymentOddsByStakeId)
                        ? $paymentOddsByStakeId[$stake->id]
                        : $option->offeredOdds,
                );
            }

            $options[] = new OptionExposure(
                $option->id,
                $option->label,
                $option->odds,
                $option->offeredOdds,
                $paidStake,
                $unpaidStake,
                $paidPayout,
                $unpaidPayout,
            );
        }

        return new BetExposure($options);
    }
}

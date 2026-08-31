<?php

declare(strict_types=1);

namespace App\Service\Market;

use App\Domain\Bet\Bet;
use App\Domain\Stake\Stake;

/**
 * Builds the exposure of the bookmaker on a bet.
 *
 * Every payout is computed from the odds frozen on each stake, never from the
 * odds currently offered: what the bookmaker owes is already contractual.
 */
final readonly class ExposureCalculator
{
    /** @param list<Stake> $stakes */
    public function calculate(Bet $bet, array $stakes): BetExposure
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
                $unpaidPayout += $stake->potentialPayout();
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

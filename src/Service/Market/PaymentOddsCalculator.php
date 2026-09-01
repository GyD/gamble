<?php

declare(strict_types=1);

namespace App\Service\Market;

use App\Domain\Bet\Bet;
use App\Domain\Stake\Stake;

/**
 * Odds a stake would capture if it were paid right now.
 *
 * An unpaid stake already weighs on the market with `unpaid_bet_market_weight`,
 * so the odds publicly offered to the next bettors already carry its own
 * influence. Charging that degraded price back to the very stake that caused it
 * would let a stake price itself, which is why the payment odds are quoted
 * without it:
 *
 *     payment_odds(stake X) = market odds computed with every stake but X
 *
 * The exclusion happens where the stakes are collected, before anything is
 * aggregated, so the stake is removed at once from its indicative exposure,
 * from the effective volume and from every aggregate deriving the direction or
 * the intensity of the drift. No pricing logic is duplicated: the same market
 * service prices the public odds and the payment odds, only the stakes handed
 * to it differ.
 *
 * Every other stake keeps its usual weight, so the other unpaid stakes still
 * move the payment odds with `unpaid_bet_market_weight`.
 */
final readonly class PaymentOddsCalculator
{
    public function __construct(
        private MarketServiceRegistry $markets = new MarketServiceRegistry(),
    ) {
    }

    /**
     * Odds the given stake would capture if it were paid right now.
     *
     * Self-exclusion only applies to fixed odds: a pari mutuel price is the
     * pool itself, and a stake cannot be taken out of the pool it belongs to
     * without quoting a payout the bookmaker would never honour.
     *
     * @param list<Stake> $stakes every stake of the bet, the given one included
     */
    public function forStake(Bet $bet, array $stakes, Stake $stake): ?float
    {
        $priced = $bet->isFixedOdds() ? $this->without($stakes, $stake->id) : $stakes;

        return $this->markets->forBet($bet)->quote($bet, $priced)->odds($stake->betOptionId);
    }

    /**
     * Payment odds of every stake still waiting for its contract.
     *
     * Each stake excludes itself and only itself, so two unpaid stakes of the
     * same bet may very well be quoted differently: each of them keeps seeing
     * the other.
     *
     * @param list<Stake> $stakes
     * @return array<int, float|null> odds keyed by stake id
     */
    public function byStake(Bet $bet, array $stakes): array
    {
        $odds = [];
        foreach ($stakes as $stake) {
            if ($stake->isPaid || $stake->hasContractualOdds()) {
                continue;
            }
            $odds[$stake->id] = $this->forStake($bet, $stakes, $stake);
        }

        return $odds;
    }

    /**
     * @param list<Stake> $stakes
     * @return list<Stake>
     */
    private function without(array $stakes, int $excludedStakeId): array
    {
        return array_values(array_filter(
            $stakes,
            static fn(Stake $stake): bool => $stake->id !== $excludedStakeId,
        ));
    }
}

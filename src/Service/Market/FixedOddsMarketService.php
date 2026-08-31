<?php

declare(strict_types=1);

namespace App\Service\Market;

use App\Domain\Bet\Bet;
use App\Domain\Bet\BetFinancials;

/**
 * Fixed odds market.
 *
 * The bookmaker prices every option by hand and is paid through the overround
 * built into those odds, so nothing is levied on the pot at settlement: a
 * winning stake is always worth `stake x odds_at_bet`.
 *
 * Depending on the evolution mode, the odds offered to the next stakes drift
 * around the priced ones so the book rebalances itself. The odds already frozen
 * on a stake are never touched.
 */
final readonly class FixedOddsMarketService implements MarketService
{
    public function __construct(
        private MarketSettings $settings,
        private StakeAggregator $aggregator = new StakeAggregator(),
        private OddsDrift $drift = new OddsDrift(),
    ) {
    }

    public function quote(Bet $bet, array $stakes): MarketQuote
    {
        $optionIds = $this->optionIds($bet);
        $effective = $this->aggregator->effectiveByOption($optionIds, $stakes, $this->settings->unpaidBetMarketWeight);
        $volume = $this->aggregator->effectiveVolume(
            $optionIds,
            $stakes,
            $this->settings->unpaidBetMarketWeight,
            $bet->oddsAnchoredAt,
        );
        $exposure = $this->aggregator->potentialPayoutByOption(
            $optionIds,
            $stakes,
            $this->settings->unpaidBetMarketWeight,
            $bet->oddsAnchoredAt,
        );

        $odds = $this->drift->apply(
            $this->pricedOdds($bet),
            $exposure,
            $this->settings->oddsDrift($bet->oddsEvolutionMode, $volume),
            $this->settings->minimumOdds,
        );

        return new MarketQuote($odds, array_sum($effective));
    }

    public function settle(Bet $bet, array $stakes, int $winningOptionId): BetFinancials
    {
        $eligible = $this->aggregator->eligible($stakes);
        $pot = $this->aggregator->total($eligible);

        $payouts = [];
        $redistributed = 0;
        foreach ($eligible as $stake) {
            if ($stake->betOptionId !== $winningOptionId) {
                continue;
            }
            $payout = $stake->potentialPayout();
            $payouts[$stake->id] = $payout;
            $redistributed += $payout;
        }
        ksort($payouts);

        return new BetFinancials(
            $pot,
            0,
            $redistributed,
            $this->pricedOdds($bet),
            $payouts,
            $pot - $redistributed,
        );
    }

    /**
     * Odds typed by the bookmaker on each option.
     *
     * @return array<int, float|null>
     */
    private function pricedOdds(Bet $bet): array
    {
        $odds = [];
        foreach ($bet->options as $option) {
            $odds[$option->id] = $option->odds;
        }

        return $odds;
    }

    /** @return list<int> */
    private function optionIds(Bet $bet): array
    {
        return array_map(static fn($option): int => $option->id, $bet->options);
    }
}

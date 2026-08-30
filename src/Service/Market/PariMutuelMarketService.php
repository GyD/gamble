<?php

declare(strict_types=1);

namespace App\Service\Market;

use App\Domain\Bet\Bet;
use App\Domain\Bet\BetFinancials;
use App\Domain\Stake\Stake;

/**
 * Pari mutuel market.
 *
 * No odds are guaranteed: the stakes feed a common pool and the bookmaker is
 * paid through a commission levied on that pool at settlement, before any
 * redistribution.
 */
final readonly class PariMutuelMarketService implements MarketService
{
    /** Indicative odds are displayed with two decimals. */
    private const ODDS_SCALE = 2;

    public function __construct(
        private MarketSettings $settings,
        private StakeAggregator $aggregator = new StakeAggregator(),
        private PayoutDistributor $distributor = new PayoutDistributor(),
    ) {
    }

    public function quote(Bet $bet, array $stakes): MarketQuote
    {
        $optionIds = $this->optionIds($bet);
        $effective = $this->aggregator->effectiveByOption($optionIds, $stakes, $this->settings->unpaidBetMarketWeight);
        $effectivePool = array_sum($effective);
        $estimatedNetPool = $effectivePool * (1.0 - ($bet->mutuelCommissionRateBps / 10_000));

        $odds = [];
        $probabilities = [];
        foreach ($optionIds as $optionId) {
            $onOption = $effective[$optionId];
            $odds[$optionId] = $onOption <= 0.0 ? null : round($estimatedNetPool / $onOption, self::ODDS_SCALE);
            $probabilities[$optionId] = $effectivePool <= 0.0 ? 0.0 : $onOption / $effectivePool;
        }

        return new MarketQuote($odds, $probabilities, $effectivePool);
    }

    public function settle(Bet $bet, array $stakes, int $winningOptionId): BetFinancials
    {
        $eligible = $this->aggregator->eligible($stakes);
        $finalPool = $this->aggregator->total($eligible);
        // The commission is always levied on the pool, never inflated by the
        // absence of winners.
        $commission = $this->commission($finalPool, $bet->mutuelCommissionRateBps);
        $netPool = $finalPool - $commission;

        $winningStakes = $this->aggregator->eligibleOnOption($stakes, $winningOptionId);
        $winningTotal = $this->aggregator->total($winningStakes);
        // Without a winner nothing is redistributed, and the whole pool stays
        // with the bookmaker.
        $redistributed = $winningTotal === 0 ? 0 : $netPool;
        $payouts = $this->distributor->distribute($winningStakes, $winningTotal, $redistributed);

        return new BetFinancials(
            $finalPool,
            $commission,
            $redistributed,
            $this->finalOdds($bet, $stakes, $netPool),
            $payouts,
            $finalPool - $redistributed,
        );
    }

    private function commission(int $pool, int $rateBps): int
    {
        return intdiv(($pool * $rateBps) + 5_000, 10_000);
    }

    /**
     * Odds each option would actually have paid, based on the settled pool.
     *
     * Unlike the indicative quote, only the stakes taken into account by the
     * settlement are used, so the recorded odds match the payouts.
     *
     * @param list<Stake> $stakes
     * @return array<int, float|null>
     */
    private function finalOdds(Bet $bet, array $stakes, int $netPool): array
    {
        $odds = [];
        foreach ($this->optionIds($bet) as $optionId) {
            $onOption = $this->aggregator->total($this->aggregator->eligibleOnOption($stakes, $optionId));
            $odds[$optionId] = $onOption <= 0 ? null : round($netPool / $onOption, self::ODDS_SCALE);
        }

        return $odds;
    }

    /** @return list<int> */
    private function optionIds(Bet $bet): array
    {
        return array_map(static fn($option): int => $option->id, $bet->options);
    }
}

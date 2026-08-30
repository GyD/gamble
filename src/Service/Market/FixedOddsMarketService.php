<?php

declare(strict_types=1);

namespace App\Service\Market;

use App\Domain\Bet\Bet;
use App\Domain\Bet\BetFinancials;
use App\Domain\Bet\OddsEvolutionMode;
use App\Domain\Stake\Stake;

/**
 * Fixed odds market.
 *
 * The bookmaker is paid through an overround built into the offered odds, so
 * nothing is levied on the pot at settlement: a winning stake is always worth
 * `stake x odds_at_bet`.
 */
final readonly class FixedOddsMarketService implements MarketService
{
    /** Offered odds are quoted with two decimals, as displayed to the bettors. */
    private const ODDS_SCALE = 2;

    /** A stake can never be worth less than its own amount. */
    private const MINIMUM_ODDS = 1.0;

    public function __construct(
        private MarketSettings $settings,
        private ProbabilityNormalizer $normalizer = new ProbabilityNormalizer(new MarketSettings()),
        private StakeAggregator $aggregator = new StakeAggregator(),
    ) {
    }

    public function quote(Bet $bet, array $stakes): MarketQuote
    {
        $optionIds = $this->optionIds($bet);
        $probabilities = $this->probabilities($bet, $stakes);
        $effective = $this->aggregator->effectiveByOption($optionIds, $stakes, $this->settings->unpaidBetMarketWeight);

        $odds = [];
        foreach ($optionIds as $optionId) {
            $odds[$optionId] = $this->offeredOdds($probabilities[$optionId], $bet->bookmakerRateBps);
        }

        return new MarketQuote($odds, $probabilities, array_sum($effective));
    }

    /**
     * Current probabilities of the options, blending the initial ones with the
     * market ones according to the odds evolution mode.
     *
     * @param list<Stake> $stakes
     * @return array<int, float>
     */
    public function probabilities(Bet $bet, array $stakes): array
    {
        $optionIds = $this->optionIds($bet);
        $initial = $this->initialProbabilities($bet);
        if ($bet->oddsEvolutionMode === OddsEvolutionMode::Fixed) {
            return $initial;
        }

        $effective = $this->aggregator->effectiveByOption($optionIds, $stakes, $this->settings->unpaidBetMarketWeight);
        $totalEffective = array_sum($effective);
        if ($totalEffective <= 0.0) {
            return $initial;
        }

        $market = [];
        foreach ($optionIds as $optionId) {
            $market[$optionId] = $effective[$optionId] / $totalEffective;
        }

        return $this->normalizer->blend(
            $initial,
            $market,
            $this->settings->marketWeight($bet->oddsEvolutionMode, $totalEffective),
            $this->currentProbabilities($bet),
        );
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
            $payout = $this->payout($stake);
            $payouts[$stake->id] = $payout;
            $redistributed += $payout;
        }
        ksort($payouts);

        return new BetFinancials(
            $pot,
            0,
            $redistributed,
            $this->quote($bet, $stakes)->oddsByOptionId,
            $payouts,
            $pot - $redistributed,
        );
    }

    /**
     * Payout of a winning stake, based on the odds captured when it was paid.
     *
     * Stakes created before the odds were tracked have no contractual odds:
     * they are refunded rather than silently multiplied.
     */
    private function payout(Stake $stake): int
    {
        if ($stake->oddsAtBet === null) {
            return $stake->amount;
        }

        return max($stake->amount, (int) round((float) $stake->amount * $stake->oddsAtBet));
    }

    /** Fair odds reduced by the bookmaker overround. */
    private function offeredOdds(float $probability, int $rateBps): float
    {
        if ($probability <= 0.0) {
            return self::MINIMUM_ODDS;
        }
        $overround = 1.0 + ((float) $rateBps / 10_000);

        return max(self::MINIMUM_ODDS, round(1.0 / ($probability * $overround), self::ODDS_SCALE));
    }

    /** @return array<int, float> */
    private function initialProbabilities(Bet $bet): array
    {
        $declared = [];
        foreach ($bet->options as $option) {
            if ($option->initialProbability !== null && $option->initialProbability > 0.0) {
                $declared[$option->id] = $option->initialProbability;
            }
        }
        if (count($declared) !== count($bet->options)) {
            return $this->normalizer->equiprobable($this->optionIds($bet));
        }

        return $this->normalizer->normalize($declared);
    }

    /** @return array<int, float> */
    private function currentProbabilities(Bet $bet): array
    {
        $current = [];
        foreach ($bet->options as $option) {
            if ($option->currentProbability !== null && $option->currentProbability > 0.0) {
                $current[$option->id] = $option->currentProbability;
            }
        }

        return count($current) === count($bet->options) ? $current : [];
    }

    /** @return list<int> */
    private function optionIds(Bet $bet): array
    {
        return array_map(static fn($option): int => $option->id, $bet->options);
    }
}

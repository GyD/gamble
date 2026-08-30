<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\Bet\Bet;
use App\Domain\Bet\BetFinancials;
use App\Domain\Bet\BetMode;
use App\Domain\Bet\MarketConfiguration;
use App\Domain\Bet\MarketOdds;
use App\Domain\Bet\OddsEvolutionMode;
use App\Domain\Stake\Stake;
use InvalidArgumentException;

final readonly class BetFinancialCalculator
{
    public function __construct(private MarketConfiguration $configuration)
    {
    }

    /** @param list<Stake> $stakes */
    public function market(Bet $bet, array $stakes): MarketOdds
    {
        $effective = array_fill_keys(array_map(static fn($option): int => $option->id, $bet->options), 0.0);
        foreach ($stakes as $stake) {
            if ($stake->isCancelled) {
                continue;
            }
            $weight = $stake->isPaid ? 1.0 : $this->configuration->unpaidBetMarketWeight;
            $effective[$stake->betOptionId] = ($effective[$stake->betOptionId] ?? 0.0) + ($stake->amount * $weight);
        }
        $effectivePool = array_sum($effective);

        if ($bet->mode === BetMode::PariMutuel) {
            $netPool = $effectivePool * (1 - ($bet->mutuelCommissionRateBps / 10000));
            $odds = [];
            foreach ($effective as $optionId => $amount) {
                $odds[$optionId] = $amount > 0 ? $netPool / $amount : null;
            }

            return new MarketOdds([], $odds, $effectivePool);
        }

        $probabilities = [];
        foreach ($bet->options as $option) {
            $probabilities[$option->id] = $option->currentProbability;
        }
        if ($bet->oddsEvolutionMode !== OddsEvolutionMode::Fixed && $effectivePool > 0) {
            $weight = $this->configuration->maxMarketWeight($bet->oddsEvolutionMode)
                * ($effectivePool / ($effectivePool + $this->configuration->liquidityReference));
            foreach ($bet->options as $option) {
                $target = ((1 - $weight) * $option->initialProbability)
                    + ($weight * ($effective[$option->id] / $effectivePool));
                $limit = $this->configuration->maxProbabilityChangePerRecalculation;
                $delta = max(-$limit, min($limit, $target - $option->currentProbability));
                $probabilities[$option->id] = max($this->configuration->minimumProbability,
                    min($this->configuration->maximumProbability, $option->currentProbability + $delta));
            }
            $probabilities = $this->normalize($probabilities);
        }
        $odds = [];
        $overround = 1 + ($bet->bookmakerRateBps / 10000);
        foreach ($probabilities as $optionId => $probability) {
            $odds[$optionId] = 1 / ($probability * $overround);
        }

        return new MarketOdds($probabilities, $odds, $effectivePool);
    }

    /** @param list<Stake> $stakes */
    public function settlement(Bet $bet, array $stakes, int $winningOptionId): BetFinancials
    {
        $active = array_values(array_filter($stakes,
            static fn(Stake $stake): bool => $stake->isPaid && !$stake->isCancelled));
        $pot = array_sum(array_column($active, 'amount'));
        $winning = array_values(array_filter($active,
            static fn(Stake $stake): bool => $stake->betOptionId === $winningOptionId));

        if ($bet->mode === BetMode::FixedOdds) {
            $payouts = [];
            foreach ($winning as $stake) {
                if ($stake->oddsAtBet === null) {
                    throw new InvalidArgumentException('A paid fixed-odds stake must have contractual odds.');
                }
                $payouts[$stake->id] = (int) round($stake->amount * $stake->oddsAtBet);
            }

            return new BetFinancials($pot, 0, array_sum($payouts), [], $payouts);
        }

        $commission = $this->roundedRate($pot, $bet->mutuelCommissionRateBps);
        $netPool = $pot - $commission;
        $winningAmount = array_sum(array_column($winning, 'amount'));
        if ($winningAmount === 0) {
            return new BetFinancials($pot, $pot, 0, [], []);
        }

        return new BetFinancials($pot, $commission, $netPool, [],
            $this->distribute($winning, $winningAmount, $netPool));
    }

    /** @param array<int, float> $values @return array<int, float> */
    private function normalize(array $values): array
    {
        for ($iteration = 0; $iteration < 10; ++$iteration) {
            $sum = array_sum($values);
            if ($sum <= 0) {
                throw new InvalidArgumentException('Probabilities must have a positive sum.');
            }
            foreach ($values as $id => $value) {
                $values[$id] = max($this->configuration->minimumProbability,
                    min($this->configuration->maximumProbability, $value / $sum));
            }
        }
        $sum = array_sum($values);
        foreach ($values as $id => $value) {
            $values[$id] = $value / $sum;
        }

        return $values;
    }

    private function roundedRate(int $pot, int $rateBps): int
    {
        return intdiv(($pot * $rateBps) + 5000, 10000);
    }

    /** @param list<Stake> $stakes @return array<int, int> */
    private function distribute(array $stakes, int $totalStake, int $totalPayout): array
    {
        $rows = [];
        $allocated = 0;
        foreach ($stakes as $stake) {
            $numerator = $totalPayout * $stake->amount;
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
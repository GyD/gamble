<?php

declare(strict_types=1);

namespace App\Service\Market;

/**
 * Exposure of the bookmaker on a bet, per option.
 *
 * Read by the bookmaker to decide how to price the next odds: an option whose
 * result is deeply negative is already over-backed at the current price.
 */
final readonly class BetExposure
{
    /** @param list<OptionExposure> $options */
    public function __construct(public array $options)
    {
    }

    /** Amount already collected across every option, paid stakes only. */
    public function paidStake(): int
    {
        return $this->sum(static fn(OptionExposure $option): int => $option->paidStake);
    }

    /** Amount pledged across every option but not collected yet. */
    public function unpaidStake(): int
    {
        return $this->sum(static fn(OptionExposure $option): int => $option->unpaidStake);
    }

    /** Debt already contracted across every option, at the odds frozen on the paid stakes. */
    public function contractualPayout(): int
    {
        return $this->sum(static fn(OptionExposure $option): int => $option->paidPayout);
    }

    /** Projection of the unpaid stakes across every option, at the odds currently offered. */
    public function indicativePayout(): int
    {
        return $this->sum(static fn(OptionExposure $option): int => $option->unpaidPayout);
    }

    public function totalStake(): int
    {
        return $this->paidStake() + $this->unpaidStake();
    }

    /** Result if the option wins, based on the money actually collected. */
    public function paidResult(int $optionId): int
    {
        return $this->paidStake() - ($this->option($optionId)?->paidPayout ?? 0);
    }

    /** Result if the option wins once every pledged stake has been collected. */
    public function potentialResult(int $optionId): int
    {
        return $this->totalStake() - ($this->option($optionId)?->totalPayout() ?? 0);
    }

    /** Worst result the bookmaker can face, on the money actually collected. */
    public function worstPaidResult(): int
    {
        return $this->worst(fn(OptionExposure $option): int => $this->paidResult($option->optionId));
    }

    /** Worst result the bookmaker can face once every pledged stake is collected. */
    public function worstPotentialResult(): int
    {
        return $this->worst(fn(OptionExposure $option): int => $this->potentialResult($option->optionId));
    }

    public function option(int $optionId): ?OptionExposure
    {
        foreach ($this->options as $option) {
            if ($option->optionId === $optionId) {
                return $option;
            }
        }

        return null;
    }

    /** @param callable(OptionExposure): int $value */
    private function sum(callable $value): int
    {
        $total = 0;
        foreach ($this->options as $option) {
            $total += $value($option);
        }

        return $total;
    }

    /** @param callable(OptionExposure): int $value */
    private function worst(callable $value): int
    {
        $worst = null;
        foreach ($this->options as $option) {
            $result = $value($option);
            $worst = $worst === null ? $result : min($worst, $result);
        }

        return $worst ?? 0;
    }
}

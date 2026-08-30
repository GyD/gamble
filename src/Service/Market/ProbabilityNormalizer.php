<?php

declare(strict_types=1);

namespace App\Service\Market;

use InvalidArgumentException;

/**
 * Keeps a set of option probabilities consistent: bounded, smoothed and
 * always summing up to 100%.
 */
final readonly class ProbabilityNormalizer
{
    public function __construct(private MarketSettings $settings)
    {
    }

    /**
     * Spreads the probability equally between every option.
     *
     * @param list<int> $optionIds
     * @return array<int, float>
     */
    public function equiprobable(array $optionIds): array
    {
        if ($optionIds === []) {
            throw new InvalidArgumentException('At least one option is required.');
        }
        $share = 1.0 / count($optionIds);

        return $this->normalize(array_fill_keys($optionIds, $share));
    }

    /**
     * Bounds every probability then rescales the set so it sums up to 100%.
     *
     * @param array<int, float> $probabilities
     * @return array<int, float>
     */
    public function normalize(array $probabilities): array
    {
        if ($probabilities === []) {
            throw new InvalidArgumentException('At least one probability is required.');
        }
        $positive = array_filter($probabilities, static fn(float $probability): bool => $probability > 0.0);
        if ($positive === []) {
            return $this->equiprobable(array_keys($probabilities));
        }

        $scaled = $this->rescale($probabilities);
        for ($pass = 0; $pass < 10; ++$pass) {
            $clamped = array_map($this->clamp(...), $scaled);
            if ($this->isNormalized($clamped)) {
                return $clamped;
            }
            $scaled = $this->rescale($clamped);
        }

        return $this->rescale(array_map($this->clamp(...), $scaled));
    }

    /**
     * Blends the initial probabilities with the market ones, then applies the
     * per-recalculation variation limit before normalising again.
     *
     * @param array<int, float> $initial
     * @param array<int, float> $market
     * @param array<int, float> $previous
     * @return array<int, float>
     */
    public function blend(array $initial, array $market, float $marketWeight, array $previous = []): array
    {
        if ($initial === []) {
            throw new InvalidArgumentException('At least one probability is required.');
        }
        $marketWeight = max(0.0, min(1.0, $marketWeight));

        $blended = [];
        foreach ($initial as $optionId => $probability) {
            $marketProbability = $market[$optionId] ?? $probability;
            $blended[$optionId] = ((1.0 - $marketWeight) * $probability) + ($marketWeight * $marketProbability);
        }

        return $this->normalize($this->limitVariation($blended, $previous));
    }

    /**
     * @param array<int, float> $probabilities
     * @param array<int, float> $previous
     * @return array<int, float>
     */
    private function limitVariation(array $probabilities, array $previous): array
    {
        if ($previous === []) {
            return $probabilities;
        }
        $limit = $this->settings->maxProbabilityChangePerRecalculation;

        $limited = [];
        foreach ($probabilities as $optionId => $probability) {
            $reference = $previous[$optionId] ?? null;
            $limited[$optionId] = $reference === null
                ? $probability
                : max($reference - $limit, min($reference + $limit, $probability));
        }

        return $limited;
    }

    /**
     * @param array<int, float> $probabilities
     * @return array<int, float>
     */
    private function rescale(array $probabilities): array
    {
        $total = array_sum($probabilities);
        if ($total <= 0.0) {
            return $this->equiprobable(array_keys($probabilities));
        }

        return array_map(static fn(float $probability): float => $probability / $total, $probabilities);
    }

    /** @param array<int, float> $probabilities */
    private function isNormalized(array $probabilities): bool
    {
        return abs(array_sum($probabilities) - 1.0) < 0.000_000_1;
    }

    private function clamp(float $probability): float
    {
        return max($this->settings->minimumProbability, min($this->settings->maximumProbability, $probability));
    }
}

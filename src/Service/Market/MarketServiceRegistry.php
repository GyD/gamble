<?php

declare(strict_types=1);

namespace App\Service\Market;

use App\Domain\Bet\Bet;
use App\Domain\Bet\BettingMode;

/** Resolves the market service matching the betting mode of a bet. */
final readonly class MarketServiceRegistry
{
    private FixedOddsMarketService $fixedOdds;
    private PariMutuelMarketService $pariMutuel;

    public function __construct(
        MarketSettings $settings = new MarketSettings(),
        ?FixedOddsMarketService $fixedOdds = null,
        ?PariMutuelMarketService $pariMutuel = null,
    ) {
        $this->fixedOdds = $fixedOdds ?? new FixedOddsMarketService($settings, new ProbabilityNormalizer($settings));
        $this->pariMutuel = $pariMutuel ?? new PariMutuelMarketService($settings);
    }

    public function forBet(Bet $bet): MarketService
    {
        return $this->forMode($bet->bettingMode);
    }

    public function forMode(BettingMode $mode): MarketService
    {
        return $mode === BettingMode::FixedOdds ? $this->fixedOdds : $this->pariMutuel;
    }
}

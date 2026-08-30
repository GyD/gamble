<?php

declare(strict_types=1);

namespace App\Service\Market;

use App\Domain\Bet\Bet;
use App\Domain\Bet\BetFinancials;
use App\Domain\Stake\Stake;

interface MarketService
{
    /**
     * Indicative state of the market: odds offered to the next stakes.
     *
     * @param list<Stake> $stakes
     */
    public function quote(Bet $bet, array $stakes): MarketQuote;

    /**
     * Financial settlement, based only on paid and eligible stakes.
     *
     * @param list<Stake> $stakes
     */
    public function settle(Bet $bet, array $stakes, int $winningOptionId): BetFinancials;
}

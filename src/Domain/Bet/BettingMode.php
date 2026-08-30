<?php

declare(strict_types=1);

namespace App\Domain\Bet;

enum BettingMode: string
{
    case FixedOdds = 'fixed_odds';
    case PariMutuel = 'pari_mutuel';
}

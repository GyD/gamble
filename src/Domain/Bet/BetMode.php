<?php

declare(strict_types=1);

namespace App\Domain\Bet;

enum BetMode: string
{
    case FixedOdds = 'fixed_odds';
    case PariMutuel = 'pari_mutuel';
}
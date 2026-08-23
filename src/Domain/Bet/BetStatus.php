<?php

declare(strict_types=1);

namespace App\Domain\Bet;

enum BetStatus: string
{
    case Open = 'open';
    case Closed = 'closed';
    case Settled = 'settled';
    case Cancelled = 'cancelled';
}
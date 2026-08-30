<?php

declare(strict_types=1);

namespace App\Domain\Bet;

enum OddsEvolutionMode: string
{
    case Fixed = 'fixed';
    case DynamicLow = 'dynamic_low';
    case DynamicNormal = 'dynamic_normal';
    case DynamicHigh = 'dynamic_high';
}
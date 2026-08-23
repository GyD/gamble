<?php

declare(strict_types=1);

namespace App\Domain\Bet;

use InvalidArgumentException;

final class BetAccessDeniedException extends InvalidArgumentException
{
}
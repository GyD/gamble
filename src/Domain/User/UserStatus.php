<?php

declare(strict_types=1);

namespace App\Domain\User;

enum UserStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Suspended = 'suspended';
}
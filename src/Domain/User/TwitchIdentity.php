<?php

declare(strict_types=1);

namespace App\Domain\User;

final readonly class TwitchIdentity
{
    public function __construct(
        public string  $id,
        public string  $login,
        public string  $displayName,
        public ?string $avatarUrl,
    )
    {
    }
}
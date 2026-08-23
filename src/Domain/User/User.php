<?php

declare(strict_types=1);

namespace App\Domain\User;

final readonly class User
{
    public function __construct(
        public int        $id,
        public string     $twitchId,
        public string     $twitchLogin,
        public string     $twitchDisplayName,
        public ?string    $twitchAvatarUrl,
        public UserStatus $status,
    )
    {
    }

    public function isActive(): bool
    {
        return $this->status === UserStatus::Active;
    }
}
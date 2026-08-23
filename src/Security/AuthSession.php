<?php

declare(strict_types=1);

namespace App\Security;

final class AuthSession
{
    private const USER_ID_KEY = 'authenticated_user_id';
    private const OAUTH_STATE_KEY = 'oauth_state';

    public function userId(): ?int
    {
        $userId = $_SESSION[self::USER_ID_KEY] ?? null;

        return is_int($userId) ? $userId : null;
    }

    public function authenticate(int $userId): void
    {
        session_regenerate_id(true);
        $_SESSION[self::USER_ID_KEY] = $userId;
        unset($_SESSION[self::OAUTH_STATE_KEY]);
    }

    public function logout(): void
    {
        $_SESSION = [];
        session_regenerate_id(true);
    }

    public function rememberOAuthState(string $state): void
    {
        $_SESSION[self::OAUTH_STATE_KEY] = $state;
    }

    public function consumeOAuthState(string $state): bool
    {
        $expected = $_SESSION[self::OAUTH_STATE_KEY] ?? null;
        unset($_SESSION[self::OAUTH_STATE_KEY]);

        return is_string($expected) && hash_equals($expected, $state);
    }
}
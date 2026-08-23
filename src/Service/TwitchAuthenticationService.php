<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\User\User;
use App\Repository\UserStore;
use App\Security\AuthSession;
use App\Security\OAuthStateStore;
use App\Security\TwitchClient;
use App\Security\TwitchOAuthException;

final readonly class TwitchAuthenticationService
{
    public function __construct(
        private TwitchClient    $twitch,
        private OAuthStateStore $states,
        private UserStore       $users,
        private AuthSession     $session,
    )
    {
    }

    public function beginAuthorization(): string
    {
        $state = bin2hex(random_bytes(32));
        $this->states->create($state);
        $this->session->rememberOAuthState($state);

        return $this->twitch->authorizationUrl($state);
    }

    public function completeAuthorization(string $code, string $state): User
    {
        if ($code === '' || $state === '') {
            throw new TwitchOAuthException('Missing OAuth callback parameters.');
        }

        $matchesSession = $this->session->consumeOAuthState($state);
        $existsInDatabase = $this->states->consume($state);

        if (!$matchesSession || !$existsInDatabase) {
            throw new TwitchOAuthException('Invalid or expired OAuth state.');
        }

        $user = $this->users->synchronizeTwitchIdentity(
            $this->twitch->identityFromAuthorizationCode($code),
        );
        $this->session->authenticate($user->id);

        return $user;
    }
}
<?php

declare(strict_types=1);

namespace App\Security;

use App\Domain\User\TwitchIdentity;

interface TwitchClient
{
    public function authorizationUrl(string $state): string;

    public function identityFromAuthorizationCode(string $code): TwitchIdentity;
}
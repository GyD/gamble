<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\User\TwitchIdentity;
use App\Domain\User\User;

interface UserStore
{
    public function findById(int $id): ?User;

    public function synchronizeTwitchIdentity(TwitchIdentity $identity): User;
}
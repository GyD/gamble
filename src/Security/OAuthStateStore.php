<?php

declare(strict_types=1);

namespace App\Security;

interface OAuthStateStore
{
    public function create(string $state): void;

    public function consume(string $state): bool;
}
<?php

declare(strict_types=1);

namespace Tests\Unit\Repository;

use App\Repository\PdoPermissionRepository;
use PDO;
use PHPUnit\Framework\TestCase;

final class PdoPermissionRepositoryTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('CREATE TABLE roles (id INTEGER PRIMARY KEY, name TEXT NOT NULL)');
        $this->pdo->exec('CREATE TABLE user_roles (user_id INTEGER NOT NULL, role_id INTEGER NOT NULL)');
        $this->pdo->exec("INSERT INTO roles (id, name) VALUES (1, 'bookmaker'), (2, 'unknown')");
        $this->pdo->exec('INSERT INTO user_roles (user_id, role_id) VALUES (10, 1), (11, 2)');
    }

    public function testConfiguredRoleGrantsPermission(): void
    {
        $repository = $this->repository();

        self::assertSame('allow', $repository->effectFor(10, 'bets.view'));
    }

    public function testRoleDoesNotGrantUnmappedPermission(): void
    {
        $repository = $this->repository();

        self::assertNull($repository->effectFor(10, 'users.manage'));
    }

    public function testUnknownRoleAndPermissionGrantNothing(): void
    {
        $repository = $this->repository();

        self::assertNull($repository->effectFor(11, 'bets.view'));
        self::assertNull($repository->effectFor(10, 'root.access'));
    }

    private function repository(): PdoPermissionRepository
    {
        return new PdoPermissionRepository($this->pdo, [
            'permissions' => ['bets.view', 'users.manage'],
            'roles' => ['bookmaker' => ['bets.view']],
        ]);
    }
}

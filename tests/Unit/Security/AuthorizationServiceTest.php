<?php

declare(strict_types=1);

namespace Tests\Unit\Security;

use App\Domain\User\User;
use App\Domain\User\UserStatus;
use App\Security\AuthorizationService;
use App\Security\PermissionResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AuthorizationServiceTest extends TestCase
{
    #[DataProvider('authorizationCases')]
    public function testPermissionDecision(UserStatus $status, ?string $effect, bool $expected): void
    {
        $resolver = new class($effect) implements PermissionResolver {
            public function __construct(private readonly ?string $effect)
            {
            }

            public function effectFor(int $userId, string $permission): ?string
            {
                return $this->effect;
            }
        };
        $user = new User(1, '123', 'viewer', 'Viewer', null, $status);

        self::assertSame($expected, (new AuthorizationService($resolver))->can($user, 'bets.view'));
    }

    /** @return iterable<string, array{UserStatus, string|null, bool}> */
    public static function authorizationCases(): iterable
    {
        yield 'active with allow' => [UserStatus::Active, 'allow', true];
        yield 'active with explicit deny' => [UserStatus::Active, 'deny', false];
        yield 'active without permission' => [UserStatus::Active, null, false];
        yield 'pending with allow' => [UserStatus::Pending, 'allow', false];
        yield 'suspended with allow' => [UserStatus::Suspended, 'allow', false];
    }
}
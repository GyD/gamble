<?php

declare(strict_types=1);

namespace Tests\Unit\Middleware;

use App\Domain\User\User;
use App\Domain\User\UserStatus;
use App\Middleware\RequireUsersViewPermissionMiddleware;
use App\Middleware\RequireContactsCreatePermissionMiddleware;
use App\Middleware\RequireContactsDeletePermissionMiddleware;
use App\Middleware\RequireContactsEditPermissionMiddleware;
use App\Middleware\RequireContactsViewPermissionMiddleware;
use App\Middleware\RequireGroupsCreatePermissionMiddleware;
use App\Middleware\RequireGroupsDeletePermissionMiddleware;
use App\Middleware\RequireGroupsEditPermissionMiddleware;
use App\Middleware\RequireGroupsViewPermissionMiddleware;
use App\Middleware\RequirePermissionsManagePermissionMiddleware;
use App\Middleware\RequireUsersManagePermissionMiddleware;
use App\Security\AuthorizationService;
use App\Security\PermissionResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Server\MiddlewareInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

final class RequireUsersViewPermissionMiddlewareTest extends TestCase
{
    #[DataProvider('accessCases')]
    public function testAccessIsControlledByPermission(
        ?string $effect,
        int $expectedStatus,
    ): void {
        $resolver = new class($effect) implements PermissionResolver {
            public function __construct(private readonly ?string $effect)
            {
            }

            public function effectFor(int $userId, string $permission): ?string
            {
                TestCase::assertSame(42, $userId);
                TestCase::assertSame('users.view', $permission);

                return $this->effect;
            }
        };
        $middleware = new RequireUsersViewPermissionMiddleware(new AuthorizationService($resolver));
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/admin/users')
            ->withAttribute(
                'user',
                new User(42, '123', 'admin', 'Admin', null, UserStatus::Active),
            );
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response(204);
            }
        };

        self::assertSame($expectedStatus, $middleware->process($request, $handler)->getStatusCode());
    }

    /** @return iterable<string, array{string|null, int}> */
    public static function accessCases(): iterable
    {
        yield 'allowed' => ['allow', 204];
        yield 'denied' => ['deny', 403];
        yield 'missing' => [null, 403];
    }

    public function testRequestWithoutUserIsForbidden(): void
    {
        $resolver = new class implements PermissionResolver {
            public function effectFor(int $userId, string $permission): ?string
            {
                TestCase::fail('Permission resolver must not be called without a user.');
            }
        };
        $middleware = new RequireUsersViewPermissionMiddleware(new AuthorizationService($resolver));
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/admin/users');
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                TestCase::fail('Handler must not be called for a forbidden request.');
            }
        };

        self::assertSame(403, $middleware->process($request, $handler)->getStatusCode());
    }

    /** @param class-string<MiddlewareInterface> $middlewareClass */
    #[DataProvider('permissionMiddlewareCases')]
    public function testEachMiddlewareRequestsItsOwnPermission(
        string $middlewareClass,
        string $expectedPermission,
    ): void {
        $resolver = new class($expectedPermission) implements PermissionResolver {
            public function __construct(private readonly string $expectedPermission)
            {
            }

            public function effectFor(int $userId, string $permission): ?string
            {
                TestCase::assertSame($this->expectedPermission, $permission);

                return 'allow';
            }
        };
        $middleware = new $middlewareClass(new AuthorizationService($resolver));
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/')
            ->withAttribute('user', new User(42, '123', 'admin', 'Admin', null, UserStatus::Active));
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response(204);
            }
        };

        self::assertSame(204, $middleware->process($request, $handler)->getStatusCode());
    }

    /** @return iterable<string, array{class-string<MiddlewareInterface>, string}> */
    public static function permissionMiddlewareCases(): iterable
    {
        yield 'view contacts' => [RequireContactsViewPermissionMiddleware::class, 'contacts.view'];
        yield 'create contacts' => [RequireContactsCreatePermissionMiddleware::class, 'contacts.create'];
        yield 'edit contacts' => [RequireContactsEditPermissionMiddleware::class, 'contacts.edit'];
        yield 'archive contacts' => [RequireContactsDeletePermissionMiddleware::class, 'contacts.delete'];
        yield 'view groups' => [RequireGroupsViewPermissionMiddleware::class, 'groups.view'];
        yield 'create groups' => [RequireGroupsCreatePermissionMiddleware::class, 'groups.create'];
        yield 'edit groups' => [RequireGroupsEditPermissionMiddleware::class, 'groups.edit'];
        yield 'delete groups' => [RequireGroupsDeletePermissionMiddleware::class, 'groups.delete'];
        yield 'view users' => [RequireUsersViewPermissionMiddleware::class, 'users.view'];
        yield 'manage users' => [RequireUsersManagePermissionMiddleware::class, 'users.manage'];
        yield 'manage permissions' => [
            RequirePermissionsManagePermissionMiddleware::class,
            'permissions.manage',
        ];
    }
}
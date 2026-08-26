<?php

declare(strict_types=1);

namespace Tests\Unit\Controller\Admin;

use App\Controller\Admin\UserController;
use App\Domain\User\User;
use App\Domain\User\UserStatus;
use App\Repository\AuditLogger;
use App\Repository\UserAdministrationStore;
use App\Security\AuthorizationService;
use App\Security\PermissionResolver;
use App\Service\UserAdministrationService;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

final class UserControllerTest extends TestCase
{
    private ControllerUserStore $users;
    private ControllerAuditLogger $auditLogs;
    private ControllerPermissionResolver $permissions;
    private UserController $controller;
    private User $actor;

    protected function setUp(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $this->actor = new User(1, '1', 'admin', 'Admin', null, UserStatus::Active);
        $this->users = new ControllerUserStore([
            1 => $this->actor,
            2 => new User(2, '2', 'viewer', 'Viewer', null, UserStatus::Pending),
        ]);
        $this->auditLogs = new ControllerAuditLogger();
        $this->permissions = new ControllerPermissionResolver([
            'users.manage' => 'allow',
            'permissions.manage' => 'allow',
        ]);
        $this->controller = new UserController(
            $this->users,
            new UserAdministrationService($pdo, $this->users, $this->auditLogs),
            new AuthorizationService($this->permissions),
            new Environment(new FilesystemLoader(dirname(__DIR__, 4) . '/templates')),
        );
    }

    public function testReadOnlyIndexHidesMutationActions(): void
    {
        $this->permissions->effects = [];
        $this->users->listedUsers = [$this->listedTarget()];

        $response = $this->controller->index($this->request('GET'), new Response());
        $html = (string) $response->getBody();

        self::assertStringContainsString('Viewer', $html);
        self::assertStringNotContainsString('Suspendre', $html);
        self::assertStringNotContainsString('>Accès</a>', $html);
        self::assertStringNotContainsString('/admin/users/2/status', $html);
    }

    public function testIndexShowsActionsAllowedByPermissions(): void
    {
        $this->users->listedUsers = [$this->listedTarget()];

        $response = $this->controller->index($this->request('GET'), new Response());
        $html = (string) $response->getBody();

        self::assertStringContainsString('/admin/users/2/status', $html);
        self::assertStringContainsString('Activer', $html);
        self::assertStringContainsString('/admin/users/2/access', $html);
    }

    public function testEditAccessDisplaysTwitchDisplayName(): void
    {
        $response = $this->controller->editAccess(
            $this->request('GET'),
            new Response(),
            ['id' => '2'],
        );
        $html = (string) $response->getBody();

        self::assertStringContainsString('<title>Accès de Viewer', $html);
        self::assertStringContainsString('<h1>Accès de Viewer</h1>', $html);
    }

    /** @param array<string, mixed> $body */
    #[DataProvider('invalidStatusRequests')]
    public function testInvalidStatusRequestReturnsBadRequest(string $id, array $body): void
    {
        $request = $this->request('POST', $body);

        $response = $this->controller->changeStatus($request, new Response(), ['id' => $id]);

        self::assertSame(400, $response->getStatusCode());
        self::assertSame(UserStatus::Pending, $this->users->users[2]->status);
        self::assertSame([], $this->auditLogs->entries);
    }

    /** @return iterable<string, array{string, array<string, mixed>}> */
    public static function invalidStatusRequests(): iterable
    {
        yield 'zero identifier' => ['0', ['status' => 'active']];
        yield 'negative identifier' => ['-2', ['status' => 'active']];
        yield 'non numeric identifier' => ['viewer', ['status' => 'active']];
        yield 'unknown status' => ['2', ['status' => 'deleted']];
        yield 'non string status' => ['2', ['status' => ['active']]];
    }

    public function testStatusChangeRedirectsAndAuditsClientAddress(): void
    {
        $request = $this->request('POST', ['status' => 'active'], ['REMOTE_ADDR' => '192.0.2.10']);

        $response = $this->controller->changeStatus($request, new Response(), ['id' => '2']);

        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/admin/users?saved=1', $response->getHeaderLine('Location'));
        self::assertSame(UserStatus::Active, $this->users->users[2]->status);
        self::assertSame('192.0.2.10', $this->auditLogs->entries[0]['ip_address']);
    }

    /** @param array<string, mixed> $body */
    #[DataProvider('invalidAccessRequests')]
    public function testInvalidAccessPayloadReturnsBadRequest(array $body): void
    {
        $response = $this->controller->updateAccess(
            $this->request('POST', $body),
            new Response(),
            ['id' => '2'],
        );

        self::assertSame(400, $response->getStatusCode());
        self::assertSame([], $this->users->rolesByUser);
        self::assertSame([], $this->auditLogs->entries);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidAccessRequests(): iterable
    {
        yield 'roles are scalar' => [['roles' => 'admin']];
        yield 'role is not string' => [['roles' => ['admin', 42]]];
    }

    public function testAccessUpdateNormalizesRolesAndRedirects(): void
    {
        $response = $this->controller->updateAccess(
            $this->request('POST', [
                'roles' => ['admin', 'admin'],
            ]),
            new Response(),
            ['id' => '2'],
        );

        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/admin/users/2/access?saved=1', $response->getHeaderLine('Location'));
        self::assertSame(['admin'], $this->users->rolesByUser[2]);
    }

    public function testMissingAccessTargetReturnsNotFound(): void
    {
        $response = $this->controller->editAccess($this->request('GET'), new Response(), ['id' => '999']);

        self::assertSame(404, $response->getStatusCode());
    }

    private function request(string $method, array $body = [], array $serverParams = []): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest($method, '/admin/users', $serverParams)
            ->withParsedBody($body)
            ->withAttribute('user', $this->actor);
    }

    /** @return array<string, mixed> */
    private function listedTarget(): array
    {
        return [
            'id' => 2,
            'twitch_id' => '2',
            'twitch_login' => 'viewer',
            'twitch_display_name' => 'Viewer',
            'twitch_avatar_url' => null,
            'status' => 'pending',
            'last_login_at' => null,
            'role_labels' => '',
        ];
    }
}

final class ControllerUserStore implements UserAdministrationStore
{
    /** @var list<array<string, mixed>> */
    public array $listedUsers = [];

    /** @var array<int, list<string>> */
    public array $rolesByUser = [];

    /** @param array<int, User> $users */
    public function __construct(public array $users)
    {
    }

    public function findById(int $id): ?User
    {
        return $this->users[$id] ?? null;
    }

    public function findAllWithRoles(): array
    {
        return $this->listedUsers;
    }

    public function findAllRoles(): array
    {
        return [['id' => 1, 'name' => 'admin', 'label' => 'Administrator']];
    }

    public function roleNamesFor(int $userId): array
    {
        return $this->rolesByUser[$userId] ?? [];
    }

    public function updateStatus(int $userId, UserStatus $status): void
    {
        $user = $this->users[$userId];
        $this->users[$userId] = new User(
            $user->id,
            $user->twitchId,
            $user->twitchLogin,
            $user->twitchDisplayName,
            $user->twitchAvatarUrl,
            $status,
        );
    }

    public function replaceRoles(int $userId, array $roleNames): void
    {
        $this->rolesByUser[$userId] = $roleNames;
    }
}

final class ControllerPermissionResolver implements PermissionResolver
{
    /** @param array<string, string> $effects */
    public function __construct(public array $effects)
    {
    }

    public function effectFor(int $userId, string $permission): ?string
    {
        return $this->effects[$permission] ?? null;
    }
}

final class ControllerAuditLogger implements AuditLogger
{
    /** @var list<array<string, mixed>> */
    public array $entries = [];

    public function record(
        int $actorUserId,
        string $action,
        string $entityType,
        string $entityId,
        ?array $before,
        ?array $after,
        ?string $ipAddress,
    ): void {
        $this->entries[] = [
            'actor_user_id' => $actorUserId,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'before' => $before,
            'after' => $after,
            'ip_address' => $ipAddress,
        ];
    }
}
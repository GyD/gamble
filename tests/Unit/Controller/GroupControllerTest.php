<?php

declare(strict_types=1);

namespace Tests\Unit\Controller;

use App\Controller\GroupController;
use App\Domain\Group\Group;
use App\Domain\User\User;
use App\Domain\User\UserStatus;
use App\Repository\AuditLogger;
use App\Repository\GroupStore;
use App\Security\AuthorizationService;
use App\Security\PermissionResolver;
use App\Service\GroupService;
use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

final class GroupControllerTest extends TestCase
{
    private ControllerGroupStore $groups;
    private ControllerGroupAuditLogger $audit;
    private GroupController $controller;

    protected function setUp(): void
    {
        $this->groups = new ControllerGroupStore();
        $this->audit = new ControllerGroupAuditLogger();
        $permissions = new class implements PermissionResolver {
            public function effectFor(int $userId, string $permission): ?string { return 'allow'; }
        };
        $this->controller = new GroupController(
            $this->groups,
            new GroupService(new PDO('sqlite::memory:'), $this->groups, $this->audit),
            new AuthorizationService($permissions),
            new Environment(new FilesystemLoader(dirname(__DIR__, 3) . '/templates')),
        );
    }

    public function testIndexShowsMembersReadOnlyAndActions(): void
    {
        $this->groups->groups[1] = new Group(1, 'Friends', 'Close friends', null);

        $response = $this->controller->index($this->request('GET'), new Response());
        $html = (string) $response->getBody();

        self::assertStringContainsString('Friends', $html);
        self::assertStringContainsString('Aucun membre. Ajoutez les contacts depuis leur fiche.', $html);
        self::assertStringContainsString('action="/groups/1/archive"', $html);
    }

    public function testIndexShowsPrimaryNavigation(): void
    {
        $html = (string) $this->controller->index($this->request('GET'), new Response())->getBody();

        self::assertStringContainsString('aria-label="Navigation principale"', $html);
        self::assertStringContainsString('href="/bets"', $html);
        self::assertStringContainsString('href="/contacts"', $html);
        self::assertStringContainsString('href="/groups"', $html);
        self::assertStringContainsString('href="/admin/users"', $html);
    }

    public function testCreateRedirectsAndAudits(): void
    {
        $response = $this->controller->create(
            $this->request('POST', ['name' => ' Friends ', 'note' => '']),
            new Response(),
        );

        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/groups?saved=1', $response->getHeaderLine('Location'));
        self::assertSame('Friends', $this->groups->groups[1]->name);
        self::assertSame('group.created', $this->audit->entries[0]['action']);
    }

    public function testActiveGroupCannotBeDeleted(): void
    {
        $this->groups->groups[1] = new Group(1, 'Friends', null, null);

        $response = $this->controller->delete($this->request('POST'), new Response(), ['id' => '1']);

        self::assertSame(400, $response->getStatusCode());
        self::assertArrayHasKey(1, $this->groups->groups);
    }

    /** @param array<string, mixed> $body */
    private function request(string $method, array $body = []): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest($method, '/groups')
            ->withParsedBody($body)
            ->withAttribute('user', new User(1, '1', 'admin', 'Admin', null, UserStatus::Active))
            ->withAttribute('csrf_name', 'name-value')
            ->withAttribute('csrf_value', 'value-value');
    }
}

final class ControllerGroupStore implements GroupStore
{
    /** @var array<int, Group> */
    public array $groups = [];
    public function findAll(): array { return array_values($this->groups); }
    public function findById(int $id): ?Group { return $this->groups[$id] ?? null; }
    public function create(string $name, ?string $note): Group
    {
        $id = count($this->groups) + 1;
        return $this->groups[$id] = new Group($id, $name, $note, null);
    }
    public function update(int $id, string $name, ?string $note): void
    {
        $group = $this->groups[$id];
        $this->groups[$id] = new Group($id, $name, $note, $group->archivedAt, $group->members);
    }
    public function setArchived(int $id, bool $archived): void
    {
        $group = $this->groups[$id];
        $this->groups[$id] = new Group($id, $group->name, $group->note, $archived ? new DateTimeImmutable() : null, $group->members);
    }
    public function delete(int $id): void { unset($this->groups[$id]); }
    public function findAvailableForContact(int $contactId): array { return []; }
    public function memberGroupIds(int $contactId): array { return []; }
    public function syncContactGroups(int $contactId, array $groupIds): void {}
}

final class ControllerGroupAuditLogger implements AuditLogger
{
    /** @var list<array<string, mixed>> */
    public array $entries = [];
    public function record(int $actorUserId, string $action, string $entityType, string $entityId, ?array $before, ?array $after, ?string $ipAddress): void
    {
        $this->entries[] = compact('action', 'before', 'after');
    }
}
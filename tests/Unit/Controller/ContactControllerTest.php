<?php

declare(strict_types=1);

namespace Tests\Unit\Controller;

use App\Controller\ContactController;
use App\Domain\Contact\Contact;
use App\Domain\User\User;
use App\Domain\User\UserStatus;
use App\Repository\AuditLogger;
use App\Repository\ContactStore;
use App\Security\AuthorizationService;
use App\Security\PermissionResolver;
use App\Service\ContactService;
use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

final class ContactControllerTest extends TestCase
{
    private ControllerContactStore $contacts;
    private ControllerContactAuditLogger $auditLogs;
    private ControllerContactPermissionResolver $permissions;
    private ContactController $controller;
    private User $actor;

    protected function setUp(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $this->actor = new User(1, '1', 'admin', 'Admin', null, UserStatus::Active);
        $this->contacts = new ControllerContactStore();
        $this->auditLogs = new ControllerContactAuditLogger();
        $this->permissions = new ControllerContactPermissionResolver([
            'contacts.create' => 'allow',
            'contacts.edit' => 'allow',
            'contacts.delete' => 'allow',
        ]);
        $this->controller = new ContactController(
            $this->contacts,
            new ContactService($pdo, $this->contacts, $this->auditLogs),
            new AuthorizationService($this->permissions),
            new Environment(new FilesystemLoader(dirname(__DIR__, 3) . '/templates')),
        );
    }

    public function testIndexHidesActionsWithoutPermissions(): void
    {
        $this->contacts->contacts[1] = new Contact(1, 'Alice', '0042', null, null);
        $this->permissions->effects = [];

        $response = $this->controller->index($this->request('GET'), new Response());
        $html = (string) $response->getBody();

        self::assertStringContainsString('Alice', $html);
        self::assertStringContainsString('Téléphone : 0042', $html);
        self::assertStringNotContainsString('Ajouter le contact', $html);
        self::assertStringNotContainsString('Modifier', $html);
        self::assertStringNotContainsString('Archiver', $html);
    }

    public function testIndexRendersCsrfFieldsExpectedByGuard(): void
    {
        $response = $this->controller->index($this->request('GET'), new Response());
        $html = (string) $response->getBody();

        self::assertStringContainsString('name="csrf_name" value="name-value"', $html);
        self::assertStringContainsString('name="csrf_value" value="value-value"', $html);
    }

    public function testIndexOnlyOffersPermanentDeletionForArchivedContacts(): void
    {
        $this->contacts->contacts[1] = new Contact(1, 'Active', '1', null, null);
        $this->contacts->contacts[2] = new Contact(2, 'Archived', '2', null, new DateTimeImmutable());

        $html = (string) $this->controller->index($this->request('GET'), new Response())->getBody();

        self::assertSame(1, substr_count($html, '>Supprimer définitivement</button>'));
        self::assertStringContainsString('action="/contacts/2/delete"', $html);
        self::assertStringNotContainsString('action="/contacts/1/delete"', $html);
    }

    public function testCreateRedirectsAndPersistsContact(): void
    {
        $response = $this->controller->create(
            $this->request('POST', ['name' => ' Alice ', 'phone_number' => ' 0042 ', 'note' => ' Friend ']),
            new Response(),
        );

        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/contacts?saved=1', $response->getHeaderLine('Location'));
        self::assertSame('Alice', $this->contacts->contacts[1]->name);
        self::assertSame('0042', $this->contacts->contacts[1]->phoneNumber);
        self::assertSame('contact.created', $this->auditLogs->entries[0]['action']);
    }

    /** @param array<string, mixed> $body */
    #[DataProvider('invalidMutationRequests')]
    public function testInvalidMutationReturnsBadRequest(string $operation, string $id, array $body): void
    {
        $this->contacts->contacts[1] = new Contact(1, 'Alice', '1234', null, null);
        $response = match ($operation) {
            'update' => $this->controller->update($this->request('POST', $body), new Response(), ['id' => $id]),
            'archive' => $this->controller->archive($this->request('POST', $body), new Response(), ['id' => $id]),
        };

        self::assertSame(400, $response->getStatusCode());
        self::assertSame([], $this->auditLogs->entries);
    }

    /** @return iterable<string, array{string, string, array<string, mixed>}> */
    public static function invalidMutationRequests(): iterable
    {
        yield 'invalid update identifier' => ['update', 'abc', ['name' => 'Alice', 'phone_number' => '1234']];
        yield 'invalid phone number' => ['update', '1', ['name' => 'Alice', 'phone_number' => '12345']];
        yield 'invalid archive identifier' => ['archive', '0', ['archived' => '1']];
        yield 'invalid archive state' => ['archive', '1', ['archived' => 'yes']];
    }

    #[DataProvider('unknownContactOperations')]
    public function testUnknownContactReturnsNotFound(string $operation): void
    {
        $request = $this->request('POST', ['name' => 'Nobody', 'phone_number' => '1234', 'archived' => '1']);
        $response = match ($operation) {
            'edit' => $this->controller->edit($request, new Response(), ['id' => '404']),
            'update' => $this->controller->update($request, new Response(), ['id' => '404']),
            'archive' => $this->controller->archive($request, new Response(), ['id' => '404']),
        };

        self::assertSame(404, $response->getStatusCode());
        self::assertSame([], $this->auditLogs->entries);
    }

    /** @return iterable<string, array{string}> */
    public static function unknownContactOperations(): iterable
    {
        yield 'edit' => ['edit'];
        yield 'update' => ['update'];
        yield 'archive' => ['archive'];
    }

    public function testUpdateAndArchiveRedirect(): void
    {
        $this->contacts->contacts[1] = new Contact(1, 'Alice', '1234', null, null);

        $updated = $this->controller->update(
            $this->request('POST', ['name' => 'Alice Cooper', 'phone_number' => '0042', 'note' => 'Friend']),
            new Response(),
            ['id' => '1'],
        );
        $archived = $this->controller->archive(
            $this->request('POST', ['archived' => '1']),
            new Response(),
            ['id' => '1'],
        );

        self::assertSame(303, $updated->getStatusCode());
        self::assertSame('/contacts?saved=1', $updated->getHeaderLine('Location'));
        self::assertSame(303, $archived->getStatusCode());
        self::assertTrue($this->contacts->contacts[1]->isArchived());
        self::assertSame(['contact.updated', 'contact.archived'], array_column($this->auditLogs->entries, 'action'));
    }

    public function testDeleteRemovesArchivedContact(): void
    {
        $this->contacts->contacts[1] = new Contact(1, 'Alice', '0042', null, new DateTimeImmutable());

        $response = $this->controller->delete($this->request('POST'), new Response(), ['id' => '1']);

        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/contacts?saved=1', $response->getHeaderLine('Location'));
        self::assertArrayNotHasKey(1, $this->contacts->contacts);
        self::assertSame('contact.deleted', $this->auditLogs->entries[0]['action']);
    }

    public function testDeleteRejectsActiveContact(): void
    {
        $this->contacts->contacts[1] = new Contact(1, 'Alice', '0042', null, null);

        $response = $this->controller->delete($this->request('POST'), new Response(), ['id' => '1']);

        self::assertSame(400, $response->getStatusCode());
        self::assertStringContainsString('Only archived contacts can be deleted.', (string) $response->getBody());
        self::assertArrayHasKey(1, $this->contacts->contacts);
    }

    /** @param array<string, mixed> $body */
    private function request(string $method, array $body = []): ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest($method, '/contacts')
            ->withParsedBody($body)
            ->withAttribute('user', $this->actor)
            ->withAttribute('csrf_name', 'name-value')
            ->withAttribute('csrf_value', 'value-value');
    }
}

final class ControllerContactStore implements ContactStore
{
    /** @var array<int, Contact> */
    public array $contacts = [];

    public function findAll(): array
    {
        return array_values($this->contacts);
    }

    public function findById(int $id): ?Contact
    {
        return $this->contacts[$id] ?? null;
    }

    public function create(string $name, string $phoneNumber, ?string $note): Contact
    {
        $id = count($this->contacts) + 1;

        return $this->contacts[$id] = new Contact($id, $name, $phoneNumber, $note, null);
    }

    public function update(int $id, string $name, string $phoneNumber, ?string $note): void
    {
        $contact = $this->contacts[$id];
        $this->contacts[$id] = new Contact($id, $name, $phoneNumber, $note, $contact->archivedAt);
    }

    public function setArchived(int $id, bool $archived): void
    {
        $contact = $this->contacts[$id];
        $this->contacts[$id] = new Contact(
            $id,
            $contact->name,
            $contact->phoneNumber,
            $contact->note,
            $archived ? new DateTimeImmutable() : null,
        );
    }

    public function delete(int $id): void
    {
        unset($this->contacts[$id]);
    }
}

final class ControllerContactPermissionResolver implements PermissionResolver
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

final class ControllerContactAuditLogger implements AuditLogger
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

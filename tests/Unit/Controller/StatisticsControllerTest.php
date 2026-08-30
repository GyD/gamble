<?php

declare(strict_types=1);

namespace Tests\Unit\Controller;

use App\Controller\StatisticsController;
use App\Domain\Contact\Contact;
use App\Domain\User\User;
use App\Domain\User\UserStatus;
use App\Repository\ContactStore;
use App\Repository\StatisticsStore;
use App\Security\AuthorizationService;
use App\Security\PermissionResolver;
use App\Service\StatisticsService;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

final class StatisticsControllerTest extends TestCase
{
    public function testLeaderboardCoversEveryBet(): void
    {
        $store = new ControllerStatisticsStore();
        $response = $this->controller($store)->index($this->request('/statistics'), new Response());

        self::assertTrue($store->wasQueried);
        self::assertStringContainsString('Tous les paris', (string)$response->getBody());
    }

    public function testContactStatisticsReturnNotFoundForUnknownContact(): void
    {
        $response = $this->controller(new ControllerStatisticsStore())->contact(
            $this->request('/statistics/contacts/99'),
            new Response(),
            ['id' => '99'],
        );

        self::assertSame(404, $response->getStatusCode());
    }

    private function controller(StatisticsStore $store): StatisticsController
    {
        $permissions = new class implements PermissionResolver {
            public function effectFor(int $userId, string $permission): ?string
            {
                return 'allow';
            }
        };

        return new StatisticsController(
            new StatisticsService($store),
            new ControllerStatisticsContactStore(),
            new AuthorizationService($permissions),
            new Environment(new FilesystemLoader(dirname(__DIR__, 3) . '/templates')),
        );
    }

    private function request(string $uri): ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest('GET', $uri)
            ->withAttribute('user', new User(42, '123', 'bookmaker', 'Bookmaker', null, UserStatus::Active));
    }
}

final class ControllerStatisticsStore implements StatisticsStore
{
    public bool $wasQueried = false;
    public function settledContactBets(?DateTimeImmutable $from, ?int $contactId = null): array
    {
        $this->wasQueried = true;
        return [];
    }
    public function betStakes(int $betId): array { return []; }
}

final class ControllerStatisticsContactStore implements ContactStore
{
    public function findAll(): array { return []; }
    public function findById(int $id): ?Contact { return null; }
    public function create(string $name, string $phoneNumber, ?string $note): Contact { throw new \LogicException(); }
    public function update(int $id, string $name, string $phoneNumber, ?string $note): void { throw new \LogicException(); }
    public function setArchived(int $id, bool $archived): void { throw new \LogicException(); }
    public function delete(int $id): void { throw new \LogicException(); }
}

<?php

declare(strict_types=1);

namespace Tests\Unit\Controller;

use App\Controller\BetController;
use App\Domain\Bet\Bet;
use App\Domain\Bet\BetStatus;
use App\Domain\User\User;
use App\Domain\User\UserStatus;
use App\Repository\AuditLogger;
use App\Repository\BetStore;
use App\Repository\StakeStore;
use App\Repository\StatisticsStore;
use App\Domain\Stake\Stake;
use App\Security\AuthorizationService;
use App\Security\PermissionResolver;
use App\Service\BetService;
use App\Service\StatisticsService;
use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

final class BetControllerTest extends TestCase
{
    public function testIndexOnlyShowsOwnedBetsWithoutViewAllPermission(): void
    {
        $store = new ControllerBetStore();
        $store->bets[1] = $this->bet(1, 1, 'Mine');
        $store->bets[2] = $this->bet(2, 2, 'Theirs');

        $response = $this->controller($store, false)->index($this->request('GET'), new Response());
        $html = (string) $response->getBody();

        self::assertStringContainsString('Mine', $html);
        self::assertStringNotContainsString('Theirs', $html);
    }

    public function testIndexShowsAllBetsWithViewAllPermission(): void
    {
        $store = new ControllerBetStore();
        $store->bets[1] = $this->bet(1, 1, 'Mine');
        $store->bets[2] = $this->bet(2, 2, 'Theirs');

        $html = (string) $this->controller($store, true)->index($this->request('GET'), new Response())->getBody();

        self::assertStringContainsString('Mine', $html);
        self::assertStringContainsString('Theirs', $html);
    }

    public function testIndexShowsLinkToBetStakes(): void
    {
        $store = new ControllerBetStore();
        $store->bets[1] = $this->bet(1, 1, 'Mine');

        $html = (string) $this->controller($store, false)->index($this->request('GET'), new Response())->getBody();

        self::assertStringContainsString('Voir les mises', $html);
        self::assertStringContainsString('/bets/1/stakes', $html);
    }

    public function testCreateRedirectsAndCreatesOpenBet(): void
    {
        $store = new ControllerBetStore();
        $response = $this->controller($store, false)->create(
            $this->request('POST', ['question' => 'Winner?', 'description' => '', 'closes_at' => '', 'options' => ['Blue', 'Red']]),
            new Response(),
        );

        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/bets?saved=1', $response->getHeaderLine('Location'));
        self::assertSame(BetStatus::Open, $store->bets[1]->status);
    }

    public function testOtherUsersBetCannotBeUpdated(): void
    {
        $store = new ControllerBetStore();
        $store->bets[1] = $this->bet(1, 2, 'Theirs');

        $response = $this->controller($store, false)->update(
            $this->request('POST', ['question' => 'Changed', 'description' => '', 'closes_at' => '', 'options' => ['A', 'B']]),
            new Response(),
            ['id' => '1'],
        );

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('Theirs', $store->bets[1]->question);
    }

    public function testOwnerCanDeleteCancelledBet(): void
    {
        $store = new ControllerBetStore();
        $store->bets[1] = new Bet(1, 1, 'Cancelled', null, null, BetStatus::Cancelled, null, []);

        $response = $this->controller($store, false)->delete(
            $this->request('POST'),
            new Response(),
            ['id' => '1'],
        );

        self::assertSame(303, $response->getStatusCode());
        self::assertNull($store->findById(1));
    }

    private function controller(ControllerBetStore $store, bool $viewAll): BetController
    {
        $stakes = new ControllerBetStakeStore();
        $permissions = new class($viewAll) implements PermissionResolver {
            public function __construct(private readonly bool $viewAll) {}
            public function effectFor(int $userId, string $permission): ?string
            {
                return $permission === 'bets.view_all' && !$this->viewAll ? null : 'allow';
            }
        };

        return new BetController(
            $store,
            new BetService(new PDO('sqlite::memory:'), $store, $stakes, new ControllerBetAuditLogger()),
            new AuthorizationService($permissions),
            new Environment(new FilesystemLoader(dirname(__DIR__, 3) . '/templates')),
            new StatisticsService(new ControllerBetStatisticsStore()),
        );
    }

    /** @param array<string, mixed> $body */
    private function request(string $method, array $body = []): ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest($method, '/bets')
            ->withParsedBody($body)
            ->withAttribute('user', new User(1, '1', 'bookmaker', 'Bookmaker', null, UserStatus::Active))
            ->withAttribute('csrf_name', 'name-value')
            ->withAttribute('csrf_value', 'value-value');
    }

    private function bet(int $id, int $ownerUserId, string $question): Bet
    {
        return new Bet($id, $ownerUserId, $question, null, null, BetStatus::Open, null, []);
    }
}

final class ControllerBetStore implements BetStore
{
    /** @var array<int, Bet> */
    public array $bets = [];
    public function findAll(): array { return array_values($this->bets); }
    public function findByOwner(int $ownerUserId): array { return array_values(array_filter($this->bets, static fn(Bet $bet): bool => $bet->ownerUserId === $ownerUserId)); }
    public function findById(int $id): ?Bet { return $this->bets[$id] ?? null; }
    public function create(int $ownerUserId, string $question, ?string $description, ?DateTimeImmutable $closesAt, array $options): Bet
    {
        $id = count($this->bets) + 1;
        return $this->bets[$id] = new Bet($id, $ownerUserId, $question, $description, $closesAt, BetStatus::Open, null, []);
    }
    public function update(int $id, string $question, ?string $description, ?DateTimeImmutable $closesAt, array $options): Bet
    {
        $bet = $this->bets[$id];
        return $this->bets[$id] = new Bet($id, $bet->ownerUserId, $question, $description, $closesAt, $bet->status, null, []);
    }
    public function changeStatus(int $id, BetStatus $status, ?int $winningOptionId): Bet
    {
        $bet = $this->bets[$id];
        return $this->bets[$id] = new Bet($id, $bet->ownerUserId, $bet->question, $bet->description, $bet->closesAt, $status, $winningOptionId, $bet->options);
    }
    public function delete(int $id): void { unset($this->bets[$id]); }
}

final class ControllerBetStakeStore implements StakeStore
{
    public function findByBet(int $betId): array { return []; }
    public function findById(int $id): ?Stake { return null; }
    public function create(int $betId, int $betOptionId, int $contactId, int $amountCents): Stake { throw new \LogicException(); }
    public function update(int $id, int $betOptionId, int $contactId, int $amountCents): Stake { throw new \LogicException(); }
    public function setPaid(int $id, bool $isPaid): Stake { throw new \LogicException(); }
    public function setCancelled(int $id, bool $isCancelled): Stake { throw new \LogicException(); }
    public function delete(int $id): void { throw new \LogicException(); }
}

final class ControllerBetAuditLogger implements AuditLogger
{
    public function record(int $actorUserId, string $action, string $entityType, string $entityId, ?array $before, ?array $after, ?string $ipAddress): void {}
}

final class ControllerBetStatisticsStore implements StatisticsStore
{
    public function settledContactBets(?int $ownerUserId, ?DateTimeImmutable $from, ?int $contactId = null): array
    {
        return [];
    }

    public function betStakes(int $betId): array
    {
        return [];
    }
}
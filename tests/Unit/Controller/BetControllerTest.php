<?php

declare(strict_types=1);

namespace Tests\Unit\Controller;

use App\Controller\BetController;
use App\Domain\Bet\Bet;
use App\Domain\Bet\BetOption;
use App\Domain\Bet\BetStatus;
use App\Domain\User\User;
use App\Domain\User\UserStatus;
use App\Repository\AuditLogger;
use App\Repository\BetStore;
use App\Repository\ContactStore;
use App\Repository\StakeStore;
use App\Repository\StatisticsStore;
use App\Domain\Stake\Stake;
use App\Security\AuthorizationService;
use App\Security\PermissionResolver;
use App\Service\BetService;
use App\Service\StatisticsService;
use App\Service\StakeService;
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
    public function testSettledBetShowsWinnersAndExactPayouts(): void
    {
        $store = new ControllerBetStore();
        $store->bets[1] = new Bet(1, 1, 'Settled', null, null, BetStatus::Settled, 10, []);
        $stakes = new ControllerBetStakeStore();
        $stakes->winners = [
            ['contact_id' => 20, 'contact_name' => 'Alice', 'winning_stake' => 100, 'payout' => 333, 'is_winnings_paid' => false],
            ['contact_id' => 21, 'contact_name' => 'Bob', 'winning_stake' => 200, 'payout' => 667, 'is_winnings_paid' => true],
        ];

        $html = (string)$this->controller($store, $stakes)
            ->show($this->request('GET'), new Response(), ['id' => '1'])->getBody();

        self::assertStringContainsString('Gagnants', $html);
        self::assertStringContainsString('333 $', $html);
        self::assertStringContainsString('667 $', $html);
        self::assertStringContainsString('Gain versé', $html);
    }

    public function testWinningsActionsRequireStakesEditPermission(): void
    {
        $store = new ControllerBetStore();
        $store->bets[1] = new Bet(1, 2, 'Theirs', null, null, BetStatus::Settled, 10, []);
        $stakes = new ControllerBetStakeStore();
        $stakes->winners = [
            ['contact_id' => 20, 'contact_name' => 'Alice', 'winning_stake' => 100, 'payout' => 333, 'is_winnings_paid' => false],
        ];

        $withoutStakesEdit = (string)$this->controller($store, $stakes, stakesEdit: false)
            ->show($this->request('GET'), new Response(), ['id' => '1'])->getBody();
        $withStakesEdit = (string)$this->controller($store, $stakes)
            ->show($this->request('GET'), new Response(), ['id' => '1'])->getBody();

        self::assertStringNotContainsString('/bets/1/winners/20/payment-status', $withoutStakesEdit);
        self::assertStringContainsString('/bets/1/winners/20/payment-status', $withStakesEdit);
    }

    public function testIndexShowsEveryBetWhateverTheOwner(): void
    {
        $store = new ControllerBetStore();
        $store->bets[1] = $this->bet(1, 1, 'Mine');
        $store->bets[2] = $this->bet(2, 2, 'Theirs');

        $html = (string) $this->controller($store)->index($this->request('GET'), new Response())->getBody();

        self::assertStringContainsString('Mine', $html);
        self::assertStringContainsString('Theirs', $html);
    }

    public function testIndexShowsCalculatedOddsForEachOption(): void
    {
        $store = new ControllerBetStore();
        $store->bets[1] = new Bet(1, 1, 'Mine', null, null, BetStatus::Open, null, [
            new BetOption(10, 'Blue', 1),
            new BetOption(11, 'Red', 2),
        ]);
        $stakes = new ControllerBetStakeStore();
        $stakes->stakes = [
            new Stake(1, 1, 10, 20, 1000, 'Alice', 'Blue', false, true),
            new Stake(2, 1, 11, 21, 2000, 'Bob', 'Red', false, true),
        ];

        $html = (string) $this->controller($store, $stakes)
            ->index($this->request('GET'), new Response())->getBody();

        self::assertStringContainsString('Blue — cote 2,70', $html);
        self::assertStringContainsString('Red — cote 1,35', $html);
    }

    public function testIndexShowsLinkToBetStakes(): void
    {
        $store = new ControllerBetStore();
        $store->bets[1] = $this->bet(1, 1, 'Mine');

        $html = (string) $this->controller($store)->index($this->request('GET'), new Response())->getBody();

        self::assertStringContainsString('Voir les mises', $html);
        self::assertStringContainsString('/bets/1/stakes', $html);
    }

    public function testCreateRedirectsAndCreatesOpenBet(): void
    {
        $store = new ControllerBetStore();
        $response = $this->controller($store)->create(
            $this->request('POST', ['question' => 'Winner?', 'description' => '', 'closes_at' => '', 'options' => ['Blue', 'Red']]),
            new Response(),
        );

        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/bets?saved=1', $response->getHeaderLine('Location'));
        self::assertSame(BetStatus::Open, $store->bets[1]->status);
    }

    public function testAnotherUsersBetCanBeShownAndEdited(): void
    {
        $store = new ControllerBetStore();
        $store->bets[1] = $this->bet(1, 2, 'Theirs');
        $controller = $this->controller($store);

        $editResponse = $controller->edit($this->request('GET'), new Response(), ['id' => '1']);
        $showHtml = (string) $controller->show($this->request('GET'), new Response(), ['id' => '1'])->getBody();
        $updateResponse = $controller->update(
            $this->request('POST', ['question' => 'Changed', 'description' => '', 'closes_at' => '', 'options' => ['A', 'B']]),
            new Response(),
            ['id' => '1'],
        );

        self::assertSame(200, $editResponse->getStatusCode());
        self::assertStringContainsString('/bets/1/edit', $showHtml);
        self::assertSame(303, $updateResponse->getStatusCode());
        self::assertSame('Changed', $store->bets[1]->question);
    }

    public function testAnotherUsersBetCanBeClosedAndSettled(): void
    {
        $store = new ControllerBetStore();
        $store->bets[1] = new Bet(1, 2, 'Theirs', null, null, BetStatus::Open, null, [
            new BetOption(10, 'Blue', 0),
            new BetOption(11, 'Red', 1),
        ]);
        $controller = $this->controller($store);

        $closeResponse = $controller->close($this->request('POST'), new Response(), ['id' => '1']);
        $settleResponse = $controller->settle(
            $this->request('POST', ['winning_option_id' => '11']),
            new Response(),
            ['id' => '1'],
        );

        self::assertSame(303, $closeResponse->getStatusCode());
        self::assertSame(303, $settleResponse->getStatusCode());
        self::assertSame(BetStatus::Settled, $store->bets[1]->status);
        self::assertSame(11, $store->bets[1]->winningOptionId);
    }

    public function testAnotherUsersBetCanBeCancelledAndDeleted(): void
    {
        $store = new ControllerBetStore();
        $store->bets[1] = $this->bet(1, 2, 'Theirs');
        $controller = $this->controller($store);

        $cancelResponse = $controller->cancel($this->request('POST'), new Response(), ['id' => '1']);
        $deleteResponse = $controller->delete($this->request('POST'), new Response(), ['id' => '1']);

        self::assertSame(303, $cancelResponse->getStatusCode());
        self::assertSame(303, $deleteResponse->getStatusCode());
        self::assertNull($store->findById(1));
    }

    public function testEditLocksOptionsButKeepsQuestionAndDeadlineEditableWhenStakeExists(): void
    {
        $store = new ControllerBetStore();
        $store->bets[1] = new Bet(1, 1, 'Winner?', null, null, BetStatus::Open, null, [
            new BetOption(10, 'Blue', 0),
            new BetOption(11, 'Red', 1),
        ]);
        $stakes = new ControllerBetStakeStore();
        $stakes->stakes[] = new Stake(1, 1, 10, 20, 1000, 'Alice', 'Blue', false, false);

        $html = (string) $this->controller($store, $stakes)
            ->edit($this->request('GET'), new Response(), ['id' => '1'])->getBody();

        self::assertStringContainsString('name="question"', $html);
        self::assertStringContainsString('name="closes_at"', $html);
        self::assertSame(2, substr_count($html, 'name="options[]"'));
        self::assertSame(2, substr_count($html, ' readonly'));
        self::assertStringNotContainsString('Ajouter un choix', $html);
        self::assertStringContainsString('les choix ne peuvent plus être modifiés', $html);
    }

    public function testCancelledBetCanBeDeleted(): void
    {
        $store = new ControllerBetStore();
        $store->bets[1] = new Bet(1, 1, 'Cancelled', null, null, BetStatus::Cancelled, null, []);

        $response = $this->controller($store)->delete(
            $this->request('POST'),
            new Response(),
            ['id' => '1'],
        );

        self::assertSame(303, $response->getStatusCode());
        self::assertNull($store->findById(1));
    }

    private function controller(
        ControllerBetStore $store,
        ?ControllerBetStakeStore $stakes = null,
        bool $stakesEdit = true,
    ): BetController
    {
        $stakes ??= new ControllerBetStakeStore();
        $permissions = new class($stakesEdit) implements PermissionResolver {
            public function __construct(private readonly bool $stakesEdit) {}
            public function effectFor(int $userId, string $permission): ?string
            {
                return match ($permission) {
                    'stakes.edit' => $this->stakesEdit ? 'allow' : null,
                    default => 'allow',
                };
            }
        };

        return new BetController(
            $store,
            new BetService(new PDO('sqlite::memory:'), $store, $stakes, new ControllerBetAuditLogger()),
            new AuthorizationService($permissions),
            new Environment(new FilesystemLoader(dirname(__DIR__, 3) . '/templates')),
            new StatisticsService(new ControllerBetStatisticsStore()),
            new StakeService(new PDO('sqlite::memory:'), $stakes, $store, new ControllerBetContactStore(), new ControllerBetAuditLogger()),
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
    public function findById(int $id): ?Bet { return $this->bets[$id] ?? null; }
    public function findByIdForUpdate(int $id): ?Bet { return $this->findById($id); }
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
    public function setBookmakerRate(int $id, int $rateBps): Bet { throw new \LogicException(); }
    public function settleFinancials(int $id, int $winningOptionId, int $pot, int $bookmakerShare, int $redistributed, array $oddsByOptionId): Bet
    {
        return $this->changeStatus($id, BetStatus::Settled, $winningOptionId);
    }
    public function delete(int $id): void { unset($this->bets[$id]); }
}

final class ControllerBetStakeStore implements StakeStore
{
    /** @var list<Stake> */
    public array $stakes = [];
    /** @var list<array{contact_id: int, contact_name: string, winning_stake: int, payout: int, is_winnings_paid: bool}> */
    public array $winners = [];
    public function findByBet(int $betId): array { return array_values(array_filter($this->stakes, static fn(Stake $stake): bool => $stake->betId === $betId)); }
    public function findById(int $id): ?Stake { return null; }
    public function create(int $betId, int $betOptionId, int $contactId, int $amount): Stake { throw new \LogicException(); }
    public function update(int $id, int $betOptionId, int $contactId, int $amount): Stake { throw new \LogicException(); }
    public function setPaid(int $id, bool $isPaid): Stake { throw new \LogicException(); }
    public function setCancelled(int $id, bool $isCancelled): Stake { throw new \LogicException(); }
    public function setFinalPayouts(int $betId, array $payoutsByStakeId): void {}
    public function findWinnersByBet(int $betId, int $winningOptionId): array { return $this->winners; }
    public function setWinningsPaid(int $betId, int $winningOptionId, int $contactId, bool $isPaid): void { throw new \LogicException(); }
    public function delete(int $id): void { throw new \LogicException(); }
}

final class ControllerBetContactStore implements ContactStore
{
    public function findAll(): array { return []; }
    public function findById(int $id): ?\App\Domain\Contact\Contact { return null; }
    public function create(string $name, string $phoneNumber, ?string $note): \App\Domain\Contact\Contact { throw new \LogicException(); }
    public function update(int $id, string $name, string $phoneNumber, ?string $note): void { throw new \LogicException(); }
    public function setArchived(int $id, bool $archived): void { throw new \LogicException(); }
    public function delete(int $id): void { throw new \LogicException(); }
}

final class ControllerBetAuditLogger implements AuditLogger
{
    public function record(int $actorUserId, string $action, string $entityType, string $entityId, ?array $before, ?array $after, ?string $ipAddress): void {}
}

final class ControllerBetStatisticsStore implements StatisticsStore
{
    public function settledContactBets(?DateTimeImmutable $from, ?int $contactId = null): array
    {
        return [];
    }

    public function betStakes(int $betId): array
    {
        return [];
    }
}
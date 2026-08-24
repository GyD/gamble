<?php

declare(strict_types=1);

namespace Tests\Unit\Controller;

use App\Controller\StakeController;
use App\Domain\Bet\Bet;
use App\Domain\Bet\BetOption;
use App\Domain\Bet\BetStatus;
use App\Domain\Contact\Contact;
use App\Domain\Stake\Stake;
use App\Domain\User\User;
use App\Domain\User\UserStatus;
use App\Repository\AuditLogger;
use App\Repository\BetStore;
use App\Repository\ContactStore;
use App\Repository\StakeStore;
use App\Security\AuthorizationService;
use App\Security\PermissionResolver;
use App\Service\StakeService;
use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

final class StakeControllerTest extends TestCase
{
    private ControllerStakeStore $stakes;
    private ControllerStakeBetStore $bets;
    private ControllerStakeContactStore $contacts;

    protected function setUp(): void
    {
        $this->stakes = new ControllerStakeStore();
        $this->bets = new ControllerStakeBetStore();
        $this->contacts = new ControllerStakeContactStore();
        $this->bets->bets[1] = new Bet(1, 1, 'Winner?', null, null, BetStatus::Open, null, [
            new BetOption(10, 'Blue', 0),
            new BetOption(11, 'Red', 1),
        ]);
        $this->contacts->contacts[20] = new Contact(20, 'Alice', '1234', null, null);
    }

    public function testOwnerCanCreateStakeAndIsRedirected(): void
    {
        $response = $this->controller()->create(
            $this->request('POST', ['contact_id' => '20', 'bet_option_id' => '10', 'amount' => '12.50']),
            new Response(),
            ['id' => '1'],
        );

        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/bets/1/stakes?saved=1', $response->getHeaderLine('Location'));
        self::assertSame(1250, $this->stakes->stakes[1]->amountCents);
    }

    public function testInvalidAmountReturnsBadRequest(): void
    {
        $response = $this->controller()->create(
            $this->request('POST', ['contact_id' => '20', 'bet_option_id' => '10', 'amount' => '0']),
            new Response(),
            ['id' => '1'],
        );

        self::assertSame(400, $response->getStatusCode());
        self::assertSame([], $this->stakes->stakes);
    }

    public function testOwnerCanMarkStakeAsPaid(): void
    {
        $this->stakes->create(1, 10, 20, 1250);

        $response = $this->controller()->setPaid(
            $this->request('POST', ['is_paid' => '1']),
            new Response(),
            ['id' => '1', 'stakeId' => '1'],
        );

        self::assertSame(303, $response->getStatusCode());
        self::assertTrue($this->stakes->stakes[1]->isPaid);
    }

    public function testOwnerCanCancelPaidStake(): void
    {
        $this->stakes->create(1, 10, 20, 1250);
        $this->stakes->setPaid(1, true);

        $response = $this->controller()->setCancelled(
            $this->request('POST', ['is_cancelled' => '1']),
            new Response(),
            ['id' => '1', 'stakeId' => '1'],
        );

        self::assertSame(303, $response->getStatusCode());
        self::assertTrue($this->stakes->stakes[1]->isPaid);
        self::assertTrue($this->stakes->stakes[1]->isCancelled);
    }

    public function testOwnerCanMarkCancelledPaidStakeAsUnpaid(): void
    {
        $this->stakes->create(1, 10, 20, 1250);
        $this->stakes->setPaid(1, true);
        $this->stakes->setCancelled(1, true);

        $response = $this->controller()->setPaid(
            $this->request('POST', ['is_paid' => '0']),
            new Response(),
            ['id' => '1', 'stakeId' => '1'],
        );

        self::assertSame(303, $response->getStatusCode());
        self::assertFalse($this->stakes->stakes[1]->isPaid);
        self::assertTrue($this->stakes->stakes[1]->isCancelled);
    }

    public function testInvalidPaymentStatusReturnsBadRequest(): void
    {
        $this->stakes->create(1, 10, 20, 1250);

        $response = $this->controller()->setPaid(
            $this->request('POST', ['is_paid' => 'yes']),
            new Response(),
            ['id' => '1', 'stakeId' => '1'],
        );

        self::assertSame(400, $response->getStatusCode());
        self::assertFalse($this->stakes->stakes[1]->isPaid);
    }

    public function testOwnerCanMarkPaidStakeAsRefundedOnCancelledBet(): void
    {
        $this->stakes->create(1, 10, 20, 1250);
        $this->stakes->setPaid(1, true);
        $bet = $this->bets->bets[1];
        $this->bets->bets[1] = new Bet($bet->id, $bet->ownerUserId, $bet->question, $bet->description, $bet->closesAt, BetStatus::Cancelled, null, $bet->options);

        $response = $this->controller()->setRefunded(
            $this->request('POST', ['is_refunded' => '1']),
            new Response(),
            ['id' => '1', 'stakeId' => '1'],
        );

        self::assertSame(303, $response->getStatusCode());
        self::assertFalse($this->stakes->stakes[1]->isPaid);
    }

    public function testOwnerCanMarkSettledWinningsPaid(): void
    {
        $bet = $this->bets->bets[1];
        $this->bets->bets[1] = new Bet($bet->id, $bet->ownerUserId, $bet->question, $bet->description, $bet->closesAt, BetStatus::Settled, 10, $bet->options);
        $this->stakes->winners = [
            ['contact_id' => 20, 'contact_name' => 'Alice', 'winning_stake_cents' => 100, 'pot_cents' => 100, 'is_winnings_paid' => false],
        ];

        $response = $this->controller()->setWinningsPaid(
            $this->request('POST', ['is_paid' => '1']),
            new Response(),
            ['id' => '1', 'contactId' => '20'],
        );

        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/bets/1?saved=1', $response->getHeaderLine('Location'));
        self::assertTrue($this->stakes->winners[0]['is_winnings_paid']);
    }

    public function testOtherUsersBetCannotBeChanged(): void
    {
        $this->bets->bets[1] = new Bet(1, 2, 'Theirs', null, null, BetStatus::Open, null, [new BetOption(10, 'Blue', 0)]);

        $response = $this->controller()->create(
            $this->request('POST', ['contact_id' => '20', 'bet_option_id' => '10', 'amount' => '1']),
            new Response(),
            ['id' => '1'],
        );

        self::assertSame(403, $response->getStatusCode());
    }

    public function testOtherUsersBetCanOnlyBeReadWithViewAllPermission(): void
    {
        $this->bets->bets[1] = new Bet(1, 2, 'Theirs', null, null, BetStatus::Open, null, []);

        $forbidden = $this->controller(false)->index($this->request('GET'), new Response(), ['id' => '1']);
        $allowed = $this->controller(true)->index($this->request('GET'), new Response(), ['id' => '1']);

        self::assertSame(403, $forbidden->getStatusCode());
        self::assertSame(200, $allowed->getStatusCode());
        self::assertStringContainsString('Theirs', (string)$allowed->getBody());
    }

    private function controller(bool $viewAll = false): StakeController
    {
        $permissions = new class($viewAll) implements PermissionResolver {
            public function __construct(private readonly bool $viewAll)
            {
            }

            public function effectFor(int $userId, string $permission): ?string
            {
                return $permission === 'bets.view_all' && !$this->viewAll ? null : 'allow';
            }
        };

        return new StakeController(
            $this->bets,
            $this->stakes,
            $this->contacts,
            new StakeService(new PDO('sqlite::memory:'), $this->stakes, $this->bets, $this->contacts, new ControllerStakeAuditLogger()),
            new AuthorizationService($permissions),
            new Environment(new FilesystemLoader(dirname(__DIR__, 3) . '/templates')),
        );
    }

    /** @param array<string, mixed> $body */
    private function request(string $method, array $body = []): ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest($method, '/bets/1/stakes')
            ->withParsedBody($body)
            ->withAttribute('user', new User(1, '1', 'bookmaker', 'Bookmaker', null, UserStatus::Active))
            ->withAttribute('csrf_name', 'name-value')
            ->withAttribute('csrf_value', 'value-value');
    }
}

final class ControllerStakeStore implements StakeStore
{
    /** @var array<int, Stake> */
    public array $stakes = [];
    /** @var list<array{contact_id: int, contact_name: string, winning_stake_cents: int, pot_cents: int, is_winnings_paid: bool}> */
    public array $winners = [];

    public function findByBet(int $betId): array
    {
        return array_values(array_filter($this->stakes, static fn(Stake $stake): bool => $stake->betId === $betId));
    }

    public function findById(int $id): ?Stake
    {
        return $this->stakes[$id] ?? null;
    }

    public function create(int $betId, int $betOptionId, int $contactId, int $amountCents): Stake
    {
        $id = count($this->stakes) + 1;
        return $this->stakes[$id] = new Stake($id, $betId, $betOptionId, $contactId, $amountCents, 'Alice', 'Blue', false, false);
    }

    public function update(int $id, int $betOptionId, int $contactId, int $amountCents): Stake
    {
        $stake = $this->stakes[$id];
        return $this->stakes[$id] = new Stake($id, $stake->betId, $betOptionId, $contactId, $amountCents, 'Alice', 'Blue', false, $stake->isPaid, $stake->isCancelled);
    }

    public function setPaid(int $id, bool $isPaid): Stake
    {
        $stake = $this->stakes[$id];

        return $this->stakes[$id] = new Stake($stake->id, $stake->betId, $stake->betOptionId, $stake->contactId, $stake->amountCents, $stake->contactName, $stake->optionLabel, $stake->contactArchived, $isPaid, $stake->isCancelled);
    }

    public function setCancelled(int $id, bool $isCancelled): Stake
    {
        $stake = $this->stakes[$id];

        return $this->stakes[$id] = new Stake($stake->id, $stake->betId, $stake->betOptionId, $stake->contactId, $stake->amountCents, $stake->contactName, $stake->optionLabel, $stake->contactArchived, $stake->isPaid, $isCancelled);
    }

    public function findWinnersByBet(int $betId, int $winningOptionId): array
    {
        return $this->winners;
    }

    public function setWinningsPaid(int $betId, int $winningOptionId, int $contactId, bool $isPaid): void
    {
        foreach ($this->winners as $index => $winner) {
            if ($winner['contact_id'] === $contactId) {
                $this->winners[$index]['is_winnings_paid'] = $isPaid;
                return;
            }
        }
        throw new \RuntimeException('Unknown winner.');
    }

    public function delete(int $id): void
    {
        unset($this->stakes[$id]);
    }
}

final class ControllerStakeBetStore implements BetStore
{
    /** @var array<int, Bet> */
    public array $bets = [];

    public function findAll(): array
    {
        return array_values($this->bets);
    }

    public function findByOwner(int $ownerUserId): array
    {
        return array_values(array_filter($this->bets, static fn(Bet $bet): bool => $bet->ownerUserId === $ownerUserId));
    }

    public function findById(int $id): ?Bet
    {
        return $this->bets[$id] ?? null;
    }

    public function create(int $ownerUserId, string $question, ?string $description, ?DateTimeImmutable $closesAt, array $options): Bet
    {
        throw new \LogicException();
    }

    public function update(int $id, string $question, ?string $description, ?DateTimeImmutable $closesAt, array $options): Bet
    {
        throw new \LogicException();
    }

    public function changeStatus(int $id, BetStatus $status, ?int $winningOptionId): Bet
    {
        throw new \LogicException();
    }
    public function delete(int $id): void { throw new \LogicException(); }
}

final class ControllerStakeContactStore implements ContactStore
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
        throw new \LogicException();
    }

    public function update(int $id, string $name, string $phoneNumber, ?string $note): void
    {
        throw new \LogicException();
    }

    public function setArchived(int $id, bool $archived): void
    {
        throw new \LogicException();
    }

    public function delete(int $id): void
    {
        throw new \LogicException();
    }
}

final class ControllerStakeAuditLogger implements AuditLogger
{
    public function record(int $actorUserId, string $action, string $entityType, string $entityId, ?array $before, ?array $after, ?string $ipAddress): void
    {
    }
}
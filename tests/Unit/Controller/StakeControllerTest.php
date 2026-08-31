<?php

declare(strict_types=1);

namespace Tests\Unit\Controller;

use App\Controller\StakeController;
use App\Domain\Bet\Bet;
use App\Domain\Bet\BetOption;
use App\Domain\Bet\BetStatus;
use App\Domain\Bet\BettingMode;
use App\Domain\Bet\OddsEvolutionMode;
use App\Domain\Contact\Contact;
use App\Domain\Group\Group;
use App\Domain\Stake\Stake;
use App\Domain\User\User;
use App\Domain\User\UserStatus;
use App\Repository\AuditLogger;
use App\Repository\BetStore;
use App\Repository\ContactStore;
use App\Repository\GroupStore;
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
    private ControllerStakeGroupStore $groups;

    protected function setUp(): void
    {
        $this->stakes = new ControllerStakeStore();
        $this->bets = new ControllerStakeBetStore();
        $this->contacts = new ControllerStakeContactStore();
        $this->bets->bets[1] = new Bet(1, 1, 'Winner?', null, null, BetStatus::Open, null, [
            new BetOption(10, 'Blue', 0, 2.00, 2.00),
            new BetOption(11, 'Red', 1, 2.00, 2.00),
        ]);
        $this->contacts->contacts[20] = new Contact(20, 'Alice', '1234', null, null);
        $this->groups = new ControllerStakeGroupStore();
        $this->groups->groups[30] = new Group(30, 'Amis', null, null);
        $this->groups->groups[31] = new Group(31, 'Anciens', null, new DateTimeImmutable());
        $this->groups->memberships[20] = [30, 31];
    }

    public function testOwnerCanCreateStakeAndIsRedirected(): void
    {
        $response = $this->controller()->create(
            $this->request('POST', ['contact_id' => '20', 'bet_option_id' => '10', 'amount' => '12']),
            new Response(),
            ['id' => '1'],
        );

        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/bets/1/stakes?saved=1', $response->getHeaderLine('Location'));
        self::assertSame(12, $this->stakes->stakes[1]->amount);
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

    public function testDecimalAmountReturnsBadRequest(): void
    {
        $response = $this->controller()->create(
            $this->request('POST', ['contact_id' => '20', 'bet_option_id' => '10', 'amount' => '12.00']),
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
            ['contact_id' => 20, 'contact_name' => 'Alice', 'winning_stake' => 100, 'payout' => 100, 'is_winnings_paid' => false],
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

    public function testAnotherOwnersStakesCanBeManaged(): void
    {
        $this->bets->bets[1] = new Bet(1, 2, 'Theirs', null, null, BetStatus::Open, null, [new BetOption(10, 'Blue', 0, 2.00, 2.00)]);
        $controller = $this->controller();

        $created = $controller->create(
            $this->request('POST', ['contact_id' => '20', 'bet_option_id' => '10', 'amount' => '15']),
            new Response(),
            ['id' => '1'],
        );
        $paid = $controller->setPaid(
            $this->request('POST', ['is_paid' => '1']),
            new Response(),
            ['id' => '1', 'stakeId' => '1'],
        );
        $body = (string)$controller->index($this->request('GET'), new Response(), ['id' => '1'])->getBody();

        self::assertSame(303, $created->getStatusCode());
        self::assertSame(303, $paid->getStatusCode());
        self::assertSame(15, $this->stakes->stakes[1]->amount);
        self::assertTrue($this->stakes->stakes[1]->isPaid);
        self::assertStringContainsString('Ajouter une mise', $body);
    }

    public function testAnotherOwnersBetCanBeRead(): void
    {
        $this->bets->bets[1] = new Bet(1, 2, 'Theirs', null, null, BetStatus::Open, null, []);

        $response = $this->controller()->index($this->request('GET'), new Response(), ['id' => '1']);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Theirs', (string)$response->getBody());
    }

    public function testGroupsAreDisplayedWhenCreatingEditingAndReadingStakes(): void
    {
        $this->stakes->create(1, 10, 20, 1250);

        $editableResponse = $this->controller()->index($this->request('GET'), new Response(), ['id' => '1']);
        $editableBody = (string)$editableResponse->getBody();

        self::assertSame(200, $editableResponse->getStatusCode());
        self::assertGreaterThanOrEqual(2, substr_count($editableBody, 'Groupes : Amis, Anciens'));

        $bet = $this->bets->bets[1];
        $this->bets->bets[1] = new Bet($bet->id, $bet->ownerUserId, $bet->question, $bet->description, $bet->closesAt, BetStatus::Closed, null, $bet->options);

        $readOnlyResponse = $this->controller()->index($this->request('GET'), new Response(), ['id' => '1']);

        self::assertSame(200, $readOnlyResponse->getStatusCode());
        self::assertStringContainsString('Groupes : Amis, Anciens', (string)$readOnlyResponse->getBody());
    }

    public function testNoGroupLabelIsDisplayedWhenContactHasNoGroup(): void
    {
        $this->groups->memberships = [];
        $this->stakes->create(1, 10, 20, 1250);

        $editableBody = (string)$this->controller()->index($this->request('GET'), new Response(), ['id' => '1'])->getBody();

        self::assertStringNotContainsString('Groupes :', $editableBody);
        self::assertStringNotContainsString('Sans groupe', $editableBody);
        self::assertStringContainsString('>Alice · 1234</option>', $editableBody);

        $bet = $this->bets->bets[1];
        $this->bets->bets[1] = new Bet($bet->id, $bet->ownerUserId, $bet->question, $bet->description, $bet->closesAt, BetStatus::Closed, null, $bet->options);

        $readOnlyBody = (string)$this->controller()->index($this->request('GET'), new Response(), ['id' => '1'])->getBody();

        self::assertStringNotContainsString('Groupes :', $readOnlyBody);
        self::assertStringNotContainsString('Sans groupe', $readOnlyBody);
    }

    public function testSummarySeparatesPaidAndUnpaidStakesAndExcludesCancelledStakes(): void
    {
        $this->stakes->stakes = [
            1 => new Stake(1, 1, 10, 20, 1000, 'Alice', 'Blue', false, true),
            2 => new Stake(2, 1, 11, 20, 2000, 'Alice', 'Red', false, false),
            3 => new Stake(3, 1, 10, 20, 4000, 'Alice', 'Blue', false, true, true),
            4 => new Stake(4, 1, 11, 20, 8000, 'Alice', 'Red', false, false, true),
        ];

        $body = (string)$this->controller()->index($this->request('GET'), new Response(), ['id' => '1'])->getBody();

        self::assertStringContainsString('<strong>1</strong><span>Mise payée</span>', $body);
        self::assertStringContainsString('<strong>1 000 $</strong><span>Pot payé</span>', $body);
        self::assertStringContainsString('<strong>1</strong><span>Mise non payée</span>', $body);
        self::assertStringContainsString('<strong>2 000 $</strong><span>Pot non payé</span>', $body);
    }

    public function testUnpaidStakeShowsTheAnnouncedOddsAndAnEstimatedPayout(): void
    {
        // Announced at 3.00 but now offered at 2.00: the projection follows the
        // current price, since nothing is contracted yet.
        $this->stakes->stakes = [
            1 => new Stake(1, 1, 10, 20, 1000, 'Alice', 'Blue', false, false, false, null, null, null, 3.00),
        ];

        $body = (string)$this->controller()->index($this->request('GET'), new Response(), ['id' => '1'])->getBody();

        self::assertStringContainsString('Cote annoncée à la prise : 3,00', $body);
        self::assertStringContainsString('Cote actuellement proposée : 2,00', $body);
        self::assertStringContainsString('gain estimé si encaissée maintenant et gagnante : 2 000 $', $body);
        self::assertStringNotContainsString('Cote contractuelle', $body);
    }

    public function testPaidStakeShowsItsContractualOddsAndAGuaranteedPayout(): void
    {
        $this->stakes->stakes = [
            1 => new Stake(1, 1, 10, 20, 1000, 'Alice', 'Blue', false, true, false, null, 3.00, null, 3.00),
        ];

        $body = (string)$this->controller()->index($this->request('GET'), new Response(), ['id' => '1'])->getBody();

        self::assertStringContainsString('Cote contractuelle : 3,00', $body);
        self::assertStringContainsString('Gain garanti si gagnant : 3 000 $', $body);
        // No projection on a paid stake: its debt is contracted.
        self::assertStringNotContainsString('gain estimé si encaissée', $body);
    }

    private function controller(): StakeController
    {
        $permissions = new class implements PermissionResolver {
            public function effectFor(int $userId, string $permission): ?string
            {
                return 'allow';
            }
        };

        return new StakeController(
            $this->bets,
            $this->stakes,
            $this->contacts,
            $this->groups,
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
    /** @var list<array{contact_id: int, contact_name: string, winning_stake: int, payout: int, is_winnings_paid: bool}> */
    public array $winners = [];

    public function findByBet(int $betId): array
    {
        return array_values(array_filter($this->stakes, static fn(Stake $stake): bool => $stake->betId === $betId));
    }

    public function findById(int $id): ?Stake
    {
        return $this->stakes[$id] ?? null;
    }

    public function findByIdForUpdate(int $id): ?Stake
    {
        return $this->findById($id);
    }

    public function create(int $betId, int $betOptionId, int $contactId, int $amount, ?float $quotedOdds = null): Stake
    {
        $id = count($this->stakes) + 1;
        return $this->stakes[$id] = new Stake($id, $betId, $betOptionId, $contactId, $amount, 'Alice', 'Blue', false, false, false, null, null, new DateTimeImmutable(), $quotedOdds);
    }

    public function update(int $id, int $betOptionId, int $contactId, int $amount): Stake
    {
        $stake = $this->stakes[$id];
        return $this->stakes[$id] = new Stake($id, $stake->betId, $betOptionId, $contactId, $amount, 'Alice', 'Blue', false, $stake->isPaid, $stake->isCancelled, $stake->finalPayout, $stake->oddsAtBet, $stake->createdAt, $stake->quotedOdds);
    }

    public function captureOddsAtBet(int $id, float $oddsAtBet): Stake
    {
        $stake = $this->stakes[$id];
        // Same guard as the real store: the contract is written only once.
        if ($stake->hasContractualOdds()) {
            return $stake;
        }

        return $this->stakes[$id] = new Stake($stake->id, $stake->betId, $stake->betOptionId, $stake->contactId, $stake->amount, $stake->contactName, $stake->optionLabel, $stake->contactArchived, $stake->isPaid, $stake->isCancelled, $stake->finalPayout, $oddsAtBet, $stake->createdAt, $stake->quotedOdds);
    }

    public function setPaid(int $id, bool $isPaid): Stake
    {
        $stake = $this->stakes[$id];

        return $this->stakes[$id] = new Stake($stake->id, $stake->betId, $stake->betOptionId, $stake->contactId, $stake->amount, $stake->contactName, $stake->optionLabel, $stake->contactArchived, $isPaid, $stake->isCancelled, $stake->finalPayout, $stake->oddsAtBet, $stake->createdAt, $stake->quotedOdds);
    }

    public function setCancelled(int $id, bool $isCancelled): Stake
    {
        $stake = $this->stakes[$id];

        return $this->stakes[$id] = new Stake($stake->id, $stake->betId, $stake->betOptionId, $stake->contactId, $stake->amount, $stake->contactName, $stake->optionLabel, $stake->contactArchived, $stake->isPaid, $isCancelled, $stake->finalPayout, $stake->oddsAtBet, $stake->createdAt, $stake->quotedOdds);
    }

    public function setFinalPayouts(int $betId, array $payoutsByStakeId): void {}

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

    public function findById(int $id): ?Bet
    {
        return $this->bets[$id] ?? null;
    }

    public function findByIdForUpdate(int $id): ?Bet
    {
        return $this->findById($id);
    }

    public function create(
        int $ownerUserId,
        string $question,
        ?string $description,
        ?DateTimeImmutable $closesAt,
        array $options,
        BettingMode $bettingMode = BettingMode::FixedOdds,
        OddsEvolutionMode $oddsEvolutionMode = OddsEvolutionMode::Fixed,
        array $odds = [],
    ): Bet {
        throw new \LogicException();
    }

    public function update(int $id, string $question, ?string $description, ?DateTimeImmutable $closesAt, array $options, array $odds = []): Bet
    {
        throw new \LogicException();
    }

    public function changeStatus(int $id, BetStatus $status, ?int $winningOptionId): Bet
    {
        throw new \LogicException();
    }
    public function setOptionOdds(int $id, array $oddsByOptionId): Bet { throw new \LogicException(); }
    public function setMutuelCommissionRate(int $id, int $rateBps): Bet { throw new \LogicException(); }
    public function setBettingMode(int $id, BettingMode $bettingMode, OddsEvolutionMode $oddsEvolutionMode): Bet { throw new \LogicException(); }
    public function settleFinancials(int $id, int $winningOptionId, int $pot, int $bookmakerShare, int $redistributed, int $bookmakerResult, array $oddsByOptionId): Bet { throw new \LogicException(); }
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

final class ControllerStakeGroupStore implements GroupStore
{
    /** @var array<int, Group> */
    public array $groups = [];
    /** @var array<int, list<int>> */
    public array $memberships = [];

    public function findAll(): array { return array_values($this->groups); }
    public function findById(int $id): ?Group { return $this->groups[$id] ?? null; }
    public function create(string $name, ?string $note): Group { throw new \LogicException(); }
    public function update(int $id, string $name, ?string $note): void { throw new \LogicException(); }
    public function setArchived(int $id, bool $archived): void { throw new \LogicException(); }
    public function delete(int $id): void { throw new \LogicException(); }
    public function findAvailableForContact(int $contactId): array { return []; }
    public function memberGroupIds(int $contactId): array { return $this->memberships[$contactId] ?? []; }
    public function syncContactGroups(int $contactId, array $groupIds): void { $this->memberships[$contactId] = $groupIds; }
}

final class ControllerStakeAuditLogger implements AuditLogger
{
    public function record(int $actorUserId, string $action, string $entityType, string $entityId, ?array $before, ?array $after, ?string $ipAddress): void
    {
    }
}
<?php

declare(strict_types=1);

namespace Tests\Unit\Service;

use App\Domain\Bet\Bet;
use App\Domain\Bet\BetOption;
use App\Domain\Bet\BetStatus;
use App\Repository\AuditLogger;
use App\Repository\BetStore;
use App\Service\BetService;
use DateTimeImmutable;
use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;

final class BetServiceTest extends TestCase
{
    private BetTestStore $bets;
    private BetTestAuditLogger $audit;
    private BetService $service;

    protected function setUp(): void
    {
        $this->bets = new BetTestStore();
        $this->audit = new BetTestAuditLogger();
        $this->service = new BetService(new PDO('sqlite::memory:'), $this->bets, $this->audit);
    }

    public function testBetIsNormalizedCreatedOpenAndAudited(): void
    {
        $bet = $this->service->create(7, '  Winner?  ', '  Final  ', '2026-09-01T20:30', [' Blue ', ' Red '], '127.0.0.1');

        self::assertSame('Winner?', $bet->question);
        self::assertSame('Final', $bet->description);
        self::assertSame(BetStatus::Open, $bet->status);
        self::assertSame(['Blue', 'Red'], array_map(static fn(BetOption $option): string => $option->label, $bet->options));
        self::assertSame('2026-09-01T20:30:00', $bet->closesAt?->format('Y-m-d\TH:i:s'));
        self::assertSame('bet.created', $this->audit->entries[0]['action']);
    }

    public function testAtLeastTwoUniqueOptionsAreRequired(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Bet options must be unique.');

        $this->service->create(7, 'Winner?', null, null, ['Blue', ' blue '], null);
    }

    public function testBetCanBeClosedAndSettledWithOneOfItsOptions(): void
    {
        $bet = $this->service->create(7, 'Winner?', null, null, ['Blue', 'Red'], null);
        $closed = $this->service->close(7, $bet->id, null);
        $settled = $this->service->settle(7, $bet->id, $closed->options[1]->id, null);

        self::assertSame(BetStatus::Settled, $settled->status);
        self::assertSame($closed->options[1]->id, $settled->winningOptionId);
        self::assertSame(['bet.created', 'bet.closed', 'bet.settled'], array_column($this->audit->entries, 'action'));
    }

    public function testOpenBetCanBeCancelledAndFinalStateCannotChange(): void
    {
        $bet = $this->service->create(7, 'Winner?', null, null, ['Blue', 'Red'], null);
        $this->service->cancel(7, $bet->id, null);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Bet must be open to become closed.');
        $this->service->close(7, $bet->id, null);
    }

    public function testOnlyOwnerCanChangeBet(): void
    {
        $bet = $this->service->create(7, 'Winner?', null, null, ['Blue', 'Red'], null);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Only the bet owner can change it.');
        $this->service->close(8, $bet->id, null);
    }

    public function testWinningOptionMustBelongToBet(): void
    {
        $bet = $this->service->create(7, 'Winner?', null, null, ['Blue', 'Red'], null);
        $this->service->close(7, $bet->id, null);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Winning option does not belong to the bet.');
        $this->service->settle(7, $bet->id, 999, null);
    }
}

final class BetTestStore implements BetStore
{
    /** @var array<int, Bet> */
    public array $bets = [];
    private int $nextOptionId = 1;

    public function findAll(): array { return array_values($this->bets); }
    public function findByOwner(int $ownerUserId): array { return array_values(array_filter($this->bets, static fn(Bet $bet): bool => $bet->ownerUserId === $ownerUserId)); }
    public function findById(int $id): ?Bet { return $this->bets[$id] ?? null; }
    public function create(int $ownerUserId, string $question, ?string $description, ?DateTimeImmutable $closesAt, array $options): Bet
    {
        $id = count($this->bets) + 1;
        return $this->bets[$id] = new Bet($id, $ownerUserId, $question, $description, $closesAt, BetStatus::Open, null, $this->options($options));
    }
    public function update(int $id, string $question, ?string $description, ?DateTimeImmutable $closesAt, array $options): Bet
    {
        $bet = $this->bets[$id];
        return $this->bets[$id] = new Bet($id, $bet->ownerUserId, $question, $description, $closesAt, $bet->status, null, $this->options($options));
    }
    public function changeStatus(int $id, BetStatus $status, ?int $winningOptionId): Bet
    {
        $bet = $this->bets[$id];
        return $this->bets[$id] = new Bet($id, $bet->ownerUserId, $bet->question, $bet->description, $bet->closesAt, $status, $winningOptionId, $bet->options);
    }
    /** @param list<string> $labels @return list<BetOption> */
    private function options(array $labels): array
    {
        return array_map(fn(string $label, int $position): BetOption => new BetOption($this->nextOptionId++, $label, $position), $labels, array_keys($labels));
    }
}

final class BetTestAuditLogger implements AuditLogger
{
    /** @var list<array<string, mixed>> */
    public array $entries = [];
    public function record(int $actorUserId, string $action, string $entityType, string $entityId, ?array $before, ?array $after, ?string $ipAddress): void
    {
        $this->entries[] = compact('actorUserId', 'action', 'entityType', 'entityId', 'before', 'after', 'ipAddress');
    }
}
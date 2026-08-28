<?php

declare(strict_types=1);

namespace Tests\Unit\Service;

use App\Domain\Bet\Bet;
use App\Domain\Bet\BetOption;
use App\Domain\Bet\BetStatus;
use App\Repository\AuditLogger;
use App\Repository\BetStore;
use App\Repository\StakeStore;
use App\Domain\Stake\Stake;
use App\Service\BetService;
use DateTimeImmutable;
use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;

final class BetServiceTest extends TestCase
{
    private BetTestStore $bets;
    private BetTestStakeStore $stakes;
    private BetTestAuditLogger $audit;
    private PDO $pdo;
    private BetService $service;

    protected function setUp(): void
    {
        $this->bets = new BetTestStore();
        $this->stakes = new BetTestStakeStore();
        $this->audit = new BetTestAuditLogger();
        $this->pdo = new PDO('sqlite::memory:');
        $this->service = new BetService($this->pdo, $this->bets, $this->stakes, $this->audit);
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

    public function testClosedBetCanBeCancelled(): void
    {
        $bet = $this->service->create(7, 'Winner?', null, null, ['Blue', 'Red'], null);
        $this->service->close(7, $bet->id, null);

        $cancelled = $this->service->cancel(7, $bet->id, null);

        self::assertSame(BetStatus::Cancelled, $cancelled->status);
    }

    public function testEditAllOverrideKeepsRealActorInAuditLog(): void
    {
        $bet = $this->service->create(7, 'Winner?', null, null, ['Blue', 'Red'], null);

        $this->service->close(8, $bet->id, '127.0.0.1', true);

        self::assertSame(8, $this->audit->entries[1]['actorUserId']);
        self::assertSame('bet.closed', $this->audit->entries[1]['action']);
    }

    public function testMetadataCanBeUpdatedWithoutReplacingOptionsWhenStakeExists(): void
    {
        $bet = $this->service->create(7, 'Winner?', null, null, ['Blue', 'Red'], null);
        $optionIds = array_map(static fn(BetOption $option): int => $option->id, $bet->options);
        $this->stakes->stakes[] = new Stake(1, $bet->id, $optionIds[0], 20, 1000, 'Alice', 'Blue', false, true);

        $updated = $this->service->update(7, $bet->id, 'New question?', null, '2026-09-01T20:30', ['Blue', 'Red'], null);

        self::assertSame('New question?', $updated->question);
        self::assertSame('2026-09-01T20:30:00', $updated->closesAt?->format('Y-m-d\TH:i:s'));
        self::assertSame($optionIds, array_map(static fn(BetOption $option): int => $option->id, $updated->options));
        self::assertCount(1, $this->stakes->stakes);
    }

    public function testOptionsCannotBeChangedWhenStakeExists(): void
    {
        $bet = $this->service->create(7, 'Winner?', null, null, ['Blue', 'Red'], null);
        $this->stakes->stakes[] = new Stake(1, $bet->id, $bet->options[0]->id, 20, 1000, 'Alice', 'Blue', false, false);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Bet options cannot be changed once stakes have been placed.');

        $this->service->update(7, $bet->id, 'New question?', null, null, ['Blue', 'Green'], null);
    }

    public function testOpenOddsIncludeUnpaidStakesAndClosedOddsExcludeThem(): void
    {
        $bet = $this->service->create(7, 'Winner?', null, null, ['Blue', 'Red'], null);
        $this->stakes->stakes = [
            new Stake(1, $bet->id, $bet->options[0]->id, 20, 1000, 'Alice', 'Blue', false, true),
            new Stake(2, $bet->id, $bet->options[1]->id, 21, 2000, 'Bob', 'Red', false, false),
        ];

        $open = $this->service->withOdds($bet);
        $closed = $this->service->withOdds($this->service->close(7, $bet->id, null));

        self::assertSame([2.7, 1.35], array_column($open->options, 'odds'));
        self::assertSame([1.0, null], array_column($closed->options, 'odds'));
    }

    public function testCancelledBetCannotBeDeletedWhilePaidStakeIsNotRefunded(): void
    {
        $bet = $this->service->create(7, 'Winner?', null, null, ['Blue', 'Red'], null);
        $this->service->cancel(7, $bet->id, null);
        $this->stakes->stakes[] = new Stake(1, $bet->id, $bet->options[0]->id, 20, 1000, 'Alice', 'Blue', false, true);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('All paid stakes must be refunded');
        $this->service->delete(7, $bet->id, null);
    }

    public function testCancelledBetCanBeDeletedAfterPaidStakesAreRefunded(): void
    {
        $bet = $this->service->create(7, 'Winner?', null, null, ['Blue', 'Red'], null);
        $this->service->cancel(7, $bet->id, null);
        $this->stakes->stakes[] = new Stake(1, $bet->id, $bet->options[0]->id, 20, 1000, 'Alice', 'Blue', false, false);

        $this->service->delete(7, $bet->id, null);

        self::assertNull($this->bets->findById($bet->id));
        self::assertSame('bet.deleted', $this->audit->entries[array_key_last($this->audit->entries)]['action']);
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

    public function testSettlementPersistsFinancialSnapshotsAndFinalPayoutsAtomically(): void
    {
        $bet = $this->service->create(7, 'Winner?', null, null, ['Blue', 'Red'], null);
        $blueId = $bet->options[0]->id;
        $redId = $bet->options[1]->id;
        $this->stakes->stakes = [
            new Stake(1, $bet->id, $blueId, 20, 100, 'Alice', 'Blue', false, true),
            new Stake(2, $bet->id, $blueId, 21, 200, 'Bob', 'Blue', false, true),
            new Stake(3, $bet->id, $redId, 22, 700, 'Carol', 'Red', false, true),
            new Stake(4, $bet->id, $blueId, 23, 500, 'Dave', 'Blue', false, false),
        ];
        $this->service->close(7, $bet->id, null);

        $settled = $this->service->settle(7, $bet->id, $blueId, null);

        self::assertFalse($this->pdo->inTransaction());
        self::assertSame([$bet->id], $this->bets->lockedBetIds);
        self::assertSame(1000, $settled->finalPot);
        self::assertSame(100, $settled->finalBookmakerShare);
        self::assertSame(900, $settled->finalRedistributed);
        self::assertSame([3.0, 900 / 700], array_map(static fn(BetOption $option): ?float => $option->odds, $settled->options));
        self::assertSame([300, 600, 0, 0], array_map(static fn(Stake $stake): ?int => $stake->finalPayout, $this->stakes->stakes));

        try {
            $this->service->settle(7, $bet->id, $redId, null);
            self::fail('A settled bet must not be settled again.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('Bet must be closed to become settled.', $exception->getMessage());
        }

        self::assertFalse($this->pdo->inTransaction());
        self::assertSame($blueId, $this->bets->findById($bet->id)?->winningOptionId);
        self::assertSame([300, 600, 0, 0], array_map(static fn(Stake $stake): ?int => $stake->finalPayout, $this->stakes->stakes));
    }

    public function testBookmakerRateCanOnlyBeChangedWhileBetIsOpen(): void
    {
        $bet = $this->service->create(7, 'Winner?', null, null, ['Blue', 'Red'], null);
        $updated = $this->service->update(7, $bet->id, 'Winner?', null, null, ['Blue', 'Red'], null, '12.5');

        self::assertSame(1250, $updated->bookmakerRateBps);
        $this->service->close(7, $bet->id, null);

        try {
            $this->service->update(7, $bet->id, 'Winner?', null, null, ['Blue', 'Red'], null, '5');
            self::fail('A closed bet must not be editable.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('Only open bets can be edited.', $exception->getMessage());
        }

        self::assertFalse($this->pdo->inTransaction());
        self::assertSame(1250, $this->bets->findById($bet->id)?->bookmakerRateBps);
    }
}

final class BetTestStore implements BetStore
{
    /** @var array<int, Bet> */
    public array $bets = [];
    /** @var list<int> */
    public array $lockedBetIds = [];
    private int $nextOptionId = 1;

    public function findAll(): array { return array_values($this->bets); }
    public function findByOwner(int $ownerUserId): array { return array_values(array_filter($this->bets, static fn(Bet $bet): bool => $bet->ownerUserId === $ownerUserId)); }
    public function findById(int $id): ?Bet { return $this->bets[$id] ?? null; }
    public function findByIdForUpdate(int $id): ?Bet
    {
        $this->lockedBetIds[] = $id;

        return $this->findById($id);
    }
    public function create(int $ownerUserId, string $question, ?string $description, ?DateTimeImmutable $closesAt, array $options): Bet
    {
        $id = count($this->bets) + 1;
        return $this->bets[$id] = new Bet($id, $ownerUserId, $question, $description, $closesAt, BetStatus::Open, null, $this->options($options));
    }
    public function update(int $id, string $question, ?string $description, ?DateTimeImmutable $closesAt, array $options): Bet
    {
        $bet = $this->bets[$id];
        $currentOptions = array_map(static fn(BetOption $option): string => $option->label, $bet->options);
        $updatedOptions = $options === $currentOptions ? $bet->options : $this->options($options);
        return $this->bets[$id] = new Bet($id, $bet->ownerUserId, $question, $description, $closesAt,
            $bet->status, $bet->winningOptionId, $updatedOptions, $bet->bookmakerRateBps,
            $bet->finalPot, $bet->finalBookmakerShare, $bet->finalRedistributed);
    }
    public function changeStatus(int $id, BetStatus $status, ?int $winningOptionId): Bet
    {
        $bet = $this->bets[$id];
        return $this->bets[$id] = new Bet($id, $bet->ownerUserId, $bet->question, $bet->description,
            $bet->closesAt, $status, $winningOptionId, $bet->options, $bet->bookmakerRateBps,
            $bet->finalPot, $bet->finalBookmakerShare, $bet->finalRedistributed);
    }
    public function setBookmakerRate(int $id, int $rateBps): Bet
    {
        $bet = $this->bets[$id];
        return $this->bets[$id] = new Bet($id, $bet->ownerUserId, $bet->question, $bet->description,
            $bet->closesAt, $bet->status, $bet->winningOptionId, $bet->options, $rateBps,
            $bet->finalPot, $bet->finalBookmakerShare, $bet->finalRedistributed);
    }
    public function settleFinancials(int $id, int $winningOptionId, int $pot, int $bookmakerShare, int $redistributed, array $oddsByOptionId): Bet
    {
        $bet = $this->bets[$id];
        $options = array_map(static fn(BetOption $option): BetOption => new BetOption(
            $option->id,
            $option->label,
            $option->position,
            $oddsByOptionId[$option->id],
        ), $bet->options);

        return $this->bets[$id] = new Bet($id, $bet->ownerUserId, $bet->question, $bet->description,
            $bet->closesAt, BetStatus::Settled, $winningOptionId, $options, $bet->bookmakerRateBps,
            $pot, $bookmakerShare, $redistributed);
    }
    public function delete(int $id): void { unset($this->bets[$id]); }
    /** @param list<string> $labels @return list<BetOption> */
    private function options(array $labels): array
    {
        return array_map(fn(string $label, int $position): BetOption => new BetOption($this->nextOptionId++, $label, $position), $labels, array_keys($labels));
    }
}

final class BetTestStakeStore implements StakeStore
{
    /** @var list<Stake> */
    public array $stakes = [];
    public function findByBet(int $betId): array { return array_values(array_filter($this->stakes, static fn(Stake $stake): bool => $stake->betId === $betId)); }
    public function findById(int $id): ?Stake { return null; }
    public function create(int $betId, int $betOptionId, int $contactId, int $amount): Stake { throw new \LogicException(); }
    public function update(int $id, int $betOptionId, int $contactId, int $amount): Stake { throw new \LogicException(); }
    public function setPaid(int $id, bool $isPaid): Stake { throw new \LogicException(); }
    public function setCancelled(int $id, bool $isCancelled): Stake { throw new \LogicException(); }
    public function setFinalPayouts(int $betId, array $payoutsByStakeId): void
    {
        $this->stakes = array_map(static fn(Stake $stake): Stake => new Stake(
            $stake->id,
            $stake->betId,
            $stake->betOptionId,
            $stake->contactId,
            $stake->amount,
            $stake->contactName,
            $stake->optionLabel,
            $stake->contactArchived,
            $stake->isPaid,
            $stake->isCancelled,
            $stake->betId === $betId ? ($payoutsByStakeId[$stake->id] ?? 0) : $stake->finalPayout,
        ), $this->stakes);
    }
    public function findWinnersByBet(int $betId, int $winningOptionId): array { return []; }
    public function setWinningsPaid(int $betId, int $winningOptionId, int $contactId, bool $isPaid): void { throw new \LogicException(); }
    public function delete(int $id): void { throw new \LogicException(); }
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
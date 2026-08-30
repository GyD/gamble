<?php

declare(strict_types=1);

namespace Tests\Unit\Service;

use App\Domain\Bet\Bet;
use App\Domain\Bet\BetAccessDeniedException;
use App\Domain\Bet\BetOption;
use App\Domain\Bet\BetStatus;
use App\Domain\Contact\Contact;
use App\Domain\Stake\Stake;
use App\Repository\AuditLogger;
use App\Repository\BetStore;
use App\Repository\ContactStore;
use App\Repository\StakeStore;
use App\Service\StakeService;
use DateTimeImmutable;
use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class StakeServiceTest extends TestCase
{
    private StakeTestStore $stakes;
    private StakeTestBetStore $bets;
    private StakeTestContactStore $contacts;
    private StakeTestAuditLogger $audit;
    private StakeService $service;

    protected function setUp(): void
    {
        $this->stakes = new StakeTestStore();
        $this->bets = new StakeTestBetStore();
        $this->contacts = new StakeTestContactStore();
        $this->audit = new StakeTestAuditLogger();
        $this->bets->bets[1] = new Bet(1, 7, 'Winner?', null, null, BetStatus::Open, null, [
            new BetOption(10, 'Blue', 0),
            new BetOption(11, 'Red', 1),
        ]);
        $this->contacts->contacts[20] = new Contact(20, 'Alice', '1234', null, null);
        $this->service = new StakeService(new PDO('sqlite::memory:'), $this->stakes, $this->bets, $this->contacts, $this->audit);
    }

    public function testContactCanPlaceSeveralAuditedStakesAcrossOptions(): void
    {
        $first = $this->service->create(7, 1, 20, 10, '12', '127.0.0.1');
        $second = $this->service->create(7, 1, 20, 11, '5', null);

        self::assertNotSame($first->id, $second->id);
        self::assertSame([12, 5], array_column($this->stakes->stakes, 'amount'));
        self::assertSame([10, 11], array_column($this->stakes->stakes, 'betOptionId'));
        self::assertSame(['stake.created', 'stake.created'], array_column($this->audit->entries, 'action'));
        self::assertSame(12, $this->audit->entries[0]['after']['amount']);
    }

    /** @param string $amount */
    #[DataProvider('invalidAmounts')]
    public function testInvalidAmountsAreRejected(string $amount): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service->create(7, 1, 20, 10, $amount, null);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidAmounts(): iterable
    {
        yield 'zero' => ['0'];
        yield 'negative' => ['-1'];
        yield 'decimal with dot' => ['1.5'];
        yield 'decimal with comma' => ['1,5'];
        yield 'decimal zero fraction' => ['1.00'];
        yield 'not numeric' => ['money'];
        yield 'too large' => ['1000000'];
    }

    public function testMaximumWholeAmountIsAccepted(): void
    {
        $stake = $this->service->create(7, 1, 20, 10, '999999', null);

        self::assertSame(999_999, $stake->amount);
    }

    public function testClosedBetRejectsChanges(): void
    {
        $this->bets->bets[1] = $this->withStatus(BetStatus::Closed);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('while the bet is open');
        $this->service->create(7, 1, 20, 10, '1', null);
    }

    public function testWinningsReturnFrozenPayouts(): void
    {
        $this->bets->bets[1] = $this->withStatus(BetStatus::Settled, 10);
        $this->stakes->winners = [
            ['contact_id' => 20, 'contact_name' => 'Alice', 'winning_stake' => 100, 'payout' => 333, 'is_winnings_paid' => false],
            ['contact_id' => 21, 'contact_name' => 'Bob', 'winning_stake' => 200, 'payout' => 667, 'is_winnings_paid' => true],
        ];

        $winners = $this->service->winnings($this->bets->bets[1]);

        self::assertSame([333, 667], array_column($winners, 'payout'));
        self::assertSame(1000, array_sum(array_column($winners, 'payout')));
    }

    public function testOwnerCanMarkSettledWinningsPaidWithAudit(): void
    {
        $this->bets->bets[1] = $this->withStatus(BetStatus::Settled, 10);
        $this->stakes->winners = [
            ['contact_id' => 20, 'contact_name' => 'Alice', 'winning_stake' => 100, 'payout' => 100, 'is_winnings_paid' => false],
        ];

        $this->service->setWinningsPaid(7, 1, 20, true, '127.0.0.1');

        self::assertTrue($this->stakes->winners[0]['is_winnings_paid']);
        self::assertSame('stake.winnings_payment_status_changed', $this->audit->entries[0]['action']);
        self::assertFalse($this->audit->entries[0]['before']['is_winnings_paid']);
        self::assertTrue($this->audit->entries[0]['after']['is_winnings_paid']);
    }

    public function testPaidStakeCanBeMarkedRefundedOnCancelledBet(): void
    {
        $this->stakes->stakes[1] = new Stake(1, 1, 10, 20, 1000, 'Alice', 'Blue', false, true);
        $this->bets->bets[1] = $this->withStatus(BetStatus::Cancelled);

        $refunded = $this->service->setRefunded(7, 1, 1, true, null);

        self::assertFalse($refunded->isPaid);
        self::assertSame('stake.refund_status_changed', $this->audit->entries[0]['action']);
    }

    public function testCancelledPaidStakeCanBeMarkedRefundedOnCancelledBet(): void
    {
        $this->stakes->stakes[1] = new Stake(1, 1, 10, 20, 1000, 'Alice', 'Blue', false, true, true);
        $this->bets->bets[1] = $this->withStatus(BetStatus::Cancelled);

        $refunded = $this->service->setRefunded(7, 1, 1, true, null);

        self::assertFalse($refunded->isPaid);
        self::assertTrue($refunded->isCancelled);
    }

    public function testRefundCanBeCancelled(): void
    {
        $this->stakes->stakes[1] = new Stake(1, 1, 10, 20, 1000, 'Alice', 'Blue', false, false);
        $this->bets->bets[1] = $this->withStatus(BetStatus::Cancelled);

        $notRefunded = $this->service->setRefunded(7, 1, 1, false, null);

        self::assertTrue($notRefunded->isPaid);
    }

    public function testOnlyOwnerCanManageStakes(): void
    {
        $this->expectException(BetAccessDeniedException::class);
        $this->service->create(8, 1, 20, 10, '1', null);
    }

    public function testGlobalEditorCanManageAnotherOwnersStakes(): void
    {
        $stake = $this->service->create(8, 1, 20, 10, '1', null, true);
        $updated = $this->service->update(8, 1, $stake->id, 20, 11, '3', null, true);
        $this->service->setPaid(8, 1, $stake->id, true, null, true);
        $this->service->setPaid(8, 1, $stake->id, false, null, true);
        $this->service->setCancelled(8, 1, $stake->id, true, null, true);
        $this->service->delete(8, 1, $stake->id, null, true);

        self::assertSame(3, $updated->amount);
        self::assertSame([], $this->stakes->stakes);
        self::assertSame([8, 8, 8, 8, 8, 8], array_column($this->audit->entries, 'actorUserId'));
    }

    public function testGlobalEditorCanRefundAndPayWinningsOfAnotherOwnersBet(): void
    {
        $this->stakes->stakes[1] = new Stake(1, 1, 10, 20, 1000, 'Alice', 'Blue', false, true);
        $this->bets->bets[1] = $this->withStatus(BetStatus::Cancelled);

        $refunded = $this->service->setRefunded(8, 1, 1, true, null, true);

        $this->bets->bets[1] = $this->withStatus(BetStatus::Settled, 10);
        $this->stakes->winners = [
            ['contact_id' => 20, 'contact_name' => 'Alice', 'winning_stake' => 100, 'payout' => 100, 'is_winnings_paid' => false],
        ];
        $this->service->setWinningsPaid(8, 1, 20, true, null, true);

        self::assertFalse($refunded->isPaid);
        self::assertTrue($this->stakes->winners[0]['is_winnings_paid']);
        self::assertSame([8, 8], array_column($this->audit->entries, 'actorUserId'));
    }

    public function testStakesOfAnotherOwnerStayProtectedWithoutGlobalEdit(): void
    {
        $this->stakes->stakes[1] = new Stake(1, 1, 10, 20, 1000, 'Alice', 'Blue', false, true);
        $this->bets->bets[1] = $this->withStatus(BetStatus::Cancelled);

        $this->expectException(BetAccessDeniedException::class);
        $this->service->setRefunded(8, 1, 1, true, null);
    }

    public function testOptionMustBelongToBet(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('does not belong');
        $this->service->create(7, 1, 20, 999, '1', null);
    }

    public function testArchivedContactIsRejected(): void
    {
        $this->contacts->contacts[20] = new Contact(20, 'Alice', '1234', null, new DateTimeImmutable());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Archived contacts');
        $this->service->create(7, 1, 20, 10, '1', null);
    }

    public function testStakeCanBeUpdatedAndDeletedWithAuditSnapshots(): void
    {
        $stake = $this->service->create(7, 1, 20, 10, '1', null);
        $updated = $this->service->update(7, 1, $stake->id, 20, 11, '2', null);
        $this->service->setCancelled(7, 1, $stake->id, true, null);
        $this->service->delete(7, 1, $stake->id, null);

        self::assertSame(2, $updated->amount);
        self::assertSame([], $this->stakes->stakes);
        self::assertSame(['stake.created', 'stake.updated', 'stake.cancellation_status_changed', 'stake.deleted'], array_column($this->audit->entries, 'action'));
        self::assertSame(1, $this->audit->entries[1]['before']['amount']);
        self::assertSame(2, $this->audit->entries[3]['before']['amount']);
    }

    public function testStakeCannotBeDeletedBeforeCancellation(): void
    {
        $stake = $this->service->create(7, 1, 20, 10, '1', null);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must be cancelled');
        $this->service->delete(7, 1, $stake->id, null);
    }

    public function testPaidStakeCanBeCancelledButCannotBeDeleted(): void
    {
        $stake = $this->service->create(7, 1, 20, 10, '1', null);
        $this->service->setPaid(7, 1, $stake->id, true, null);
        $cancelled = $this->service->setCancelled(7, 1, $stake->id, true, null);

        self::assertTrue($cancelled->isPaid);
        self::assertTrue($cancelled->isCancelled);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('marked unpaid');
        $this->service->delete(7, 1, $stake->id, null);
    }

    public function testCancelledPaidStakeCanBeMarkedUnpaidAndDeleted(): void
    {
        $stake = $this->service->create(7, 1, 20, 10, '1', null);
        $this->service->setPaid(7, 1, $stake->id, true, null);
        $this->service->setCancelled(7, 1, $stake->id, true, null);

        $refunded = $this->service->setPaid(7, 1, $stake->id, false, null);
        $this->service->delete(7, 1, $stake->id, null);

        self::assertFalse($refunded->isPaid);
        self::assertTrue($refunded->isCancelled);
        self::assertSame([], $this->stakes->stakes);
    }

    public function testCancelledStakeCannotBeChanged(): void
    {
        $stake = $this->service->create(7, 1, 20, 10, '1', null);
        $this->service->setCancelled(7, 1, $stake->id, true, null);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Cancelled stakes cannot be marked paid');
        $this->service->setPaid(7, 1, $stake->id, true, null);
    }

    public function testStakeCanBeMarkedPaidAndUnpaidWithAuditSnapshots(): void
    {
        $stake = $this->service->create(7, 1, 20, 10, '1', null);

        $paid = $this->service->setPaid(7, 1, $stake->id, true, '127.0.0.1');
        $unpaid = $this->service->setPaid(7, 1, $stake->id, false, null);

        self::assertTrue($paid->isPaid);
        self::assertFalse($unpaid->isPaid);
        self::assertSame(
            ['stake.created', 'stake.payment_status_changed', 'stake.payment_status_changed'],
            array_column($this->audit->entries, 'action'),
        );
        self::assertFalse($this->audit->entries[1]['before']['is_paid']);
        self::assertTrue($this->audit->entries[1]['after']['is_paid']);
    }

    public function testStakeFromAnotherBetCannotBeChanged(): void
    {
        $this->stakes->stakes[1] = new Stake(1, 2, 10, 20, 1000, 'Alice', 'Blue', false, true);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('does not belong');
        $this->service->delete(7, 1, 1, null);
    }

    private function withStatus(BetStatus $status, ?int $winningOptionId = null): Bet
    {
        $bet = $this->bets->bets[1];

        return new Bet($bet->id, $bet->ownerUserId, $bet->question, $bet->description, $bet->closesAt, $status, $winningOptionId, $bet->options);
    }
}

final class StakeTestStore implements StakeStore
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

    public function create(int $betId, int $betOptionId, int $contactId, int $amount): Stake
    {
        $id = count($this->stakes) + 1;
        return $this->stakes[$id] = new Stake($id, $betId, $betOptionId, $contactId, $amount, 'Alice', $betOptionId === 10 ? 'Blue' : 'Red', false, false);
    }

    public function update(int $id, int $betOptionId, int $contactId, int $amount): Stake
    {
        $stake = $this->stakes[$id];
        return $this->stakes[$id] = new Stake($id, $stake->betId, $betOptionId, $contactId, $amount, 'Alice', $betOptionId === 10 ? 'Blue' : 'Red', false, $stake->isPaid, $stake->isCancelled);
    }

    public function setPaid(int $id, bool $isPaid): Stake
    {
        $stake = $this->stakes[$id];

        return $this->stakes[$id] = new Stake($stake->id, $stake->betId, $stake->betOptionId, $stake->contactId, $stake->amount, $stake->contactName, $stake->optionLabel, $stake->contactArchived, $isPaid, $stake->isCancelled);
    }

    public function setCancelled(int $id, bool $isCancelled): Stake
    {
        $stake = $this->stakes[$id];

        return $this->stakes[$id] = new Stake($stake->id, $stake->betId, $stake->betOptionId, $stake->contactId, $stake->amount, $stake->contactName, $stake->optionLabel, $stake->contactArchived, $stake->isPaid, $isCancelled);
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

final class StakeTestBetStore implements BetStore
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

    public function findByIdForUpdate(int $id): ?Bet
    {
        return $this->findById($id);
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
    public function setBookmakerRate(int $id, int $rateBps): Bet { throw new \LogicException(); }
    public function settleFinancials(int $id, int $winningOptionId, int $pot, int $bookmakerShare, int $redistributed, array $oddsByOptionId): Bet { throw new \LogicException(); }
    public function delete(int $id): void { throw new \LogicException(); }
}

final class StakeTestContactStore implements ContactStore
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

final class StakeTestAuditLogger implements AuditLogger
{
    /** @var list<array<string, mixed>> */
    public array $entries = [];

    public function record(int $actorUserId, string $action, string $entityType, string $entityId, ?array $before, ?array $after, ?string $ipAddress): void
    {
        $this->entries[] = compact('actorUserId', 'action', 'entityType', 'entityId', 'before', 'after', 'ipAddress');
    }
}
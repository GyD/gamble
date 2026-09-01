<?php

declare(strict_types=1);

namespace Tests\Unit\Service;

use App\Domain\Bet\Bet;
use App\Domain\Bet\BetOption;
use App\Domain\Bet\BetStatus;
use App\Domain\Bet\BettingMode;
use App\Domain\Bet\OddsEvolutionMode;
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
            new BetOption(10, 'Blue', 0, 2.00, 2.00),
            new BetOption(11, 'Red', 1, 2.00, 2.00),
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

    public function testTheOddsAnnouncedAreOnlyQuotedAtCreation(): void
    {
        $stake = $this->service->create(7, 1, 20, 10, '100', null);

        // Nothing is contracted before payment: the price is only announced.
        self::assertSame(2.00, $stake->quotedOdds);
        self::assertNull($stake->oddsAtBet);
        self::assertFalse($stake->hasContractualOdds());
        // Without contractual odds, a won stake is only worth its own amount.
        self::assertSame(100, $stake->potentialPayout());
        self::assertSame(2.00, $this->audit->entries[0]['after']['quoted_odds']);
        self::assertNull($this->audit->entries[0]['after']['odds_at_bet']);
    }

    public function testContractualOddsAreCapturedAtPaymentAtThePriceOfThatDay(): void
    {
        $stake = $this->service->create(7, 1, 20, 10, '100', null);
        $this->bets->bets[1] = $this->bets->bets[1]->withOptions([
            new BetOption(10, 'Blue', 0, 1.50, 1.50),
            new BetOption(11, 'Red', 1, 3.00, 3.00),
        ]);

        $paid = $this->service->setPaid(7, 1, $stake->id, true, null);

        // The bettor reserved nothing: they are paid at the current price.
        self::assertSame(2.00, $paid->quotedOdds);
        self::assertSame(1.50, $paid->oddsAtBet);
        self::assertSame(150, $paid->potentialPayout());
        self::assertSame(1.50, $this->audit->entries[1]['after']['odds_at_bet']);
    }

    public function testAnUnpaidStakeCanBeRepricedAndChangedWithoutContract(): void
    {
        $stake = $this->service->create(7, 1, 20, 10, '100', null);
        $this->bets->bets[1] = $this->bets->bets[1]->withOptions([
            new BetOption(10, 'Blue', 0, 1.20, 1.20),
            new BetOption(11, 'Red', 1, 4.00, 4.00),
        ]);

        $updated = $this->service->update(7, 1, $stake->id, 20, 10, '150', null);

        // The announced price stays as a trace of what was presented.
        self::assertSame(2.00, $updated->quotedOdds);
        self::assertNull($updated->oddsAtBet);
    }

    public function testContractualOddsAreCapturedOnlyOnceAndSurviveUnpayment(): void
    {
        $stake = $this->service->create(7, 1, 20, 10, '100', null);
        $this->service->setPaid(7, 1, $stake->id, true, null);
        $this->bets->bets[1] = $this->bets->bets[1]->withOptions([
            new BetOption(10, 'Blue', 0, 1.10, 1.10),
            new BetOption(11, 'Red', 1, 5.00, 5.00),
        ]);

        $unpaid = $this->service->setPaid(7, 1, $stake->id, false, null);
        $paidAgain = $this->service->setPaid(7, 1, $stake->id, true, null);

        // Unpaying is a cash correction, not the end of the contract.
        self::assertSame(2.00, $unpaid->oddsAtBet);
        self::assertSame(2.00, $paidAgain->oddsAtBet);
        self::assertSame(200, $paidAgain->potentialPayout());
    }

    public function testPaymentCapturesTheOddsQuotedWithoutTheStakeItself(): void
    {
        // Alone on a drifting market, the stake degrades the public price. The
        // captured contract must be the price quoted without its own influence.
        $this->bets->bets[1] = $this->drifting([2.50, 2.50]);
        $stake = $this->service->create(7, 1, 20, 10, '100', null);
        $publicOdds = $this->service->withOdds($this->bets->bets[1])->options[0]->offeredOdds;

        $paid = $this->service->setPaid(7, 1, $stake->id, true, null);

        self::assertSame(2.47, $publicOdds);
        self::assertSame(2.50, $paid->oddsAtBet);
        self::assertSame(250, $paid->potentialPayout());
        self::assertSame(2.50, $this->audit->entries[1]['after']['odds_at_bet']);
    }

    public function testThePublicOddsAreOnlyRecalculatedAfterTheCapture(): void
    {
        $this->bets->bets[1] = $this->drifting([2.50, 2.50]);
        $stake = $this->service->create(7, 1, 20, 10, '100', null);

        $paid = $this->service->setPaid(7, 1, $stake->id, true, null);
        $publicOddsAfterPayment = $this->service->withOdds($this->bets->bets[1])->options[0]->offeredOdds;

        // Its weight moved from 0.50 to 1.00 only after the contract was signed,
        // so the next bettors see a shorter price than the one it captured.
        self::assertSame(2.50, $paid->oddsAtBet);
        self::assertSame(2.45, $publicOddsAfterPayment);
        self::assertLessThan((float) $paid->oddsAtBet, (float) $publicOddsAfterPayment);
    }

    public function testTheOtherStakesStillMoveTheCapturedOdds(): void
    {
        // Money on Red lengthens Blue: the stake captures that market movement,
        // only its own contribution is excluded.
        $this->bets->bets[1] = $this->drifting([2.50, 2.50]);
        $stake = $this->service->create(7, 1, 20, 10, '100', null);
        $onRed = $this->service->create(7, 1, 20, 11, '400', null);
        $this->service->setPaid(7, 1, $onRed->id, true, null);

        $paid = $this->service->setPaid(7, 1, $stake->id, true, null);

        self::assertSame(2.50, $paid->quotedOdds);
        self::assertNotNull($paid->oddsAtBet);
        self::assertGreaterThan(2.50, $paid->oddsAtBet);
    }

    public function testUnpayingAndRepayingNeverCapturesTheOddsAgain(): void
    {
        $this->bets->bets[1] = $this->drifting([2.50, 2.50]);
        $stake = $this->service->create(7, 1, 20, 10, '100', null);
        $captured = $this->service->setPaid(7, 1, $stake->id, true, null)->oddsAtBet;
        // The market moves a lot in between.
        $this->service->setPaid(7, 1, $this->service->create(7, 1, 20, 11, '900', null)->id, true, null);

        $unpaid = $this->service->setPaid(7, 1, $stake->id, false, null);
        $paidAgain = $this->service->setPaid(7, 1, $stake->id, true, null);

        self::assertSame(2.50, $captured);
        self::assertSame($captured, $unpaid->oddsAtBet);
        self::assertSame($captured, $paidAgain->oddsAtBet);
    }

    public function testRefundingAStakeNeverCapturesTheOddsAgain(): void
    {
        $this->bets->bets[1] = $this->drifting([2.50, 2.50]);
        $stake = $this->service->create(7, 1, 20, 10, '100', null);
        $captured = $this->service->setPaid(7, 1, $stake->id, true, null)->oddsAtBet;
        $this->bets->bets[1] = $this->withStatus(BetStatus::Cancelled);

        $refunded = $this->service->setRefunded(7, 1, $stake->id, true, null);
        $notRefunded = $this->service->setRefunded(7, 1, $stake->id, false, null);

        self::assertSame($captured, $refunded->oddsAtBet);
        self::assertSame($captured, $notRefunded->oddsAtBet);
    }

    public function testEachUnpaidStakeIsQuotedWithoutItselfOnly(): void
    {
        $this->bets->bets[1] = $this->drifting([2.50, 2.50]);
        $onBlue = $this->service->create(7, 1, 20, 10, '100', null);
        $onRed = $this->service->create(7, 1, 20, 11, '300', null);

        $paymentOdds = $this->service->paymentOddsByStake($this->bets->bets[1]);

        self::assertSame([$onBlue->id, $onRed->id], array_keys($paymentOdds));
        // Each stake keeps seeing the other, so their prices differ.
        self::assertNotSame($paymentOdds[$onBlue->id], $paymentOdds[$onRed->id]);
        // And neither equals the public price, which carries both of them.
        $public = $this->service->withOdds($this->bets->bets[1]);
        self::assertNotSame($public->options[0]->offeredOdds, $paymentOdds[$onBlue->id]);
    }

    public function testRefundCancellationNeverCapturesNewContractualOdds(): void
    {
        // A legacy stake paid without contractual odds: the refund flow must not
        // silently turn it into a priced contract.
        $this->stakes->stakes[1] = new Stake(1, 1, 10, 20, 1000, 'Alice', 'Blue', false, true);
        $this->bets->bets[1] = $this->withStatus(BetStatus::Cancelled);

        $refunded = $this->service->setRefunded(7, 1, 1, true, null);
        $notRefunded = $this->service->setRefunded(7, 1, 1, false, null);

        self::assertNull($refunded->oddsAtBet);
        self::assertNull($notRefunded->oddsAtBet);
        self::assertTrue($notRefunded->isPaid);
    }

    public function testAnUnpricedOptionAcceptsNoStake(): void
    {
        $this->bets->bets[1] = $this->bets->bets[1]->withOptions([
            new BetOption(10, 'Blue', 0),
            new BetOption(11, 'Red', 1),
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('This option has no odds yet: price it before taking a stake.');

        $this->service->create(7, 1, 20, 10, '100', null);
    }

    public function testPariMutuelStakesCarryNoContractualOdds(): void
    {
        $bet = $this->bets->bets[1];
        $this->bets->bets[1] = new Bet($bet->id, $bet->ownerUserId, $bet->question, $bet->description,
            $bet->closesAt, $bet->status, null, $bet->options, null, null, null, BettingMode::PariMutuel);

        $stake = $this->service->create(7, 1, 20, 10, '100', null);

        // The payout only comes out of the pool at settlement.
        self::assertNull($stake->oddsAtBet);
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

    public function testUnknownBetStakesCannotBeCreated(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown bet.');
        $this->service->create(8, 404, 20, 10, '1', null);
    }

    public function testAnotherOwnersStakesCanBeManaged(): void
    {
        $stake = $this->service->create(8, 1, 20, 10, '1', null);
        $updated = $this->service->update(8, 1, $stake->id, 20, 11, '3', null);
        $this->service->setPaid(8, 1, $stake->id, true, null);
        $this->service->setPaid(8, 1, $stake->id, false, null);
        $this->service->setCancelled(8, 1, $stake->id, true, null);
        $this->service->delete(8, 1, $stake->id, null);

        self::assertSame(3, $updated->amount);
        self::assertSame([], $this->stakes->stakes);
        self::assertSame([8, 8, 8, 8, 8, 8], array_column($this->audit->entries, 'actorUserId'));
    }

    public function testAnotherOwnersBetWinningsAndRefundsCanBeManaged(): void
    {
        $this->stakes->stakes[1] = new Stake(1, 1, 10, 20, 1000, 'Alice', 'Blue', false, true);
        $this->bets->bets[1] = $this->withStatus(BetStatus::Cancelled);

        $refunded = $this->service->setRefunded(8, 1, 1, true, null);

        $this->bets->bets[1] = $this->withStatus(BetStatus::Settled, 10);
        $this->stakes->winners = [
            ['contact_id' => 20, 'contact_name' => 'Alice', 'winning_stake' => 100, 'payout' => 100, 'is_winnings_paid' => false],
        ];
        $this->service->setWinningsPaid(8, 1, 20, true, null);

        self::assertFalse($refunded->isPaid);
        self::assertTrue($this->stakes->winners[0]['is_winnings_paid']);
        self::assertSame([8, 8], array_column($this->audit->entries, 'actorUserId'));
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

    /**
     * A bet whose offered odds drift with the exposure taken.
     *
     * @param list<float|null> $odds
     */
    private function drifting(array $odds): Bet
    {
        return new Bet(1, 7, 'Winner?', null, null, BetStatus::Open, null, [
            new BetOption(10, 'Blue', 0, $odds[0]),
            new BetOption(11, 'Red', 1, $odds[1]),
        ], null, null, null, BettingMode::FixedOdds, OddsEvolutionMode::DynamicNormal);
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

    public function findByIdForUpdate(int $id): ?Stake
    {
        return $this->findById($id);
    }

    public function create(int $betId, int $betOptionId, int $contactId, int $amount, ?float $quotedOdds = null): Stake
    {
        $id = count($this->stakes) + 1;
        return $this->stakes[$id] = new Stake($id, $betId, $betOptionId, $contactId, $amount, 'Alice', $betOptionId === 10 ? 'Blue' : 'Red', false, false, false, null, null, new DateTimeImmutable(), $quotedOdds);
    }

    public function update(int $id, int $betOptionId, int $contactId, int $amount): Stake
    {
        $stake = $this->stakes[$id];
        return $this->stakes[$id] = new Stake($id, $stake->betId, $betOptionId, $contactId, $amount, 'Alice', $betOptionId === 10 ? 'Blue' : 'Red', false, $stake->isPaid, $stake->isCancelled, $stake->finalPayout, $stake->oddsAtBet, $stake->createdAt, $stake->quotedOdds);
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

final class StakeTestBetStore implements BetStore
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
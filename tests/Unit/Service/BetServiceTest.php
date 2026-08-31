<?php

declare(strict_types=1);

namespace Tests\Unit\Service;

use App\Domain\Bet\Bet;
use App\Domain\Bet\BetOption;
use App\Domain\Bet\BetStatus;
use App\Domain\Bet\BettingMode;
use App\Domain\Bet\OddsEvolutionMode;
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

    public function testAnotherUserClosingABetKeepsRealActorInAuditLog(): void
    {
        $bet = $this->service->create(7, 'Winner?', null, null, ['Blue', 'Red'], null);

        $this->service->close(8, $bet->id, '127.0.0.1');

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

    public function testTheDriftMovesTheOddsOfferedToTheNextStakes(): void
    {
        $bet = $this->service->create(7, 'Winner?', null, null, ['Blue', 'Red'], null, null, 'dynamic_normal', ['2.00', '2.00']);
        $blueId = $bet->options[0]->id;
        $redId = $bet->options[1]->id;
        $this->stakes->stakes = [
            new Stake(1, $bet->id, $blueId, 20, 1000, 'Alice', 'Blue', false, true, false, null, 2.00, new DateTimeImmutable()),
            new Stake(2, $bet->id, $redId, 21, 1000, 'Bob', 'Red', false, true, false, null, 2.00, new DateTimeImmutable()),
        ];

        $balanced = $this->service->withOdds($bet);
        self::assertSame($balanced->options[0]->offeredOdds, $balanced->options[1]->offeredOdds);

        $this->stakes->stakes[] = new Stake(3, $bet->id, $redId, 22, 2000, 'Carol', 'Red', false, false, false, null, 2.00, new DateTimeImmutable());
        $withUnpaid = $this->service->withOdds($bet);

        self::assertGreaterThan((float) $balanced->options[0]->offeredOdds, (float) $withUnpaid->options[0]->offeredOdds);
        self::assertLessThan((float) $balanced->options[1]->offeredOdds, (float) $withUnpaid->options[1]->offeredOdds);

        $this->stakes->stakes[2] = new Stake(3, $bet->id, $redId, 22, 2000, 'Carol', 'Red', false, true, false, null, 2.00, new DateTimeImmutable());
        $withPaid = $this->service->withOdds($bet);

        // An unpaid stake only counts for half, so paying it pushes the odds further.
        self::assertGreaterThan((float) $withUnpaid->options[0]->offeredOdds, (float) $withPaid->options[0]->offeredOdds);
        self::assertLessThan((float) $withUnpaid->options[1]->offeredOdds, (float) $withPaid->options[1]->offeredOdds);
    }

    public function testPricingTheOddsAgainRestartsTheDrift(): void
    {
        $bet = $this->service->create(7, 'Winner?', null, null, ['Blue', 'Red'], null, null, 'dynamic_normal', ['2.00', '2.00']);
        $blueId = $bet->options[0]->id;
        // The book was priced two hours ago, the stake taken since then.
        $this->bets->anchorAt($bet->id, new DateTimeImmutable('-2 hours'));
        $this->stakes->stakes = [
            new Stake(1, $bet->id, $blueId, 20, 5000, 'Alice', 'Blue', false, true, false, null, 2.00, new DateTimeImmutable('-1 hour')),
        ];

        $drifted = $this->service->withOdds($this->bets->findById($bet->id) ?? $bet);
        self::assertLessThan(2.00, (float) $drifted->options[0]->offeredOdds);

        $repriced = $this->service->anchorOptionOdds(7, $bet->id, [$blueId => '1.90', $bet->options[1]->id => '2.10'], null);

        // The stake predates the new pricing: it no longer feeds the drift.
        self::assertSame(1.90, $this->service->withOdds($repriced)->options[0]->offeredOdds);
        self::assertSame('bet.odds_priced', $this->audit->entries[array_key_last($this->audit->entries)]['action']);
    }

    public function testSettledOddsAreKeptAsRecordedAtSettlement(): void
    {
        $bet = $this->service->create(7, 'Winner?', null, null, ['Blue', 'Red'], null, null, 'dynamic_normal', ['2.00', '2.00']);
        $this->service->close(7, $bet->id, null);
        $settled = $this->service->settle(7, $bet->id, $bet->options[0]->id, null);

        $this->stakes->stakes[] = new Stake(1, $bet->id, $bet->options[1]->id, 20, 5000, 'Alice', 'Red', false, true, false, null, 2.00, new DateTimeImmutable());

        self::assertSame([2.00, 2.00], array_column($settled->options, 'finalOdds'));
        self::assertSame(
            array_column($settled->options, 'finalOdds'),
            array_column($this->service->withOdds($settled)->options, 'finalOdds'),
        );
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

    public function testUnknownBetCannotBeChanged(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown bet.');
        $this->service->close(8, 404, null);
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
            new Stake(1, $bet->id, $blueId, 20, 100, 'Alice', 'Blue', false, true, false, null, 2.0),
            new Stake(2, $bet->id, $blueId, 21, 200, 'Bob', 'Blue', false, true, false, null, 1.8),
            new Stake(3, $bet->id, $redId, 22, 700, 'Carol', 'Red', false, true, false, null, 1.6),
            new Stake(4, $bet->id, $blueId, 23, 500, 'Dave', 'Blue', false, false, false, null, 2.1),
        ];
        $this->service->close(7, $bet->id, null);

        $settled = $this->service->settle(7, $bet->id, $blueId, null);

        self::assertFalse($this->pdo->inTransaction());
        self::assertSame([$bet->id, $bet->id], $this->bets->lockedBetIds);
        self::assertSame(1000, $settled->finalPot);
        // In fixed odds the bookmaker is paid through the overround, never on the pot.
        self::assertSame(0, $settled->finalBookmakerShare);
        self::assertSame(560, $settled->finalRedistributed);
        self::assertSame(440, $settled->finalBookmakerResult);
        self::assertSame(
            $settled->finalPot - $settled->finalRedistributed,
            $settled->finalBookmakerResult,
        );
        // Only paid, non cancelled and winning stakes are paid out, at their contractual odds.
        self::assertSame([200, 360, 0, 0], array_map(static fn(Stake $stake): ?int => $stake->finalPayout, $this->stakes->stakes));

        try {
            $this->service->settle(7, $bet->id, $redId, null);
            self::fail('A settled bet must not be settled again.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('Bet must be closed to become settled.', $exception->getMessage());
        }

        self::assertFalse($this->pdo->inTransaction());
        self::assertSame($blueId, $this->bets->findById($bet->id)?->winningOptionId);
        self::assertSame([200, 360, 0, 0], array_map(static fn(Stake $stake): ?int => $stake->finalPayout, $this->stakes->stakes));
    }

    public function testTheMutuelCommissionCanOnlyBeChangedWhileTheBetIsOpen(): void
    {
        $bet = $this->service->create(7, 'Winner?', null, null, ['Blue', 'Red'], null, 'pari_mutuel');
        $updated = $this->service->update(7, $bet->id, 'Winner?', null, null, ['Blue', 'Red'], null, '12.5');

        self::assertSame(1250, $updated->mutuelCommissionRateBps);
        $this->service->close(7, $bet->id, null);

        try {
            $this->service->update(7, $bet->id, 'Winner?', null, null, ['Blue', 'Red'], null, '5');
            self::fail('A closed bet must not be editable.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('Only open bets can be edited.', $exception->getMessage());
        }

        self::assertFalse($this->pdo->inTransaction());
        self::assertSame(1250, $this->bets->findById($bet->id)?->mutuelCommissionRateBps);
    }

    public function testOddsCanOnlyBeSetOnFixedOddsBets(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Odds can only be set on fixed odds bets.');

        $this->service->create(7, 'Winner?', null, null, ['Blue', 'Red'], null, 'pari_mutuel', null, ['2.00', '2.00']);
    }

    public function testEveryOptionNeedsItsOwnOdds(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Odds are required for each option.');

        $this->service->create(7, 'Winner?', null, null, ['Blue', 'Red'], null, null, null, ['2.00']);
    }

    public function testOddsBelowTheMinimumAreRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Odds must be a number between 1.01 and 1000.');

        $this->service->create(7, 'Winner?', null, null, ['Blue', 'Red'], null, null, null, ['1.00', '2.00']);
    }

    public function testAnUnpricedBookIsAccepted(): void
    {
        $bet = $this->service->create(7, 'Winner?', null, null, ['Blue', 'Red'], null);

        // The bookmaker may open the bet first and price it afterwards.
        self::assertSame([null, null], array_column($bet->options, 'odds'));
        self::assertNull($bet->oddsAnchoredAt);
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
    public function findById(int $id): ?Bet { return $this->bets[$id] ?? null; }
    public function findByIdForUpdate(int $id): ?Bet
    {
        $this->lockedBetIds[] = $id;

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
        $id = count($this->bets) + 1;
        return $this->bets[$id] = new Bet($id, $ownerUserId, $question, $description, $closesAt, BetStatus::Open, null,
            $this->options($options, $odds), null, null, null, $bettingMode, $oddsEvolutionMode, 1000, null,
            $this->anchor($odds));
    }
    public function update(int $id, string $question, ?string $description, ?DateTimeImmutable $closesAt, array $options, array $odds = []): Bet
    {
        $bet = $this->bets[$id];
        $currentOptions = array_map(static fn(BetOption $option): string => $option->label, $bet->options);
        $updatedOptions = $options === $currentOptions
            ? $this->reprice($bet->options, $odds)
            : $this->options($options, $odds);
        return $this->bets[$id] = new Bet($id, $bet->ownerUserId, $question, $description, $closesAt,
            $bet->status, $bet->winningOptionId, $updatedOptions,
            $bet->finalPot, $bet->finalBookmakerShare, $bet->finalRedistributed,
            $bet->bettingMode, $bet->oddsEvolutionMode, $bet->mutuelCommissionRateBps, $bet->finalBookmakerResult,
            $odds === [] ? $bet->oddsAnchoredAt : $this->anchor($odds));
    }
    public function changeStatus(int $id, BetStatus $status, ?int $winningOptionId): Bet
    {
        $bet = $this->bets[$id];
        return $this->bets[$id] = new Bet($id, $bet->ownerUserId, $bet->question, $bet->description,
            $bet->closesAt, $status, $winningOptionId, $bet->options,
            $bet->finalPot, $bet->finalBookmakerShare, $bet->finalRedistributed,
            $bet->bettingMode, $bet->oddsEvolutionMode, $bet->mutuelCommissionRateBps, $bet->finalBookmakerResult,
            $bet->oddsAnchoredAt);
    }
    public function setOptionOdds(int $id, array $oddsByOptionId): Bet
    {
        $bet = $this->bets[$id];
        $options = array_map(static function (BetOption $option) use ($oddsByOptionId): BetOption {
            $odds = array_key_exists($option->id, $oddsByOptionId) ? $oddsByOptionId[$option->id] : $option->odds;

            return new BetOption($option->id, $option->label, $option->position, $odds, $odds, $option->finalOdds);
        }, $bet->options);

        return $this->bets[$id] = new Bet($id, $bet->ownerUserId, $bet->question, $bet->description,
            $bet->closesAt, $bet->status, $bet->winningOptionId, $options,
            $bet->finalPot, $bet->finalBookmakerShare, $bet->finalRedistributed,
            $bet->bettingMode, $bet->oddsEvolutionMode, $bet->mutuelCommissionRateBps, $bet->finalBookmakerResult,
            new DateTimeImmutable());
    }
    public function setMutuelCommissionRate(int $id, int $rateBps): Bet
    {
        $bet = $this->bets[$id];
        return $this->bets[$id] = new Bet($id, $bet->ownerUserId, $bet->question, $bet->description,
            $bet->closesAt, $bet->status, $bet->winningOptionId, $bet->options,
            $bet->finalPot, $bet->finalBookmakerShare, $bet->finalRedistributed,
            $bet->bettingMode, $bet->oddsEvolutionMode, $rateBps, $bet->finalBookmakerResult, $bet->oddsAnchoredAt);
    }
    public function setBettingMode(int $id, BettingMode $bettingMode, OddsEvolutionMode $oddsEvolutionMode): Bet
    {
        $bet = $this->bets[$id];
        return $this->bets[$id] = new Bet($id, $bet->ownerUserId, $bet->question, $bet->description,
            $bet->closesAt, $bet->status, $bet->winningOptionId, $bet->options,
            $bet->finalPot, $bet->finalBookmakerShare, $bet->finalRedistributed,
            $bettingMode, $oddsEvolutionMode, $bet->mutuelCommissionRateBps, $bet->finalBookmakerResult,
            $bet->oddsAnchoredAt);
    }
    public function settleFinancials(int $id, int $winningOptionId, int $pot, int $bookmakerShare, int $redistributed, int $bookmakerResult, array $oddsByOptionId): Bet
    {
        $bet = $this->bets[$id];
        $options = array_map(static fn(BetOption $option): BetOption => new BetOption(
            $option->id,
            $option->label,
            $option->position,
            $option->odds,
            $option->offeredOdds,
            $oddsByOptionId[$option->id] ?? null,
        ), $bet->options);

        return $this->bets[$id] = new Bet($id, $bet->ownerUserId, $bet->question, $bet->description,
            $bet->closesAt, BetStatus::Settled, $winningOptionId, $options,
            $pot, $bookmakerShare, $redistributed,
            $bet->bettingMode, $bet->oddsEvolutionMode, $bet->mutuelCommissionRateBps, $bookmakerResult,
            $bet->oddsAnchoredAt);
    }
    public function delete(int $id): void { unset($this->bets[$id]); }
    /** Test helper: back-dates the pricing so a stake can be taken after it. */
    public function anchorAt(int $id, DateTimeImmutable $anchoredAt): void
    {
        $bet = $this->bets[$id];
        $this->bets[$id] = new Bet($id, $bet->ownerUserId, $bet->question, $bet->description,
            $bet->closesAt, $bet->status, $bet->winningOptionId, $bet->options,
            $bet->finalPot, $bet->finalBookmakerShare, $bet->finalRedistributed,
            $bet->bettingMode, $bet->oddsEvolutionMode, $bet->mutuelCommissionRateBps, $bet->finalBookmakerResult,
            $anchoredAt);
    }
    /** @param list<string> $labels @param list<float|null> $odds @return list<BetOption> */
    private function options(array $labels, array $odds = []): array
    {
        return array_map(
            fn(string $label, int $position): BetOption => new BetOption(
                $this->nextOptionId++,
                $label,
                $position,
                $odds[$position] ?? null,
                $odds[$position] ?? null,
            ),
            $labels,
            array_keys($labels),
        );
    }
    /** @param list<BetOption> $options @param list<float|null> $odds @return list<BetOption> */
    private function reprice(array $options, array $odds): array
    {
        if ($odds === []) {
            return $options;
        }

        return array_map(static fn(BetOption $option): BetOption => new BetOption(
            $option->id,
            $option->label,
            $option->position,
            $odds[$option->position] ?? null,
            $odds[$option->position] ?? null,
            $option->finalOdds,
        ), $options);
    }
    /** @param list<float|null> $odds */
    private function anchor(array $odds): ?DateTimeImmutable
    {
        return array_filter($odds, static fn(?float $value): bool => $value !== null) === []
            ? null
            : new DateTimeImmutable();
    }
}

final class BetTestStakeStore implements StakeStore
{
    /** @var list<Stake> */
    public array $stakes = [];
    public function findByBet(int $betId): array { return array_values(array_filter($this->stakes, static fn(Stake $stake): bool => $stake->betId === $betId)); }
    public function findById(int $id): ?Stake { return null; }
    public function findByIdForUpdate(int $id): ?Stake { return $this->findById($id); }
    public function create(int $betId, int $betOptionId, int $contactId, int $amount, ?float $quotedOdds = null): Stake { throw new \LogicException(); }
    public function update(int $id, int $betOptionId, int $contactId, int $amount): Stake { throw new \LogicException(); }
    public function captureOddsAtBet(int $id, float $oddsAtBet): Stake { throw new \LogicException(); }
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
<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\Bet\Bet;
use App\Domain\Bet\BetStatus;
use App\Domain\Stake\Stake;
use App\Repository\AuditLogger;
use App\Repository\BetStore;
use App\Repository\ContactStore;
use App\Repository\StakeStore;
use App\Service\Market\MarketRecalculator;
use InvalidArgumentException;
use PDO;
use Throwable;

final readonly class StakeService
{
    private const MAX_AMOUNT_UNITS = 999_999;

    private MarketRecalculator $market;

    public function __construct(
        private PDO          $pdo,
        private StakeStore   $stakes,
        private BetStore     $bets,
        private ContactStore $contacts,
        private AuditLogger  $auditLogs,
        ?MarketRecalculator  $market = null,
    )
    {
        $this->market = $market ?? new MarketRecalculator($bets, $stakes);
    }

    public function create(
        int     $actorUserId,
        int     $betId,
        int     $contactId,
        int     $betOptionId,
        string  $amount,
        ?string $ipAddress,
    ): Stake
    {
        $amount = $this->amount($amount);

        return $this->transactional(function () use ($actorUserId, $betId, $contactId, $betOptionId, $amount, $ipAddress): Stake {
            $bet = $this->mutableBet($betId);
            $this->assertOptionBelongsToBet($bet, $betOptionId);
            $this->activeContact($contactId);
            // The odds currently offered become the contract passed with the
            // bettor: they are frozen on the stake and never recomputed.
            $oddsAtBet = $this->contractualOdds($bet, $betOptionId);
            $stake = $this->stakes->create($betId, $betOptionId, $contactId, $amount, $oddsAtBet);
            $this->auditLogs->record($actorUserId, 'stake.created', 'stake', (string)$stake->id, null, $this->snapshot($stake), $ipAddress);

            return $stake;
        });
    }

    public function update(
        int     $actorUserId,
        int     $betId,
        int     $stakeId,
        int     $contactId,
        int     $betOptionId,
        string  $amount,
        ?string $ipAddress,
    ): Stake
    {
        $amount = $this->amount($amount);

        return $this->transactional(function () use ($actorUserId, $betId, $stakeId, $contactId, $betOptionId, $amount, $ipAddress): Stake {
            $bet = $this->mutableBet($betId);
            $before = $this->lockedStakeForBet($stakeId, $betId);
            $this->assertActiveStake($before);
            $this->assertOptionBelongsToBet($bet, $betOptionId);
            $this->activeContact($contactId);
            $after = $this->stakes->update($stakeId, $betOptionId, $contactId, $amount);
            $this->auditLogs->record($actorUserId, 'stake.updated', 'stake', (string)$stakeId, $this->snapshot($before), $this->snapshot($after), $ipAddress);

            return $after;
        });
    }

    public function delete(int $actorUserId, int $betId, int $stakeId, ?string $ipAddress): void
    {
        $this->transactional(function () use ($actorUserId, $betId, $stakeId, $ipAddress): void {
            $bet = $this->mutableBet($betId);
            $stake = $this->lockedStakeForBet($stakeId, $betId);
            if (!$stake->isCancelled) {
                throw new InvalidArgumentException('Stake must be cancelled before it can be deleted.');
            }
            if ($stake->isPaid) {
                throw new InvalidArgumentException('Paid stake must be marked unpaid before it can be deleted.');
            }
            $this->stakes->delete($stakeId);
            $this->auditLogs->record($actorUserId, 'stake.deleted', 'stake', (string)$stakeId, $this->snapshot($stake), null, $ipAddress);
        });
    }

    public function setPaid(int $actorUserId, int $betId, int $stakeId, bool $isPaid, ?string $ipAddress): Stake
    {
        return $this->transactional(function () use ($actorUserId, $betId, $stakeId, $isPaid, $ipAddress): Stake {
            $this->mutableBet($betId);
            $before = $this->lockedStakeForBet($stakeId, $betId);
            if ($before->isCancelled && $isPaid) {
                throw new InvalidArgumentException('Cancelled stakes cannot be marked paid.');
            }
            if ($before->contactArchived) {
                throw new InvalidArgumentException('Archived contacts cannot have their stakes changed.');
            }
            // Paying a stake only settles the cash: the odds were frozen at creation.
            $after = $this->stakes->setPaid($stakeId, $isPaid);
            $this->auditLogs->record($actorUserId, 'stake.payment_status_changed', 'stake', (string)$stakeId, $this->snapshot($before), $this->snapshot($after), $ipAddress);

            return $after;
        });
    }

    public function setCancelled(int $actorUserId, int $betId, int $stakeId, bool $isCancelled, ?string $ipAddress): Stake
    {
        return $this->transactional(function () use ($actorUserId, $betId, $stakeId, $isCancelled, $ipAddress): Stake {
            $this->mutableBet($betId);
            $before = $this->lockedStakeForBet($stakeId, $betId);
            $after = $this->stakes->setCancelled($stakeId, $isCancelled);
            $this->auditLogs->record($actorUserId, 'stake.cancellation_status_changed', 'stake', (string)$stakeId, $this->snapshot($before), $this->snapshot($after), $ipAddress);

            return $after;
        });
    }

    public function setRefunded(int $actorUserId, int $betId, int $stakeId, bool $isRefunded, ?string $ipAddress): Stake
    {
        return $this->transactional(function () use ($actorUserId, $betId, $stakeId, $isRefunded, $ipAddress): Stake {
            $bet = $this->lockedBet($betId);
            if ($bet->status !== BetStatus::Cancelled) {
                throw new InvalidArgumentException('Stakes can only be refunded when the bet is cancelled.');
            }
            $before = $this->lockedStakeForBet($stakeId, $betId);
            if ($before->isCancelled && !$isRefunded) {
                throw new InvalidArgumentException('Refund cannot be cancelled while the stake is cancelled.');
            }
            $after = $this->stakes->setPaid($stakeId, !$isRefunded);
            $this->auditLogs->record($actorUserId, 'stake.refund_status_changed', 'stake', (string)$stakeId, $this->snapshot($before), $this->snapshot($after), $ipAddress);

            return $after;
        });
    }

    /** @return list<array{contact_id: int, contact_name: string, winning_stake: int, payout: int, is_winnings_paid: bool}> */
    public function winnings(Bet $bet): array
    {
        if ($bet->status !== BetStatus::Settled || $bet->winningOptionId === null) {
            return [];
        }

        $winners = $this->stakes->findWinnersByBet($bet->id, $bet->winningOptionId);
        if ($winners === []) {
            return [];
        }

        return $winners;
    }

    public function setWinningsPaid(
        int $actorUserId,
        int $betId,
        int $contactId,
        bool $isPaid,
        ?string $ipAddress,
    ): void {
        $this->transactional(function () use ($actorUserId, $betId, $contactId, $isPaid, $ipAddress): void {
            $bet = $this->lockedBet($betId);
            if ($bet->status !== BetStatus::Settled || $bet->winningOptionId === null) {
                throw new InvalidArgumentException('Winnings can only be paid when the bet is settled.');
            }
            $winner = null;
            foreach ($this->winnings($bet) as $candidate) {
                if ($candidate['contact_id'] === $contactId) {
                    $winner = $candidate;
                    break;
                }
            }
            if ($winner === null) {
                throw new InvalidArgumentException('Unknown winner.');
            }

            $this->stakes->setWinningsPaid($betId, $bet->winningOptionId, $contactId, $isPaid);
            $this->auditLogs->record(
                $actorUserId,
                'stake.winnings_payment_status_changed',
                'bet_winner',
                sprintf('%d:%d', $betId, $contactId),
                ['is_winnings_paid' => $winner['is_winnings_paid']],
                ['is_winnings_paid' => $isPaid],
                $ipAddress,
            );
        });
    }

    /** Returns the bet with the odds currently offered on each option. */
    public function withOdds(Bet $bet): Bet
    {
        return $this->market->withOdds($bet);
    }

    private function mutableBet(int $betId): Bet
    {
        $bet = $this->lockedBet($betId);
        if ($bet->status !== BetStatus::Open) {
            throw new InvalidArgumentException('Stakes can only be changed while the bet is open.');
        }

        return $bet;
    }

    /**
     * Loads a bet with its market locked for the current transaction.
     *
     * The bet row serialises every operation able to change the indicative
     * market, so a payment and a creation never recalculate the same market
     * from two different states.
     */
    private function lockedBet(int $betId): Bet
    {
        return $this->market->lock($betId);
    }

    /**
     * Odds to freeze on a stake being created.
     *
     * Pari mutuel stakes carry no contract: their payout only comes out of the
     * pool at settlement. A fixed odds option that is still unpriced accepts no
     * stake, since there would be nothing to contract on.
     */
    private function contractualOdds(Bet $bet, int $betOptionId): ?float
    {
        if (!$bet->isFixedOdds()) {
            return null;
        }
        $odds = $this->market->currentOdds($bet, $betOptionId);
        if ($odds === null) {
            throw new InvalidArgumentException('This option has no odds yet: price it before taking a stake.');
        }

        return $odds;
    }

    private function activeContact(int $contactId): void
    {
        $contact = $this->contacts->findById($contactId) ?? throw new InvalidArgumentException('Unknown contact.');
        if ($contact->isArchived()) {
            throw new InvalidArgumentException('Archived contacts cannot be assigned to a stake.');
        }

    }

    private function assertOptionBelongsToBet(Bet $bet, int $betOptionId): void
    {
        foreach ($bet->options as $option) {
            if ($option->id === $betOptionId) {
                return;
            }
        }

        throw new InvalidArgumentException('Bet option does not belong to the bet.');
    }

    /** Must be called inside a transaction, after the bet has been locked. */
    private function lockedStakeForBet(int $stakeId, int $betId): Stake
    {
        $stake = $this->stakes->findByIdForUpdate($stakeId) ?? throw new InvalidArgumentException('Unknown stake.');
        if ($stake->betId !== $betId) {
            throw new InvalidArgumentException('Stake does not belong to the bet.');
        }

        return $stake;
    }

    private function assertActiveStake(Stake $stake): void
    {
        if ($stake->isCancelled) {
            throw new InvalidArgumentException('Cancelled stakes cannot be changed.');
        }
    }

    private function amount(string $amount): int
    {
        $amount = trim($amount);
        if (preg_match('/^[1-9]\d{0,5}$/', $amount) !== 1) {
            throw new InvalidArgumentException('Stake amount must be a whole number between 1 and 999999.');
        }
        $amount = (int)$amount;
        if ($amount > self::MAX_AMOUNT_UNITS) {
            throw new InvalidArgumentException('Stake amount must be a whole number between 1 and 999999.');
        }

        return $amount;
    }

    /** @return array<string, int|string|bool|float|null> */
    private function snapshot(Stake $stake): array
    {
        return [
            'bet_id' => $stake->betId,
            'bet_option_id' => $stake->betOptionId,
            'contact_id' => $stake->contactId,
            'amount' => $stake->amount,
            'odds_at_bet' => $stake->oddsAtBet,
            'is_paid' => $stake->isPaid,
            'is_cancelled' => $stake->isCancelled,
        ];
    }

    private function transactional(callable $operation): mixed
    {
        $this->pdo->beginTransaction();
        try {
            $result = $operation();
            $this->pdo->commit();

            return $result;
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }
}
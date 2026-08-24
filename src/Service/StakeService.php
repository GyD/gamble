<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\Bet\Bet;
use App\Domain\Bet\BetAccessDeniedException;
use App\Domain\Bet\BetStatus;
use App\Domain\Stake\Stake;
use App\Repository\AuditLogger;
use App\Repository\BetStore;
use App\Repository\ContactStore;
use App\Repository\StakeStore;
use InvalidArgumentException;
use PDO;
use Throwable;

final readonly class StakeService
{
    private const MAX_AMOUNT_CENTS = 99_999_999;

    public function __construct(
        private PDO          $pdo,
        private StakeStore   $stakes,
        private BetStore     $bets,
        private ContactStore $contacts,
        private AuditLogger  $auditLogs,
    )
    {
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
        $amountCents = $this->amountInCents($amount);

        return $this->transactional(function () use ($actorUserId, $betId, $contactId, $betOptionId, $amountCents, $ipAddress): Stake {
            $bet = $this->mutableBet($actorUserId, $betId);
            $this->assertOptionBelongsToBet($bet, $betOptionId);
            $this->activeContact($contactId);
            $stake = $this->stakes->create($betId, $betOptionId, $contactId, $amountCents);
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
        $amountCents = $this->amountInCents($amount);

        return $this->transactional(function () use ($actorUserId, $betId, $stakeId, $contactId, $betOptionId, $amountCents, $ipAddress): Stake {
            $bet = $this->mutableBet($actorUserId, $betId);
            $before = $this->stakeForBet($stakeId, $betId);
            $this->assertActiveStake($before);
            $this->assertOptionBelongsToBet($bet, $betOptionId);
            $this->activeContact($contactId);
            $after = $this->stakes->update($stakeId, $betOptionId, $contactId, $amountCents);
            $this->auditLogs->record($actorUserId, 'stake.updated', 'stake', (string)$stakeId, $this->snapshot($before), $this->snapshot($after), $ipAddress);

            return $after;
        });
    }

    public function delete(int $actorUserId, int $betId, int $stakeId, ?string $ipAddress): void
    {
        $this->transactional(function () use ($actorUserId, $betId, $stakeId, $ipAddress): void {
            $this->mutableBet($actorUserId, $betId);
            $stake = $this->stakeForBet($stakeId, $betId);
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
            $this->mutableBet($actorUserId, $betId);
            $before = $this->stakeForBet($stakeId, $betId);
            if ($before->isCancelled && $isPaid) {
                throw new InvalidArgumentException('Cancelled stakes cannot be marked paid.');
            }
            if ($before->contactArchived) {
                throw new InvalidArgumentException('Archived contacts cannot have their stakes changed.');
            }
            $after = $this->stakes->setPaid($stakeId, $isPaid);
            $this->auditLogs->record($actorUserId, 'stake.payment_status_changed', 'stake', (string)$stakeId, $this->snapshot($before), $this->snapshot($after), $ipAddress);

            return $after;
        });
    }

    public function setCancelled(int $actorUserId, int $betId, int $stakeId, bool $isCancelled, ?string $ipAddress): Stake
    {
        return $this->transactional(function () use ($actorUserId, $betId, $stakeId, $isCancelled, $ipAddress): Stake {
            $this->mutableBet($actorUserId, $betId);
            $before = $this->stakeForBet($stakeId, $betId);
            $after = $this->stakes->setCancelled($stakeId, $isCancelled);
            $this->auditLogs->record($actorUserId, 'stake.cancellation_status_changed', 'stake', (string)$stakeId, $this->snapshot($before), $this->snapshot($after), $ipAddress);

            return $after;
        });
    }

    public function setRefunded(int $actorUserId, int $betId, int $stakeId, bool $isRefunded, ?string $ipAddress): Stake
    {
        return $this->transactional(function () use ($actorUserId, $betId, $stakeId, $isRefunded, $ipAddress): Stake {
            $bet = $this->ownedBet($actorUserId, $betId);
            if ($bet->status !== BetStatus::Cancelled) {
                throw new InvalidArgumentException('Stakes can only be refunded when the bet is cancelled.');
            }
            $before = $this->stakeForBet($stakeId, $betId);
            if ($before->isCancelled && !$isRefunded) {
                throw new InvalidArgumentException('Refund cannot be cancelled while the stake is cancelled.');
            }
            $after = $this->stakes->setPaid($stakeId, !$isRefunded);
            $this->auditLogs->record($actorUserId, 'stake.refund_status_changed', 'stake', (string)$stakeId, $this->snapshot($before), $this->snapshot($after), $ipAddress);

            return $after;
        });
    }

    /** @return list<array{contact_id: int, contact_name: string, winning_stake_cents: int, payout_cents: int, is_winnings_paid: bool}> */
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
            $bet = $this->ownedBet($actorUserId, $betId);
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

    private function mutableBet(int $actorUserId, int $betId): Bet
    {
        $bet = $this->ownedBet($actorUserId, $betId);
        if ($bet->status !== BetStatus::Open) {
            throw new InvalidArgumentException('Stakes can only be changed while the bet is open.');
        }

        return $bet;
    }

    private function ownedBet(int $actorUserId, int $betId): Bet
    {
        $bet = $this->bets->findById($betId) ?? throw new InvalidArgumentException('Unknown bet.');
        if (!$bet->isOwnedBy($actorUserId)) {
            throw new BetAccessDeniedException('Only the bet owner can manage its stakes.');
        }

        return $bet;
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

    private function stakeForBet(int $stakeId, int $betId): Stake
    {
        $stake = $this->stakes->findById($stakeId) ?? throw new InvalidArgumentException('Unknown stake.');
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

    private function amountInCents(string $amount): int
    {
        $amount = str_replace(',', '.', trim($amount));
        if (preg_match('/^(\d{1,6})(?:\.(\d{1,2}))?$/', $amount, $matches) !== 1) {
            throw new InvalidArgumentException('Stake amount must be a positive amount with at most two decimals.');
        }
        $amountCents = ((int)$matches[1] * 100) + (int)str_pad($matches[2] ?? '', 2, '0');
        if ($amountCents < 1 || $amountCents > self::MAX_AMOUNT_CENTS) {
            throw new InvalidArgumentException('Stake amount must be between 0.01 and 999999.99.');
        }

        return $amountCents;
    }

    /** @return array<string, int|string|bool> */
    private function snapshot(Stake $stake): array
    {
        return [
            'bet_id' => $stake->betId,
            'bet_option_id' => $stake->betOptionId,
            'contact_id' => $stake->contactId,
            'amount_cents' => $stake->amountCents,
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
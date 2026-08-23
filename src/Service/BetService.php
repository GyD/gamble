<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\Bet\Bet;
use App\Domain\Bet\BetAccessDeniedException;
use App\Domain\Bet\BetStatus;
use App\Repository\AuditLogger;
use App\Repository\BetStore;
use App\Repository\StakeStore;
use DateTimeImmutable;
use InvalidArgumentException;
use PDO;
use Throwable;

final readonly class BetService
{
    public function __construct(
        private PDO $pdo,
        private BetStore $bets,
        private StakeStore $stakes,
        private AuditLogger $auditLogs,
    ) {
    }

    /** @param list<string> $options */
    public function create(
        int $actorUserId,
        string $question,
        ?string $description,
        ?string $closesAt,
        array $options,
        ?string $ipAddress,
    ): Bet {
        [$question, $description, $deadline, $options] = $this->normalize($question, $description, $closesAt, $options);

        return $this->transactional(function () use ($actorUserId, $question, $description, $deadline, $options, $ipAddress): Bet {
            $bet = $this->bets->create($actorUserId, $question, $description, $deadline, $options);
            $this->auditLogs->record($actorUserId, 'bet.created', 'bet', (string) $bet->id, null, $this->snapshot($bet), $ipAddress);

            return $bet;
        });
    }

    /** @param list<string> $options */
    public function update(
        int $actorUserId,
        int $betId,
        string $question,
        ?string $description,
        ?string $closesAt,
        array $options,
        ?string $ipAddress,
    ): Bet {
        [$question, $description, $deadline, $options] = $this->normalize($question, $description, $closesAt, $options);

        return $this->transactional(function () use ($actorUserId, $betId, $question, $description, $deadline, $options, $ipAddress): Bet {
            $before = $this->ownedBet($actorUserId, $betId);
            if ($before->status !== BetStatus::Open) {
                throw new InvalidArgumentException('Only open bets can be edited.');
            }
            $after = $this->bets->update($betId, $question, $description, $deadline, $options);
            $this->auditLogs->record($actorUserId, 'bet.updated', 'bet', (string) $betId, $this->snapshot($before), $this->snapshot($after), $ipAddress);

            return $after;
        });
    }

    public function close(int $actorUserId, int $betId, ?string $ipAddress): Bet
    {
        return $this->transition($actorUserId, $betId, BetStatus::Open, BetStatus::Closed, null, 'bet.closed', $ipAddress);
    }

    public function cancel(int $actorUserId, int $betId, ?string $ipAddress): Bet
    {
        return $this->transactional(function () use ($actorUserId, $betId, $ipAddress): Bet {
            $before = $this->ownedBet($actorUserId, $betId);
            if (!in_array($before->status, [BetStatus::Open, BetStatus::Closed], true)) {
                throw new InvalidArgumentException('Only open or closed bets can be cancelled.');
            }
            $after = $this->bets->changeStatus($betId, BetStatus::Cancelled, null);
            $this->auditLogs->record($actorUserId, 'bet.cancelled', 'bet', (string) $betId, $this->snapshot($before), $this->snapshot($after), $ipAddress);

            return $after;
        });
    }

    public function delete(int $actorUserId, int $betId, ?string $ipAddress): void
    {
        $this->transactional(function () use ($actorUserId, $betId, $ipAddress): void {
            $bet = $this->ownedBet($actorUserId, $betId);
            if ($bet->status !== BetStatus::Cancelled) {
                throw new InvalidArgumentException('Only cancelled bets can be deleted.');
            }
            foreach ($this->stakes->findByBet($betId) as $stake) {
                if ($stake->isPaid) {
                    throw new InvalidArgumentException('All paid stakes must be refunded before deleting the bet.');
                }
            }
            $this->bets->delete($betId);
            $this->auditLogs->record($actorUserId, 'bet.deleted', 'bet', (string) $betId, $this->snapshot($bet), null, $ipAddress);
        });
    }

    public function settle(int $actorUserId, int $betId, int $winningOptionId, ?string $ipAddress): Bet
    {
        $bet = $this->ownedBet($actorUserId, $betId);
        if (!in_array($winningOptionId, array_map(static fn($option): int => $option->id, $bet->options), true)) {
            throw new InvalidArgumentException('Winning option does not belong to the bet.');
        }

        return $this->transition(
            $actorUserId,
            $betId,
            BetStatus::Closed,
            BetStatus::Settled,
            $winningOptionId,
            'bet.settled',
            $ipAddress,
        );
    }

    private function transition(
        int $actorUserId,
        int $betId,
        BetStatus $expectedStatus,
        BetStatus $newStatus,
        ?int $winningOptionId,
        string $action,
        ?string $ipAddress,
    ): Bet {
        return $this->transactional(function () use ($actorUserId, $betId, $expectedStatus, $newStatus, $winningOptionId, $action, $ipAddress): Bet {
            $before = $this->ownedBet($actorUserId, $betId);
            if ($before->status !== $expectedStatus) {
                throw new InvalidArgumentException(sprintf(
                    'Bet must be %s to become %s.',
                    $expectedStatus->value,
                    $newStatus->value,
                ));
            }
            $after = $this->bets->changeStatus($betId, $newStatus, $winningOptionId);
            $this->auditLogs->record($actorUserId, $action, 'bet', (string) $betId, $this->snapshot($before), $this->snapshot($after), $ipAddress);

            return $after;
        });
    }

    /**
     * @param list<string> $options
     * @return array{string, string|null, DateTimeImmutable|null, list<string>}
     */
    private function normalize(string $question, ?string $description, ?string $closesAt, array $options): array
    {
        $question = trim($question);
        $description = $description === null ? null : trim($description);
        $description = $description === '' ? null : $description;
        if ($question === '') {
            throw new InvalidArgumentException('Bet question is required.');
        }
        if (mb_strlen($question) > 255) {
            throw new InvalidArgumentException('Bet question cannot exceed 255 characters.');
        }
        if ($description !== null && mb_strlen($description) > 2000) {
            throw new InvalidArgumentException('Bet description cannot exceed 2000 characters.');
        }

        $normalizedOptions = [];
        foreach ($options as $option) {
            $option = trim($option);
            if ($option === '') {
                continue;
            }
            if (mb_strlen($option) > 120) {
                throw new InvalidArgumentException('Bet options cannot exceed 120 characters.');
            }
            if (in_array(mb_strtolower($option), array_map('mb_strtolower', $normalizedOptions), true)) {
                throw new InvalidArgumentException('Bet options must be unique.');
            }
            $normalizedOptions[] = $option;
        }
        if (count($normalizedOptions) < 2 || count($normalizedOptions) > 20) {
            throw new InvalidArgumentException('A bet must contain between 2 and 20 options.');
        }

        return [$question, $description, $this->parseDeadline($closesAt), $normalizedOptions];
    }

    private function parseDeadline(?string $value): ?DateTimeImmutable
    {
        $value = $value === null ? '' : trim($value);
        if ($value === '') {
            return null;
        }
        $deadline = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $value);
        $errors = DateTimeImmutable::getLastErrors();
        if ($deadline === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            throw new InvalidArgumentException('Invalid closing date.');
        }

        return $deadline;
    }

    private function ownedBet(int $actorUserId, int $betId): Bet
    {
        $bet = $this->bets->findById($betId) ?? throw new InvalidArgumentException('Unknown bet.');
        if (!$bet->isOwnedBy($actorUserId)) {
            throw new BetAccessDeniedException('Only the bet owner can change it.');
        }

        return $bet;
    }

    /** @return array<string, mixed> */
    private function snapshot(Bet $bet): array
    {
        return [
            'owner_user_id' => $bet->ownerUserId,
            'question' => $bet->question,
            'description' => $bet->description,
            'closes_at' => $bet->closesAt?->format(DATE_ATOM),
            'status' => $bet->status->value,
            'winning_option_id' => $bet->winningOptionId,
            'options' => array_map(static fn($option): array => ['id' => $option->id, 'label' => $option->label], $bet->options),
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
<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\Bet\Bet;
use App\Domain\Bet\BetStatus;
use App\Domain\Bet\BettingMode;
use App\Domain\Bet\OddsEvolutionMode;
use App\Repository\AuditLogger;
use App\Repository\BetStore;
use App\Repository\StakeStore;
use App\Service\Market\BetExposure;
use App\Service\Market\MarketRecalculator;
use App\Service\Market\MarketServiceRegistry;
use DateTimeImmutable;
use InvalidArgumentException;
use PDO;
use Throwable;

final readonly class BetService
{
    private MarketServiceRegistry $markets;
    private MarketRecalculator $recalculator;

    public function __construct(
        private PDO $pdo,
        private BetStore $bets,
        private StakeStore $stakes,
        private AuditLogger $auditLogs,
        ?MarketServiceRegistry $markets = null,
        ?MarketRecalculator $recalculator = null,
    ) {
        $this->markets = $markets ?? new MarketServiceRegistry();
        $this->recalculator = $recalculator ?? new MarketRecalculator($bets, $stakes, $this->markets);
    }

    /**
     * @param list<string> $options
     * @param list<string> $odds odds typed by the bookmaker, aligned with $options
     */
    public function create(
        int $actorUserId,
        string $question,
        ?string $description,
        ?string $closesAt,
        array $options,
        ?string $ipAddress,
        ?string $bettingMode = null,
        ?string $oddsEvolutionMode = null,
        array $odds = [],
    ): Bet {
        [$question, $description, $deadline, $options] = $this->normalize($question, $description, $closesAt, $options);
        $mode = $this->parseBettingMode($bettingMode);
        $evolution = $this->parseOddsEvolutionMode($oddsEvolutionMode);
        $pricedOdds = $this->parseOdds($odds, count($options), $mode);

        return $this->transactional(function () use ($actorUserId, $question, $description, $deadline, $options, $ipAddress, $mode, $evolution, $pricedOdds): Bet {
            $bet = $this->bets->create($actorUserId, $question, $description, $deadline, $options, $mode, $evolution, $pricedOdds);
            $this->auditLogs->record($actorUserId, 'bet.created', 'bet', (string) $bet->id, null, $this->snapshot($bet), $ipAddress);

            return $bet;
        });
    }

    /**
     * @param list<string> $options
     * @param list<string> $odds odds typed by the bookmaker, aligned with $options
     */
    public function update(
        int $actorUserId,
        int $betId,
        string $question,
        ?string $description,
        ?string $closesAt,
        array $options,
        ?string $ipAddress,
        ?string $mutuelPercentage = null,
        ?string $bettingMode = null,
        ?string $oddsEvolutionMode = null,
        array $odds = [],
    ): Bet {
        [$question, $description, $deadline, $options] = $this->normalize($question, $description, $closesAt, $options);
        $rateBps = $mutuelPercentage === null ? null : $this->parseMutuelCommissionRate($mutuelPercentage);
        $requestedMode = $bettingMode === null ? null : $this->parseBettingMode($bettingMode);
        $requestedEvolution = $oddsEvolutionMode === null ? null : $this->parseOddsEvolutionMode($oddsEvolutionMode);

        return $this->transactional(function () use ($actorUserId, $betId, $question, $description, $deadline, $options, $ipAddress, $rateBps, $requestedMode, $requestedEvolution, $odds): Bet {
            $before = $this->lockedBet($betId);
            if ($before->status !== BetStatus::Open) {
                throw new InvalidArgumentException('Only open bets can be edited.');
            }
            $currentOptions = array_map(static fn($option): string => $option->label, $before->options);
            $hasStakes = $this->stakes->findByBet($betId) !== [];
            if ($options !== $currentOptions && $hasStakes) {
                throw new InvalidArgumentException('Bet options cannot be changed once stakes have been placed.');
            }
            $mode = $requestedMode ?? $before->bettingMode;
            if ($mode !== $before->bettingMode && $hasStakes) {
                throw new InvalidArgumentException('The betting mode cannot be changed once stakes have been placed.');
            }
            $pricedOdds = $this->parseOdds($odds, count($options), $mode);
            $after = $this->bets->update($betId, $question, $description, $deadline, $options, $pricedOdds);
            $evolution = $requestedEvolution ?? $before->oddsEvolutionMode;
            if ($mode !== $before->bettingMode || $evolution !== $before->oddsEvolutionMode) {
                $after = $this->bets->setBettingMode($betId, $mode, $evolution);
            }
            if ($rateBps !== null && $rateBps !== $before->mutuelCommissionRateBps) {
                $after = $this->bets->setMutuelCommissionRate($betId, $rateBps);
            }
            $this->auditLogs->record($actorUserId, 'bet.updated', 'bet', (string) $betId, $this->snapshot($before), $this->snapshot($after), $ipAddress);

            return $after;
        });
    }

    public function hasStakes(int $betId): bool
    {
        return $this->stakes->findByBet($betId) !== [];
    }

    public function withOdds(Bet $bet): Bet
    {
        return $this->recalculator->withOdds($bet);
    }

    /** Exposure the bookmaker carries on each option, to price the next odds. */
    public function exposure(Bet $bet): BetExposure
    {
        return $this->recalculator->exposure($bet);
    }

    /**
     * Republishes the odds typed by the bookmaker.
     *
     * Pricing the book restarts the drift, and the stakes already taken keep the
     * odds frozen when they were created.
     *
     * @param array<int|string, string> $oddsByOptionId odds typed per option id
     */
    public function anchorOptionOdds(int $actorUserId, int $betId, array $oddsByOptionId, ?string $ipAddress): Bet
    {
        return $this->transactional(function () use ($actorUserId, $betId, $oddsByOptionId, $ipAddress): Bet {
            $before = $this->lockedBet($betId);
            if ($before->status !== BetStatus::Open) {
                throw new InvalidArgumentException('Odds can only be priced while the bet is open.');
            }
            if (!$before->isFixedOdds()) {
                throw new InvalidArgumentException('Only fixed odds bets carry priced odds.');
            }
            $after = $this->bets->setOptionOdds($betId, $this->parseOddsByOptionId($before, $oddsByOptionId));
            $this->auditLogs->record($actorUserId, 'bet.odds_priced', 'bet', (string)$betId, $this->snapshot($before), $this->snapshot($after), $ipAddress);

            return $after;
        });
    }

    public function setMutuelCommissionRate(int $actorUserId, int $betId, string $percentage, ?string $ipAddress): Bet
    {
        $rateBps = $this->parseMutuelCommissionRate($percentage);

        return $this->transactional(function () use ($actorUserId, $betId, $rateBps, $ipAddress): Bet {
            $before = $this->lockedBet($betId);
            if ($before->status !== BetStatus::Open) {
                throw new InvalidArgumentException('Commission can only be changed while the bet is open.');
            }
            if ($before->isFixedOdds()) {
                throw new InvalidArgumentException('Fixed odds bets carry their margin in the odds themselves.');
            }
            $after = $this->bets->setMutuelCommissionRate($betId, $rateBps);
            $this->auditLogs->record($actorUserId, 'bet.commission_changed', 'bet', (string)$betId, $this->snapshot($before), $this->snapshot($after), $ipAddress);

            return $after;
        });
    }

    private function parseMutuelCommissionRate(string $percentage): int
    {
        if (!preg_match('/^(?:\d|1\d|2[0-5])(?:[.,]\d{1,2})?$/', trim($percentage))) {
            throw new InvalidArgumentException('Commission must be between 0% and 25%.');
        }

        return (int) round((float) str_replace(',', '.', $percentage) * 100);
    }

    private function parseBettingMode(?string $mode): BettingMode
    {
        $mode = $mode === null ? '' : trim($mode);
        if ($mode === '') {
            return BettingMode::FixedOdds;
        }

        return BettingMode::tryFrom($mode) ?? throw new InvalidArgumentException('Unknown betting mode.');
    }

    private function parseOddsEvolutionMode(?string $mode): OddsEvolutionMode
    {
        $mode = $mode === null ? '' : trim($mode);
        if ($mode === '') {
            // Odds typed by hand stay as typed unless the bookmaker asks for a drift.
            return OddsEvolutionMode::Fixed;
        }

        return OddsEvolutionMode::tryFrom($mode) ?? throw new InvalidArgumentException('Unknown odds evolution mode.');
    }

    /**
     * Odds typed by the bookmaker, aligned with the submitted options.
     *
     * Odds only exist in fixed odds mode. An empty submission leaves the book
     * unpriced, and an unpriced option simply accepts no stake yet.
     *
     * @param list<string> $odds
     * @return list<float|null>
     */
    private function parseOdds(array $odds, int $optionCount, BettingMode $mode): array
    {
        $values = array_values(array_filter(
            array_map(static fn(mixed $value): string => trim((string) $value), $odds),
            static fn(string $value): bool => $value !== '',
        ));
        if ($values === []) {
            return [];
        }
        if ($mode !== BettingMode::FixedOdds) {
            throw new InvalidArgumentException('Odds can only be set on fixed odds bets.');
        }
        if (count($values) !== $optionCount) {
            throw new InvalidArgumentException('Odds are required for each option.');
        }

        return array_map($this->parseSingleOdds(...), $values);
    }

    /**
     * Odds typed per option id, restricted to the options of the bet.
     *
     * A blank field unprices its option instead of keeping a stale price.
     *
     * @param array<int|string, string> $oddsByOptionId
     * @return array<int, float|null>
     */
    private function parseOddsByOptionId(Bet $bet, array $oddsByOptionId): array
    {
        $parsed = [];
        foreach ($bet->options as $option) {
            if (!array_key_exists($option->id, $oddsByOptionId)) {
                continue;
            }
            $value = trim((string) $oddsByOptionId[$option->id]);
            $parsed[$option->id] = $value === '' ? null : $this->parseSingleOdds($value);
        }
        if ($parsed === []) {
            throw new InvalidArgumentException('Odds are required for each option.');
        }

        return $parsed;
    }

    private function parseSingleOdds(string $value): float
    {
        if (preg_match('/^\d{1,4}(?:[.,]\d{1,2})?$/', $value) !== 1) {
            throw new InvalidArgumentException('Odds must be a number between 1.01 and 1000.');
        }
        $odds = round((float) str_replace(',', '.', $value), 2);
        if ($odds < 1.01 || $odds > 1000.0) {
            throw new InvalidArgumentException('Odds must be a number between 1.01 and 1000.');
        }

        return $odds;
    }

    public function close(int $actorUserId, int $betId, ?string $ipAddress): Bet
    {
        return $this->transition($actorUserId, $betId, BetStatus::Open, BetStatus::Closed, null, 'bet.closed', $ipAddress);
    }

    public function cancel(int $actorUserId, int $betId, ?string $ipAddress): Bet
    {
        return $this->transactional(function () use ($actorUserId, $betId, $ipAddress): Bet {
            $before = $this->lockedBet($betId);
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
            $bet = $this->lockedBet($betId);
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
        return $this->transactional(function () use ($actorUserId, $betId, $winningOptionId, $ipAddress): Bet {
            $before = $this->lockedBet($betId);
            if ($before->status !== BetStatus::Closed) {
                throw new InvalidArgumentException('Bet must be closed to become settled.');
            }
            if (!in_array($winningOptionId, array_map(static fn($option): int => $option->id, $before->options), true)) {
                throw new InvalidArgumentException('Winning option does not belong to the bet.');
            }
            $financials = $this->markets->forBet($before)->settle(
                $before,
                $this->stakes->findByBet($betId),
                $winningOptionId,
            );
            $this->stakes->setFinalPayouts($betId, $financials->payoutsByStakeId);
            $after = $this->bets->settleFinancials($betId, $winningOptionId, $financials->pot,
                $financials->bookmakerShare, $financials->redistributed, $financials->bookmakerResult,
                $financials->oddsByOptionId);
            $this->auditLogs->record($actorUserId, 'bet.settled', 'bet', (string)$betId, $this->snapshot($before), $this->snapshot($after), $ipAddress);
            return $after;
        });
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
            $before = $this->lockedBet($betId);
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

    /**
     * Loads a bet with its market locked for the current transaction.
     *
     * Every operation able to change the indicative market must go through it
     * so concurrent recalculations are serialised on the bet row.
     */
    private function lockedBet(int $betId): Bet
    {
        return $this->recalculator->lock($betId);
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
            'betting_mode' => $bet->bettingMode->value,
            'odds_evolution_mode' => $bet->oddsEvolutionMode->value,
            'odds_anchored_at' => $bet->oddsAnchoredAt?->format(DATE_ATOM),
            'mutuel_commission_rate_bps' => $bet->mutuelCommissionRateBps,
            'final_pot' => $bet->finalPot,
            'final_bookmaker_share' => $bet->finalBookmakerShare,
            'final_redistributed' => $bet->finalRedistributed,
            'final_bookmaker_result' => $bet->finalBookmakerResult,
            'options' => array_map(static fn($option): array => [
                'id' => $option->id,
                'label' => $option->label,
                'odds' => $option->odds,
            ], $bet->options),
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
<?php

declare(strict_types=1);

namespace App\Service\Market;

use App\Domain\Bet\Bet;
use App\Domain\Bet\BetStatus;
use App\Repository\BetStore;
use App\Repository\StakeStore;
use InvalidArgumentException;

/**
 * Entry point of every market read and recalculation.
 *
 * The bet row acts as the serialisation lock of its own market: any operation
 * able to change the indicative state (stake creation, payment, cancellation,
 * amount change) must lock the bet first so two concurrent operations never
 * recalculate the same market from two different states.
 */
final readonly class MarketRecalculator
{
    public function __construct(
        private BetStore $bets,
        private StakeStore $stakes,
        private MarketServiceRegistry $markets = new MarketServiceRegistry(),
    ) {
    }

    /**
     * Locks the market of a bet for the current transaction.
     *
     * Must be called inside a transaction.
     */
    public function lock(int $betId): Bet
    {
        return $this->bets->findByIdForUpdate($betId) ?? throw new InvalidArgumentException('Unknown bet.');
    }

    /** Indicative state of the market of a bet. */
    public function quote(Bet $bet): MarketQuote
    {
        return $this->markets->forBet($bet)->quote($bet, $this->stakes->findByBet($bet->id));
    }

    /** Odds currently offered on an option, or null when none can be quoted. */
    public function currentOdds(Bet $bet, int $betOptionId): ?float
    {
        return $this->quote($bet)->odds($betOptionId);
    }

    /**
     * Returns the bet with its options carrying the indicative odds.
     *
     * A settled bet keeps the odds recorded at settlement.
     */
    public function withOdds(Bet $bet): Bet
    {
        if ($bet->status === BetStatus::Settled || $bet->options === []) {
            return $bet;
        }
        $quote = $this->quote($bet);

        return $bet->withOptions(array_map(
            static fn($option) => $option->withOdds($quote->odds($option->id)),
            $bet->options,
        ));
    }

    /**
     * Persists the current probabilities of the options.
     *
     * Must be called inside a transaction, after the bet has been locked.
     */
    public function recalculate(Bet $bet): void
    {
        if (!$bet->isFixedOdds() || $bet->options === []) {
            return;
        }
        $quote = $this->quote($bet);
        if ($quote->probabilitiesByOptionId === []) {
            return;
        }
        $this->bets->updateCurrentProbabilities($bet->id, $quote->probabilitiesByOptionId);
    }
}

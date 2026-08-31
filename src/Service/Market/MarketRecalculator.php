<?php

declare(strict_types=1);

namespace App\Service\Market;

use App\Domain\Bet\Bet;
use App\Domain\Bet\BetStatus;
use App\Repository\BetStore;
use App\Repository\StakeStore;
use InvalidArgumentException;

/**
 * Entry point of every market read.
 *
 * The bet row acts as the serialisation lock of its own market: any operation
 * able to change the offered odds (stake creation, payment, cancellation,
 * amount change) must lock the bet first so two concurrent operations never
 * read the same market from two different states.
 *
 * Nothing is persisted here: the offered odds are always derived from the odds
 * priced by the bookmaker and from the stakes placed since.
 */
final readonly class MarketRecalculator
{
    public function __construct(
        private BetStore $bets,
        private StakeStore $stakes,
        private MarketServiceRegistry $markets = new MarketServiceRegistry(),
        private ExposureCalculator $exposures = new ExposureCalculator(),
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

    /** Odds currently offered on the options of a bet. */
    public function quote(Bet $bet): MarketQuote
    {
        return $this->markets->forBet($bet)->quote($bet, $this->stakes->findByBet($bet->id));
    }

    /** Odds currently offered on an option, or null when the option is unpriced. */
    public function currentOdds(Bet $bet, int $betOptionId): ?float
    {
        return $this->quote($bet)->odds($betOptionId);
    }

    /**
     * Returns the bet with its options carrying the odds currently offered.
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
            static fn($option) => $option->withOfferedOdds($quote->odds($option->id)),
            $bet->options,
        ));
    }

    /** What the bookmaker collected and what they owe on each option. */
    public function exposure(Bet $bet): BetExposure
    {
        return $this->exposures->calculate($this->withOdds($bet), $this->stakes->findByBet($bet->id));
    }
}

<?php

declare(strict_types=1);

namespace App\Service\Market;

use App\Domain\Bet\Bet;
use App\Domain\Bet\BetStatus;
use App\Domain\Stake\Stake;
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
    private PaymentOddsCalculator $paymentOdds;

    public function __construct(
        private BetStore $bets,
        private StakeStore $stakes,
        private MarketServiceRegistry $markets = new MarketServiceRegistry(),
        private ExposureCalculator $exposures = new ExposureCalculator(),
        ?PaymentOddsCalculator $paymentOdds = null,
    ) {
        $this->paymentOdds = $paymentOdds ?? new PaymentOddsCalculator($this->markets);
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

    /**
     * Odds a stake would capture if it were paid right now.
     *
     * The stake never prices itself: its own influence is taken out of the
     * market before the odds are quoted, while every other stake keeps its
     * usual weight.
     */
    public function paymentOdds(Bet $bet, Stake $stake): ?float
    {
        return $this->paymentOdds->forStake($bet, $this->stakes->findByBet($bet->id), $stake);
    }

    /**
     * Payment odds of every stake of a bet still waiting for its contract.
     *
     * @return array<int, float|null> odds keyed by stake id
     */
    public function paymentOddsByStake(Bet $bet): array
    {
        return $this->paymentOdds->byStake($bet, $this->stakes->findByBet($bet->id));
    }

    /** What the bookmaker collected and what they owe on each option. */
    public function exposure(Bet $bet): BetExposure
    {
        $stakes = $this->stakes->findByBet($bet->id);

        return $this->exposures->calculate(
            $this->withOdds($bet),
            $stakes,
            // Each unpaid stake is projected at its own payment odds, so it is
            // never valued at a price it degraded itself.
            $this->paymentOdds->byStake($bet, $stakes),
        );
    }
}

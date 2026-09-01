<?php

declare(strict_types=1);

namespace Tests\Unit\Service\Market;

use App\Domain\Bet\Bet;
use App\Domain\Bet\BetOption;
use App\Domain\Bet\BetStatus;
use App\Domain\Bet\BettingMode;
use App\Domain\Bet\OddsEvolutionMode;
use App\Domain\Stake\Stake;
use App\Service\Market\FixedOddsMarketService;
use App\Service\Market\MarketServiceRegistry;
use App\Service\Market\MarketSettings;
use App\Service\Market\PaymentOddsCalculator;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class PaymentOddsCalculatorTest extends TestCase
{
    private PaymentOddsCalculator $calculator;
    private FixedOddsMarketService $market;

    protected function setUp(): void
    {
        $this->calculator = new PaymentOddsCalculator(new MarketServiceRegistry());
        $this->market = new FixedOddsMarketService(new MarketSettings());
    }

    public function testASingleUnpaidStakeMovesThePublicOddsButNotItsOwnPaymentOdds(): void
    {
        $bet = $this->bet([2.50, 2.50]);
        $alice = $this->unpaidStake(1, 10, 100);

        $public = $this->market->quote($bet, [$alice])->odds(10);

        // The public price offered to the next bettors carries Alice's weight…
        self::assertNotNull($public);
        self::assertLessThan(2.50, $public);
        // …but Alice herself is quoted the market without her own contribution.
        self::assertSame(2.50, $this->calculator->forStake($bet, [$alice], $alice));
    }

    public function testTheObservedScenarioOfAStakeAloneOnTheMarket(): void
    {
        // A = 2.50, Alice stakes 100 unpaid on A, no other activity.
        $bet = $this->bet([2.50, 2.50]);
        $alice = $this->unpaidStake(1, 10, 100);

        self::assertSame(2.47, $this->market->quote($bet, [$alice])->odds(10));
        self::assertSame(2.50, $this->calculator->forStake($bet, [$alice], $alice));

        // Once paid at 2.50, her weight moves from 0.50 to 1.00 and the public
        // odds offered to the next bettors move again.
        $paid = $this->paidStake(1, 10, 100, 2.50);
        self::assertSame(2.45, $this->market->quote($bet, [$paid])->odds(10));
    }

    public function testMovementsCausedByTheOtherStakesAreKeptInThePaymentOdds(): void
    {
        // Money arriving on B lengthens A: Alice gets that new market price,
        // her own contribution excluded, never her historical quoted odds.
        $bet = $this->bet([2.50, 2.50]);
        $alice = $this->unpaidStake(1, 10, 100);
        $onOtherOption = $this->paidStake(2, 11, 400, 2.50);

        $paymentOdds = $this->calculator->forStake($bet, [$alice, $onOtherOption], $alice);

        self::assertNotNull($paymentOdds);
        self::assertGreaterThan(2.50, $paymentOdds);
        // Payment odds are not the odds announced at creation.
        self::assertNotSame($alice->quotedOdds, $paymentOdds);
    }

    public function testAStakeOnlyExcludesItselfAndKeepsSeeingTheOtherUnpaidStakes(): void
    {
        $bet = $this->bet([2.50, 2.50]);
        $alice = $this->unpaidStake(1, 10, 100);
        $bob = $this->unpaidStake(2, 11, 300);

        $withBob = $this->calculator->forStake($bet, [$alice, $bob], $alice);
        $alone = $this->calculator->forStake($bet, [$alice], $alice);

        // Bob still weighs on Alice's payment odds with the unpaid weight.
        self::assertNotSame($alone, $withBob);
        self::assertNotNull($withBob);
        self::assertGreaterThan(2.50, $withBob);
        // And Alice weighs on Bob's, symmetrically: neither of them is removed
        // from the market of the other.
        self::assertSame(
            $this->market->quote($bet, [$alice])->odds(11),
            $this->calculator->forStake($bet, [$alice, $bob], $bob),
        );
    }

    public function testEachUnpaidStakeCarriesItsOwnPaymentOdds(): void
    {
        $bet = $this->bet([2.50, 2.50]);
        $alice = $this->unpaidStake(1, 10, 100);
        $carol = $this->unpaidStake(3, 11, 300);

        $odds = $this->calculator->byStake($bet, [$alice, $carol]);

        self::assertSame([1, 3], array_keys($odds));
        self::assertNotSame($odds[1], $odds[3]);
        // Each of them is quoted the market minus itself only.
        self::assertSame($this->calculator->forStake($bet, [$alice, $carol], $alice), $odds[1]);
        self::assertSame($this->calculator->forStake($bet, [$alice, $carol], $carol), $odds[3]);
    }

    public function testPaidStakesInfluenceThePaymentOddsOfAnUnpaidStake(): void
    {
        $bet = $this->bet([2.50, 2.50]);
        $alice = $this->unpaidStake(1, 10, 100);
        $heavilyBackedSameOption = $this->paidStake(2, 10, 900, 2.50);

        $withPaid = $this->calculator->forStake($bet, [$alice, $heavilyBackedSameOption], $alice);

        // The paid stake keeps its full weight, so Alice is quoted a shortened
        // price even though her own contribution is excluded.
        self::assertNotNull($withPaid);
        self::assertLessThan(2.50, $withPaid);
    }

    public function testOnlyTheStakesWithoutContractAreQuoted(): void
    {
        $bet = $this->bet([2.50, 2.50]);
        $stakes = [
            $this->unpaidStake(1, 10, 100),
            $this->paidStake(2, 10, 100, 2.50),
            // Unpaid again after a first payment: the contract stays attached.
            new Stake(3, 1, 11, 23, 100, 'Carol', 'Red', false, false, false, null, 2.50, new DateTimeImmutable(), 2.50),
        ];

        self::assertSame([1], array_keys($this->calculator->byStake($bet, $stakes)));
    }

    public function testACancelledStakeNeitherPricesItselfNorTheOthers(): void
    {
        $bet = $this->bet([2.50, 2.50]);
        $alice = $this->unpaidStake(1, 10, 100);
        $cancelled = new Stake(2, 1, 11, 22, 900, 'Bob', 'Red', false, false, true, null, null, new DateTimeImmutable(), 2.50);

        // A cancelled stake already carries no market weight, so excluding it
        // changes nothing for the others.
        self::assertSame(
            $this->calculator->forStake($bet, [$alice], $alice),
            $this->calculator->forStake($bet, [$alice, $cancelled], $alice),
        );
    }

    public function testAnUnpricedOptionStillHasNoPaymentOdds(): void
    {
        $bet = $this->bet([null, 2.50]);
        $alice = $this->unpaidStake(1, 10, 100);

        self::assertNull($this->calculator->forStake($bet, [$alice], $alice));
    }

    public function testAFixedEvolutionQuotesThePricedOddsWithOrWithoutTheStake(): void
    {
        $bet = $this->bet([2.50, 2.50], OddsEvolutionMode::Fixed);
        $alice = $this->unpaidStake(1, 10, 100);

        self::assertSame(2.50, $this->calculator->forStake($bet, [$alice], $alice));
    }

    public function testPariMutuelStakesAreNeverSelfExcluded(): void
    {
        // A pari mutuel price is the pool itself: removing a stake from the pool
        // it belongs to would quote a payout the bookmaker never honours.
        $bet = $this->bet([null, null], OddsEvolutionMode::Fixed, BettingMode::PariMutuel);
        $alice = $this->unpaidStake(1, 10, 100);

        self::assertSame(
            $this->market->quote($bet, [$alice])->odds(10),
            null,
        );
        self::assertNotNull($this->calculator->forStake($bet, [$alice], $alice));
    }

    /** @param list<float|null> $odds */
    private function bet(
        array $odds,
        OddsEvolutionMode $evolution = OddsEvolutionMode::DynamicNormal,
        BettingMode $mode = BettingMode::FixedOdds,
    ): Bet {
        return new Bet(1, 7, 'Winner?', null, null, BetStatus::Open, null, [
            new BetOption(10, 'Blue', 1, $odds[0]),
            new BetOption(11, 'Red', 2, $odds[1]),
        ], null, null, null, $mode, $evolution, 1000);
    }

    private function unpaidStake(int $id, int $optionId, int $amount): Stake
    {
        return new Stake($id, 1, $optionId, 20 + $id, $amount, 'Alice', 'Blue', false, false, false, null, null, new DateTimeImmutable(), 2.50);
    }

    private function paidStake(int $id, int $optionId, int $amount, float $oddsAtBet): Stake
    {
        return new Stake($id, 1, $optionId, 20 + $id, $amount, 'Bob', 'Red', false, true, false, null, $oddsAtBet, new DateTimeImmutable(), $oddsAtBet);
    }
}

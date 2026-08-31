<?php

declare(strict_types=1);

namespace Tests\Unit\Repository;

use App\Repository\StakeRepository;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

final class StakeRepositoryTest extends TestCase
{
    public function testWinnersAreAggregatedAndUnpaidAndCancelledStakesAreExcludedFromWinningsAndPot(): void
    {
        $repository = new StakeRepository($this->database());

        $winners = $repository->findWinnersByBet(1, 10);

        self::assertCount(2, $winners);
        self::assertSame([1, 2], array_column($winners, 'contact_id'));
        self::assertSame([300, 300], array_column($winners, 'winning_stake'));
        self::assertSame([450, 500], array_column($winners, 'payout'));
        self::assertSame([false, true], array_column($winners, 'is_winnings_paid'));
    }

    public function testWinningsPaymentStatusUpdatesEveryPaidActiveWinningStakeForContact(): void
    {
        $repository = new StakeRepository($this->database());

        $repository->setWinningsPaid(1, 10, 1, true);
        $winner = $repository->findWinnersByBet(1, 10)[0];

        self::assertTrue($winner['is_winnings_paid']);
    }

    public function testDatabaseRejectsStakeAmountThatIsNotAWholeUnit(): void
    {
        $repository = new StakeRepository($this->database());

        $this->expectException(PDOException::class);
        $repository->create(1, 10, 1, 1_000_000);
    }

    public function testCreatedStakeOnlyRecordsTheAnnouncedOdds(): void
    {
        $repository = new StakeRepository($this->database());

        $stake = $repository->create(1, 10, 1, 100, 2.50);

        self::assertSame(2.50, $stake->quotedOdds);
        self::assertNull($stake->oddsAtBet);
        self::assertFalse($stake->hasContractualOdds());
    }

    public function testContractualOddsAreWrittenOnceAndNeverRewritten(): void
    {
        $repository = new StakeRepository($this->database());
        $stake = $repository->create(1, 10, 1, 100, 2.50);

        $captured = $repository->captureOddsAtBet($stake->id, 1.80);
        $recaptured = $repository->captureOddsAtBet($stake->id, 5.00);

        self::assertSame(1.80, $captured->oddsAtBet);
        self::assertSame(1.80, $recaptured->oddsAtBet);
        // The announced price stays untouched by the capture.
        self::assertSame(2.50, $recaptured->quotedOdds);
    }

    public function testPaymentStatusChangesNeverTouchTheContractualOdds(): void
    {
        $repository = new StakeRepository($this->database());
        $stake = $repository->create(1, 10, 1, 100, 2.50);
        $repository->captureOddsAtBet($stake->id, 1.80);

        $unpaid = $repository->setPaid($stake->id, false);
        $paidAgain = $repository->setPaid($stake->id, true);

        self::assertSame(1.80, $unpaid->oddsAtBet);
        self::assertSame(1.80, $paidAgain->oddsAtBet);
        self::assertSame(180, $paidAgain->potentialPayout());
    }

    private function database(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('CREATE TABLE contacts (id INTEGER PRIMARY KEY, name TEXT, archived_at TEXT)');
        $pdo->exec('CREATE TABLE bet_options (id INTEGER PRIMARY KEY, label TEXT)');
        $pdo->exec('CREATE TABLE stakes (
            id INTEGER PRIMARY KEY,
            bet_id INTEGER,
            bet_option_id INTEGER,
            contact_id INTEGER,
            amount INTEGER CHECK (amount BETWEEN 1 AND 999999),
            is_paid INTEGER,
            is_cancelled INTEGER,
            final_payout INTEGER,
            winnings_paid_at TEXT,
            created_at TEXT,
            odds_at_bet REAL,
            quoted_odds REAL
        )');
        $pdo->exec("INSERT INTO contacts VALUES (1, 'Alice', NULL), (2, 'Bob', NULL)");
        $pdo->exec("INSERT INTO bet_options VALUES (10, 'Blue'), (11, 'Red')");
        $pdo->exec("INSERT INTO stakes VALUES
            (1, 1, 10, 1, 100, 1, 0, 150, NULL, '2026-08-24 10:00:00', 1.50, 1.50),
            (2, 1, 10, 1, 200, 1, 0, 300, NULL, '2026-08-24 10:01:00', 1.50, 1.50),
            (3, 1, 10, 2, 300, 1, 0, 500, '2026-08-24 11:00:00', '2026-08-24 10:02:00', 1.66, 1.66),
            (4, 1, 11, 2, 400, 1, 0, 0, NULL, '2026-08-24 10:03:00', 2.00, 2.00),
            (5, 1, 10, 1, 5000, 1, 1, NULL, NULL, '2026-08-24 10:04:00', 1.50, 1.50),
            (6, 1, 10, 1, 7000, 0, 0, NULL, NULL, '2026-08-24 10:05:00', NULL, 1.50)");

        return $pdo;
    }
}
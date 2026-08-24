<?php

declare(strict_types=1);

namespace Tests\Unit\Repository;

use App\Repository\StakeRepository;
use PDO;
use PHPUnit\Framework\TestCase;

final class StakeRepositoryTest extends TestCase
{
    public function testWinnersAreAggregatedAndCancelledStakesAreExcludedFromWinningsAndPot(): void
    {
        $repository = new StakeRepository($this->database());

        $winners = $repository->findWinnersByBet(1, 10);

        self::assertCount(2, $winners);
        self::assertSame([1, 2], array_column($winners, 'contact_id'));
        self::assertSame([300, 300], array_column($winners, 'winning_stake_cents'));
        self::assertSame([450, 500], array_column($winners, 'payout_cents'));
        self::assertSame([false, true], array_column($winners, 'is_winnings_paid'));
    }

    public function testWinningsPaymentStatusUpdatesEveryActiveWinningStakeForContact(): void
    {
        $repository = new StakeRepository($this->database());

        $repository->setWinningsPaid(1, 10, 1, true);
        $winner = $repository->findWinnersByBet(1, 10)[0];

        self::assertTrue($winner['is_winnings_paid']);
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
            amount_cents INTEGER,
            is_paid INTEGER,
            is_cancelled INTEGER,
            final_payout_cents INTEGER,
            winnings_paid_at TEXT,
            created_at TEXT
        )');
        $pdo->exec("INSERT INTO contacts VALUES (1, 'Alice', NULL), (2, 'Bob', NULL)");
        $pdo->exec("INSERT INTO bet_options VALUES (10, 'Blue'), (11, 'Red')");
        $pdo->exec("INSERT INTO stakes VALUES
            (1, 1, 10, 1, 100, 1, 0, 150, NULL, '2026-08-24 10:00:00'),
            (2, 1, 10, 1, 200, 1, 0, 300, NULL, '2026-08-24 10:01:00'),
            (3, 1, 10, 2, 300, 1, 0, 500, '2026-08-24 11:00:00', '2026-08-24 10:02:00'),
            (4, 1, 11, 2, 400, 1, 0, 0, NULL, '2026-08-24 10:03:00'),
            (5, 1, 10, 1, 5000, 1, 1, NULL, NULL, '2026-08-24 10:04:00')");

        return $pdo;
    }
}
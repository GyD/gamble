<?php

declare(strict_types=1);

namespace Tests\Unit\Repository;

use App\Repository\StatisticsRepository;
use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\TestCase;

final class StatisticsRepositoryTest extends TestCase
{
    public function testSettledRowsAggregateMultipleStakesByContactAndBetAndIgnoreIneligibleBets(): void
    {
        $repository = new StatisticsRepository($this->database());

        $rows = $repository->settledContactBets(null, null);

        self::assertCount(1, $rows);
        self::assertSame(1, $rows[0]['contact_id']);
        self::assertSame(2, $rows[0]['stake_count']);
        self::assertSame(3000, $rows[0]['total_staked_cents']);
        self::assertSame(3000, $rows[0]['winning_staked_cents']);
        self::assertSame(4500, $rows[0]['returned_cents']);
        self::assertSame(2000, $rows[0]['largest_stake_cents']);
    }

    public function testSettledRowsApplyOwnerContactAndPeriodFilters(): void
    {
        $repository = new StatisticsRepository($this->database());

        self::assertCount(1, $repository->settledContactBets(1, new DateTimeImmutable('2026-08-01'), 1));
        self::assertSame([], $repository->settledContactBets(2, null, 1));
        self::assertSame([], $repository->settledContactBets(1, new DateTimeImmutable('2026-09-01'), 1));
    }

    public function testBetStakesKeepsEmptyOptionsAndExcludesCancelledStakes(): void
    {
        $rows = (new StatisticsRepository($this->database()))->betStakes(1);

        self::assertCount(3, $rows);
        self::assertSame([1, 1, 2], array_column($rows, 'option_id'));
        self::assertSame([1000, 2000, null], array_column($rows, 'amount_cents'));
    }

    private function database(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('CREATE TABLE bets (id INTEGER PRIMARY KEY, owner_user_id INTEGER, question TEXT, status TEXT, winning_option_id INTEGER, updated_at TEXT)');
        $pdo->exec('CREATE TABLE contacts (id INTEGER PRIMARY KEY, name TEXT)');
        $pdo->exec('CREATE TABLE bet_options (id INTEGER PRIMARY KEY, bet_id INTEGER, label TEXT, position INTEGER)');
        $pdo->exec('CREATE TABLE stakes (id INTEGER PRIMARY KEY, bet_id INTEGER, bet_option_id INTEGER, contact_id INTEGER, amount_cents INTEGER, final_payout_cents INTEGER, is_cancelled INTEGER)');
        $pdo->exec("INSERT INTO contacts VALUES (1, 'Alice')");
        $pdo->exec("INSERT INTO bets VALUES
            (1, 1, 'Settled', 'settled', 1, '2026-08-20 12:00:00'),
            (2, 1, 'Open', 'open', NULL, '2026-08-21 12:00:00'),
            (3, 1, 'Cancelled', 'cancelled', NULL, '2026-08-22 12:00:00')");
        $pdo->exec("INSERT INTO bet_options VALUES (1, 1, 'Blue', 1), (2, 1, 'Red', 2), (3, 2, 'Yes', 1), (4, 3, 'No', 1)");
        $pdo->exec('INSERT INTO stakes VALUES
            (1, 1, 1, 1, 1000, 1500, 0),
            (2, 1, 1, 1, 5000, NULL, 1),
            (3, 1, 1, 1, 2000, 3000, 0),
            (4, 2, 3, 1, 4000, NULL, 0),
            (5, 3, 4, 1, 8000, NULL, 0)');

        return $pdo;
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit\Service;

use App\Repository\StatisticsStore;
use App\Service\StatisticsService;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class StatisticsServiceTest extends TestCase
{
    public function testContactWithoutSettledBetHasNullRatesAndFinancialResults(): void
    {
        $statistics = $this->service([])->contact(1, null, 'all');

        self::assertSame(0, $statistics['participations']);
        self::assertNull($statistics['win_rate']);
        self::assertNull($statistics['average_stake']);
        self::assertNull($statistics['net']);
        self::assertNull($statistics['roi']);
        self::assertNull($statistics['largest_gain']);
    }

    public function testContactAggregatesWinsLossesStakesAndLargestLoss(): void
    {
        $statistics = $this->service([
            $this->row(1, '2026-08-20', 3000, 1000, 2000, returned: 6000),
            $this->row(2, '2026-08-21', 5000, 0, 5000),
            $this->row(3, '2026-08-22', 4000, 4000, 4000, returned: 7000),
        ])->contact(1, null, 'all');

        self::assertSame(3, $statistics['participations']);
        self::assertSame(2, $statistics['wins']);
        self::assertSame(1, $statistics['losses']);
        self::assertSame(66.7, $statistics['win_rate']);
        self::assertSame(12000, $statistics['total_staked']);
        self::assertSame(4000, $statistics['average_stake']);
        self::assertSame(5000, $statistics['largest_stake']);
        self::assertSame(5000, $statistics['largest_loss']);
        self::assertSame(13000, $statistics['total_returned']);
        self::assertSame(1000, $statistics['net']);
        self::assertSame(8.3, $statistics['roi']);
        self::assertSame(3000, $statistics['largest_gain']);
    }

    #[DataProvider('streakCases')]
    public function testStreaks(string $sequence, string $currentType, int $currentCount, int $bestWins, int $bestLosses): void
    {
        $rows = [];
        foreach (str_split($sequence) as $index => $outcome) {
            $rows[] = $this->row($index + 1, sprintf('2026-08-%02d', $index + 1), 1000, $outcome === 'W' ? 1000 : 0, 1000);
        }

        $statistics = $this->service($rows)->contact(1, null, 'all');

        self::assertSame(['type' => $currentType, 'count' => $currentCount], $statistics['current_streak']);
        self::assertSame($bestWins, $statistics['best_win_streak']);
        self::assertSame($bestLosses, $statistics['best_loss_streak']);
    }

    public static function streakCases(): iterable
    {
        yield 'W' => ['W', 'win', 1, 1, 0];
        yield 'L' => ['L', 'loss', 1, 0, 1];
        yield 'WWW' => ['WWW', 'win', 3, 3, 0];
        yield 'LLL' => ['LLL', 'loss', 3, 0, 3];
        yield 'WWLL' => ['WWLL', 'loss', 2, 2, 2];
        yield 'LLWWW' => ['LLWWW', 'win', 3, 3, 2];
        yield 'alternating' => ['WLWL', 'loss', 1, 1, 1];
    }

    public function testLeaderboardSortsBySupportedMetricsAndFiltersPeriod(): void
    {
        $rows = [
            $this->row(1, '2026-08-23', 1000, 1000, 1000, 1, 'Alice'),
            $this->row(2, '2026-08-22', 1000, 0, 1000, 1, 'Alice'),
            $this->row(3, '2026-08-21', 5000, 5000, 5000, 2, 'Bob'),
            $this->row(4, '2026-07-01', 9000, 9000, 9000, 3, 'Old'),
        ];
        $service = $this->service($rows);
        $now = new DateTimeImmutable('2026-08-24 12:00:00');

        self::assertSame(['Bob', 'Alice'], array_column($service->leaderboard(null, '30d', 'win_rate', $now)['contacts'], 'name'));
        self::assertSame(['Bob', 'Alice'], array_column($service->leaderboard(null, '30d', 'total_staked', $now)['contacts'], 'name'));
        self::assertSame(['Alice', 'Bob'], array_column($service->leaderboard(null, '30d', 'participations', $now)['contacts'], 'name'));
        self::assertCount(3, $service->leaderboard(null, 'all', 'win_rate', $now)['contacts']);
    }

    public function testBetStatisticsCalculatePotParticipantsAverageMedianMaximumAndDistribution(): void
    {
        $service = new StatisticsService(new StatisticsServiceStore([], [
            $this->stake(1, 'Blue', 1, 1, 1000),
            $this->stake(1, 'Blue', 2, 2, 1000),
            $this->stake(2, 'Red', 3, 1, 3000),
            $this->emptyOption(3, 'Green'),
        ]));

        $statistics = $service->bet(10);

        self::assertSame(2, $statistics['participant_count']);
        self::assertSame(5000, $statistics['pot_cents']);
        self::assertSame(1667, $statistics['average_stake_cents']);
        self::assertSame(2000, $statistics['median_stake_cents_x2']);
        self::assertSame(3000, $statistics['largest_stake_cents']);
        self::assertSame([40.0, 60.0, 0.0], array_column($statistics['options'], 'pot_percentage'));
        self::assertSame([2, 1, 0], array_column($statistics['options'], 'participant_count'));
    }

    public function testBetStatisticsHandleNoStakeOneStakeAndEvenMedian(): void
    {
        $empty = (new StatisticsService(new StatisticsServiceStore([], [$this->emptyOption(1, 'A')])))->bet(1);
        self::assertNull($empty['median_stake_cents_x2']);
        self::assertNull($empty['average_stake_cents']);
        self::assertNull($empty['options'][0]['pot_percentage']);

        $one = (new StatisticsService(new StatisticsServiceStore([], [$this->stake(1, 'A', 1, 1, 999)])))->bet(1);
        self::assertSame(1998, $one['median_stake_cents_x2']);

        $even = (new StatisticsService(new StatisticsServiceStore([], [
            $this->stake(1, 'A', 1, 1, 1000),
            $this->stake(1, 'A', 2, 2, 1001),
        ])))->bet(1);
        self::assertSame(2001, $even['median_stake_cents_x2']);
    }

    /** @param list<array<string, mixed>> $rows */
    private function service(array $rows): StatisticsService
    {
        return new StatisticsService(new StatisticsServiceStore($rows, []));
    }

    /** @return array<string, mixed> */
    private function row(
        int $betId,
        string $date,
        int $total,
        int $winning,
        int $largest,
        int $contactId = 1,
        string $name = 'Alice',
        int $returned = 0,
    ): array
    {
        return ['contact_id' => $contactId, 'contact_name' => $name, 'bet_id' => $betId, 'question' => 'Bet ' . $betId,
            'settled_at' => $date . ' 12:00:00', 'stake_count' => 1, 'total_staked_cents' => $total,
            'winning_staked_cents' => $winning, 'returned_cents' => $returned,
            'largest_stake_cents' => $largest];
    }

    /** @return array<string, mixed> */
    private function stake(int $optionId, string $label, int $stakeId, int $contactId, int $amount): array
    {
        return ['option_id' => $optionId, 'option_label' => $label, 'option_position' => $optionId,
            'stake_id' => $stakeId, 'contact_id' => $contactId, 'amount_cents' => $amount];
    }

    /** @return array<string, mixed> */
    private function emptyOption(int $optionId, string $label): array
    {
        return ['option_id' => $optionId, 'option_label' => $label, 'option_position' => $optionId,
            'stake_id' => null, 'contact_id' => null, 'amount_cents' => null];
    }
}

final class StatisticsServiceStore implements StatisticsStore
{
    /** @param list<array<string, mixed>> $settledRows @param list<array<string, mixed>> $stakeRows */
    public function __construct(private readonly array $settledRows, private readonly array $stakeRows) {}

    public function settledContactBets(?int $ownerUserId, ?DateTimeImmutable $from, ?int $contactId = null): array
    {
        return array_values(array_filter($this->settledRows, static fn(array $row): bool =>
            ($contactId === null || $row['contact_id'] === $contactId)
            && ($from === null || new DateTimeImmutable($row['settled_at']) >= $from)));
    }

    public function betStakes(int $betId): array { return $this->stakeRows; }
}

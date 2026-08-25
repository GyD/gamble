<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\StatisticsStore;
use DateTimeImmutable;

final readonly class StatisticsService
{
    public const PERIOD_ALL = 'all';
    public const PERIOD_7_DAYS = '7d';
    public const PERIOD_30_DAYS = '30d';

    private const PERIODS = [self::PERIOD_7_DAYS, self::PERIOD_30_DAYS, self::PERIOD_ALL];
    private const SORTS = ['win_rate', 'total_staked', 'participations'];

    public function __construct(private StatisticsStore $statistics)
    {
    }

    /** @return array{period: string, sort: string, contacts: list<array<string, mixed>>, records: array<string, mixed>} */
    public function leaderboard(?int $ownerUserId, string $period, string $sort, ?DateTimeImmutable $now = null): array
    {
        $period = $this->period($period);
        $sort = in_array($sort, self::SORTS, true) ? $sort : 'win_rate';
        $rows = $this->statistics->settledContactBets($ownerUserId, $this->from($period, $now));
        $grouped = [];
        foreach ($rows as $row) {
            $grouped[$row['contact_id']][] = $row;
        }
        $contacts = array_map($this->summarize(...), array_values($grouped));
        usort($contacts, static function (array $left, array $right) use ($sort): int {
            $comparison = ($right[$sort] ?? -1) <=> ($left[$sort] ?? -1);

            return $comparison !== 0 ? $comparison : strcasecmp($left['name'], $right['name']);
        });

        return [
            'period' => $period,
            'sort' => $sort,
            'contacts' => $contacts,
            'records' => $this->records($rows, $contacts),
        ];
    }

    /** @return array<string, mixed> */
    public function contact(int $contactId, ?int $ownerUserId, string $period, ?DateTimeImmutable $now = null): array
    {
        $period = $this->period($period);
        $rows = $this->statistics->settledContactBets($ownerUserId, $this->from($period, $now), $contactId);
        $summary = $rows === [] ? $this->emptySummary() : $this->summarize($rows);
        $summary['period'] = $period;
        $summary['recent_results'] = array_slice(array_reverse(array_map(static fn(array $row): array => [
            'bet_id' => $row['bet_id'],
            'question' => $row['question'],
            'settled_at' => $row['settled_at'],
            'outcome' => $row['winning_staked'] > 0 ? 'win' : 'loss',
            'staked' => $row['total_staked'],
        ], $rows)), 0, 10);

        return $summary;
    }

    /** @return array<string, mixed> */
    public function bet(int $betId): array
    {
        $rows = $this->statistics->betStakes($betId);
        $options = [];
        $amounts = [];
        $participants = [];
        foreach ($rows as $row) {
            $options[$row['option_id']] ??= [
                'id' => $row['option_id'],
                'label' => $row['option_label'],
                'participants' => [],
                'amount' => 0,
            ];
            if ($row['stake_id'] === null) {
                continue;
            }
            $amount = $row['amount'];
            $amounts[] = $amount;
            $participants[$row['contact_id']] = true;
            $options[$row['option_id']]['participants'][$row['contact_id']] = true;
            $options[$row['option_id']]['amount'] += $amount;
        }
        sort($amounts, SORT_NUMERIC);
        $pot = array_sum($amounts);
        $optionStatistics = array_map(static function (array $option) use ($pot): array {
            $option['participant_count'] = count($option['participants']);
            unset($option['participants']);
            $option['pot_percentage'] = $pot === 0 ? null : (float) (($option['amount'] * 100) / $pot);

            return $option;
        }, array_values($options));

        return [
            'participant_count' => count($participants),
            'stake_count' => count($amounts),
            'pot' => $pot,
            'average_stake' => $amounts === [] ? null : $this->roundedAverage($pot, count($amounts)),
            'median_stake_x2' => $this->medianTimesTwo($amounts),
            'largest_stake' => $amounts === [] ? null : max($amounts),
            'options' => $optionStatistics,
        ];
    }

    /** @param list<array<string, mixed>> $rows @return array<string, mixed> */
    private function summarize(array $rows): array
    {
        $outcomes = array_map(static fn(array $row): string => $row['winning_staked'] > 0 ? 'win' : 'loss', $rows);
        $wins = count(array_filter($outcomes, static fn(string $outcome): bool => $outcome === 'win'));
        $participations = count($rows);
        $stakeCount = array_sum(array_column($rows, 'stake_count'));
        $totalStaked = array_sum(array_column($rows, 'total_staked'));
        $totalReturned = array_sum(array_column($rows, 'returned'));
        $net = $totalReturned - $totalStaked;
        $streaks = $this->streaks($outcomes);

        return [
            'id' => $rows[0]['contact_id'],
            'name' => $rows[0]['contact_name'],
            'participations' => $participations,
            'wins' => $wins,
            'losses' => $participations - $wins,
            'win_rate' => $participations === 0 ? null : round($wins * 100 / $participations, 1),
            'total_staked' => $totalStaked,
            'average_stake' => $stakeCount === 0 ? null : $this->roundedAverage($totalStaked, $stakeCount),
            'largest_stake' => max(array_column($rows, 'largest_stake')),
            'largest_loss' => $this->largestLoss($rows),
            'current_streak' => $streaks['current'],
            'best_win_streak' => $streaks['best_win'],
            'best_loss_streak' => $streaks['best_loss'],
            'total_returned' => $totalReturned,
            'net' => $net,
            'roi' => $totalStaked === 0 ? null : round($net * 100 / $totalStaked, 1),
            'largest_gain' => max(array_map(static fn(array $row): int => $row['returned'] - $row['total_staked'], $rows)),
        ];
    }

    /** @return array<string, mixed> */
    private function emptySummary(): array
    {
        return [
            'participations' => 0, 'wins' => 0, 'losses' => 0, 'win_rate' => null,
            'total_staked' => 0, 'average_stake' => null, 'largest_stake' => null,
            'largest_loss' => null, 'current_streak' => null, 'best_win_streak' => 0,
            'best_loss_streak' => 0, 'total_returned' => 0, 'net' => null,
            'roi' => null, 'largest_gain' => null,
        ];
    }

    /** @param list<string> $outcomes @return array{current: array{type: string, count: int}|null, best_win: int, best_loss: int} */
    private function streaks(array $outcomes): array
    {
        $currentType = null;
        $currentCount = 0;
        $best = ['win' => 0, 'loss' => 0];
        foreach ($outcomes as $outcome) {
            if ($outcome === $currentType) {
                ++$currentCount;
            } else {
                $currentType = $outcome;
                $currentCount = 1;
            }
            $best[$outcome] = max($best[$outcome], $currentCount);
        }

        return [
            'current' => $currentType === null ? null : ['type' => $currentType, 'count' => $currentCount],
            'best_win' => $best['win'],
            'best_loss' => $best['loss'],
        ];
    }

    /** @param list<array<string, mixed>> $rows */
    private function largestLoss(array $rows): ?int
    {
        $losses = array_column(array_filter($rows, static fn(array $row): bool => $row['winning_staked'] === 0), 'total_staked');

        return $losses === [] ? null : max($losses);
    }

    /** @param list<int> $amounts */
    private function medianTimesTwo(array $amounts): ?int
    {
        $count = count($amounts);
        if ($count === 0) {
            return null;
        }
        $middle = intdiv($count, 2);

        return $count % 2 === 1 ? $amounts[$middle] * 2 : $amounts[$middle - 1] + $amounts[$middle];
    }

    private function roundedAverage(int $total, int $count): int
    {
        return intdiv(($total * 2) + $count, $count * 2);
    }

    private function period(string $period): string
    {
        return in_array($period, self::PERIODS, true) ? $period : self::PERIOD_30_DAYS;
    }

    private function from(string $period, ?DateTimeImmutable $now): ?DateTimeImmutable
    {
        $now ??= new DateTimeImmutable();

        return match ($period) {
            self::PERIOD_7_DAYS => $now->modify('-7 days'),
            self::PERIOD_30_DAYS => $now->modify('-30 days'),
            self::PERIOD_ALL => null,
        };
    }

    /** @param list<array<string, mixed>> $rows @param list<array<string, mixed>> $contacts @return array<string, mixed> */
    private function records(array $rows, array $contacts): array
    {
        $pots = [];
        $questions = [];
        foreach ($rows as $row) {
            $pots[$row['bet_id']] = ($pots[$row['bet_id']] ?? 0) + $row['total_staked'];
            $questions[$row['bet_id']] = $row['question'];
        }
        arsort($pots, SORT_NUMERIC);
        $largestPotBetId = array_key_first($pots);

        return [
            'largest_stake' => $this->contactRecord($contacts, 'largest_stake'),
            'largest_loss' => $this->contactRecord($contacts, 'largest_loss'),
            'longest_win_streak' => $this->contactRecord($contacts, 'best_win_streak'),
            'longest_loss_streak' => $this->contactRecord($contacts, 'best_loss_streak'),
            'most_participations' => $this->contactRecord($contacts, 'participations'),
            'largest_pot' => $largestPotBetId === null ? null : [
                'bet_id' => $largestPotBetId,
                'question' => $questions[$largestPotBetId],
                'value' => $pots[$largestPotBetId],
            ],
        ];
    }

    /** @param list<array<string, mixed>> $contacts @return array{name: string, value: int}|null */
    private function contactRecord(array $contacts, string $key): ?array
    {
        $eligible = array_values(array_filter($contacts, static fn(array $contact): bool => $contact[$key] !== null));
        if ($eligible === []) {
            return null;
        }
        usort($eligible, static fn(array $left, array $right): int => $right[$key] <=> $left[$key]);

        return ['name' => $eligible[0]['name'], 'value' => $eligible[0][$key]];
    }
}

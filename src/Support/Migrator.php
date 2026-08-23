<?php

declare(strict_types=1);

namespace App\Support;

use PDO;
use RuntimeException;

final readonly class Migrator
{
    public function __construct(
        private PDO $pdo,
        private string $migrationPath,
    ) {
    }

    public function migrate(): int
    {
        $this->createMigrationsTable();
        $applied = $this->appliedMigrations();
        $files = glob($this->migrationPath . '/*.sql');

        if ($files === false) {
            throw new RuntimeException('Unable to read migration directory.');
        }

        sort($files, SORT_STRING);
        $count = 0;

        foreach ($files as $file) {
            $name = basename($file);
            if (isset($applied[$name])) {
                continue;
            }

            $sql = file_get_contents($file);
            if ($sql === false) {
                throw new RuntimeException(sprintf('Unable to read migration "%s".', $name));
            }

            $this->pdo->exec($sql);
            $statement = $this->pdo->prepare('INSERT INTO migrations (name) VALUES (:name)');
            $statement->execute(['name' => $name]);
            ++$count;
        }

        return $count;
    }

    private function createMigrationsTable(): void
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS migrations (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(255) NOT NULL UNIQUE,
                applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );
    }

    /** @return array<string, true> */
    private function appliedMigrations(): array
    {
        $statement = $this->pdo->query('SELECT name FROM migrations');
        $result = [];

        foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $name) {
            $result[(string) $name] = true;
        }

        return $result;
    }
}
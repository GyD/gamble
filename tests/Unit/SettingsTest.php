<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class SettingsTest extends TestCase
{
    public function testDefaultSettingsAreSafeForProduction(): void
    {
        $originalEnv = $_ENV;
        $_ENV = [];

        try {
            $settings = require dirname(__DIR__, 2) . '/config/settings.php';
        } finally {
            $_ENV = $originalEnv;
        }

        self::assertSame('production', $settings['app']['environment']);
        self::assertSame('Gamble', $settings['app']['name']);
        self::assertFalse($settings['app']['debug']);
        self::assertTrue($settings['session']['secure']);
        self::assertSame('utf8mb4', $settings['database']['charset']);
    }

    public function testApplicationNameCanBeConfiguredFromEnvironment(): void
    {
        $originalEnv = $_ENV;
        $_ENV['APP_NAME'] = 'Private Bets';

        try {
            $settings = require dirname(__DIR__, 2) . '/config/settings.php';
        } finally {
            $_ENV = $originalEnv;
        }

        self::assertSame('Private Bets', $settings['app']['name']);
    }
}
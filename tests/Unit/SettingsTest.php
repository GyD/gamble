<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
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

    #[DataProvider('environmentCases')]
    public function testEnvironmentIsNormalizedToASupportedValue(string $rawEnvironment, string $expected): void
    {
        $originalEnv = $_ENV;
        $_ENV['APP_ENV'] = $rawEnvironment;

        try {
            $settings = require dirname(__DIR__, 2) . '/config/settings.php';
        } finally {
            $_ENV = $originalEnv;
        }

        self::assertSame($expected, $settings['app']['environment']);
    }

    /** @return iterable<string, array{string, string}> */
    public static function environmentCases(): iterable
    {
        yield 'development' => ['development', 'development'];
        yield 'test' => ['test', 'test'];
        yield 'production' => ['production', 'production'];
        yield 'uppercase value is normalized' => ['Development', 'development'];
        yield 'padded value is trimmed' => ["  test\n", 'test'];
        yield 'unknown value falls back to production' => ['dev', 'production'];
        yield 'empty value falls back to production' => ['', 'production'];
    }
}
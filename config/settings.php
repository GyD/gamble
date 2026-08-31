<?php

declare(strict_types=1);

$root = dirname(__DIR__);

$supportedEnvironments = ['development', 'test', 'production'];
$environment = strtolower(trim((string)($_ENV['APP_ENV'] ?? '')));

if (!in_array($environment, $supportedEnvironments, true)) {
    $environment = 'production';
}

return [
    'permissions' => require __DIR__ . '/permissions.php',
    'app' => [
        'name' => $_ENV['APP_NAME'] ?? 'Gamble',
        'environment' => $environment,
        'debug' => filter_var($_ENV['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOL),
        'url' => rtrim($_ENV['APP_URL'] ?? 'http://localhost:8080', '/'),
        'secret' => $_ENV['APP_SECRET'] ?? '',
    ],
    'database' => [
        'host' => $_ENV['DB_HOST'] ?? '127.0.0.1',
        'port' => (int)($_ENV['DB_PORT'] ?? 3306),
        'database' => $_ENV['DB_DATABASE'] ?? 'gamble',
        'username' => $_ENV['DB_USERNAME'] ?? 'gamble',
        'password' => $_ENV['DB_PASSWORD'] ?? '',
        'charset' => $_ENV['DB_CHARSET'] ?? 'utf8mb4',
    ],
    'session' => [
        'name' => $_ENV['SESSION_NAME'] ?? 'gamble_session',
        'secure' => filter_var($_ENV['SESSION_SECURE'] ?? true, FILTER_VALIDATE_BOOL),
    ],
    'twitch' => [
        'client_id' => $_ENV['TWITCH_CLIENT_ID'] ?? '',
        'client_secret' => $_ENV['TWITCH_CLIENT_SECRET'] ?? '',
        'redirect_uri' => $_ENV['TWITCH_REDIRECT_URI'] ?? '',
    ],
    'paths' => [
        'root' => $root,
        'templates' => $root . '/templates',
        'logs' => $root . '/var/log',
        'migrations' => $root . '/database/migrations',
    ],
    'market' => [
        // Market weight of an active but unpaid stake.
        'unpaid_bet_market_weight' => 0.50,
        // Volume, in whole units, at which the drift reaches half of its maximum.
        'liquidity_reference' => 500,
        // Lowest odds the bookmaker can offer on an option.
        'minimum_odds' => 1.01,
        // Widest drift applicable to the priced odds, in basis points.
        'max_odds_drift_bps' => [
            'fixed' => 0,
            'dynamic_low' => 500,
            'dynamic_normal' => 1200,
            'dynamic_high' => 2500,
        ],
    ],
];
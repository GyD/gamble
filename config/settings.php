<?php

declare(strict_types=1);

$root = dirname(__DIR__);

return [
    'app' => [
        'name' => $_ENV['APP_NAME'] ?? 'Gamble',
        'environment' => $_ENV['APP_ENV'] ?? 'production',
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
];
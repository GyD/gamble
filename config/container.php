<?php

declare(strict_types=1);

use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use Psr\Log\LoggerInterface;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

use function DI\factory;

$settings = require __DIR__ . '/settings.php';

return [
    'settings' => $settings,
    PDO::class => factory(static function () use ($settings): PDO {
        $database = $settings['database'];
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $database['host'],
            $database['port'],
            $database['database'],
            $database['charset'],
        );

        return new PDO($dsn, $database['username'], $database['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }),
    LoggerInterface::class => factory(static function () use ($settings): LoggerInterface {
        $logDirectory = $settings['paths']['logs'];
        if (!is_dir($logDirectory)) {
            mkdir($logDirectory, 0775, true);
        }

        $logger = new Logger('gamble');
        $logger->pushHandler(new StreamHandler(
            $logDirectory . '/app.log',
            $settings['app']['debug'] ? Level::Debug : Level::Info,
        ));

        return $logger;
    }),
    Environment::class => factory(static function () use ($settings): Environment {
        return new Environment(
            new FilesystemLoader($settings['paths']['templates']),
            [
                'cache' => false,
                'debug' => $settings['app']['debug'],
                'strict_variables' => $settings['app']['debug'],
            ],
        );
    }),
];
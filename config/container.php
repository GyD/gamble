<?php

declare(strict_types=1);

use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use App\Repository\OAuthStateRepository;
use App\Repository\AuditLogger;
use App\Repository\AuditLogRepository;
use App\Repository\PdoPermissionRepository;
use App\Repository\UserAdministrationStore;
use App\Repository\UserRepository;
use App\Repository\UserStore;
use App\Security\CurlTwitchClient;
use App\Security\OAuthStateStore;
use App\Security\PermissionResolver;
use App\Security\TwitchClient;
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
    UserStore::class => DI\get(UserRepository::class),
    UserAdministrationStore::class => DI\get(UserRepository::class),
    AuditLogger::class => DI\get(AuditLogRepository::class),
    OAuthStateStore::class => DI\get(OAuthStateRepository::class),
    PermissionResolver::class => DI\get(PdoPermissionRepository::class),
    TwitchClient::class => factory(static fn(): TwitchClient => new CurlTwitchClient(
        $settings['twitch']['client_id'],
        $settings['twitch']['client_secret'],
        $settings['twitch']['redirect_uri'],
    )),
];
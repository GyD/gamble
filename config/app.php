<?php

declare(strict_types=1);

use DI\ContainerBuilder;
use Slim\Csrf\Guard;
use Slim\Factory\AppFactory;

$containerBuilder = new ContainerBuilder();
$containerBuilder->addDefinitions(require __DIR__ . '/container.php');
$container = $containerBuilder->build();

AppFactory::setContainer($container);
$app = AppFactory::create();

$settings = $container->get('settings');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name($settings['session']['name']);
    session_set_cookie_params([
        'httponly' => true,
        'secure' => $settings['session']['secure'],
        'samesite' => 'Lax',
        'path' => '/',
    ]);
    session_start();
}

(require __DIR__ . '/routes.php')($app);

$app->addRoutingMiddleware();
$app->add(new Guard($app->getResponseFactory()));
$app->addErrorMiddleware(
    $settings['app']['debug'],
    true,
    true,
    $container->get(Psr\Log\LoggerInterface::class),
);

return $app;
<?php

declare(strict_types=1);

use App\Controller\AuthController;
use App\Controller\HealthController;
use App\Controller\HomeController;
use App\Middleware\RequireActiveUserMiddleware;
use Slim\App;

return static function (App $app): void {
    $app->get('/', HomeController::class)
        ->add(RequireActiveUserMiddleware::class)
        ->setName('home');
    $app->get('/health', HealthController::class)->setName('health');
    $app->get('/login', [AuthController::class, 'login'])->setName('login');
    $app->get('/auth/twitch', [AuthController::class, 'redirectToTwitch'])->setName('auth.twitch');
    $app->get('/auth/twitch/callback', [AuthController::class, 'callback'])->setName('auth.twitch.callback');
    $app->get('/access/pending', [AuthController::class, 'pending'])->setName('access.pending');
    $app->get('/access/suspended', [AuthController::class, 'suspended'])->setName('access.suspended');
    $app->post('/auth/logout', [AuthController::class, 'logout'])->setName('auth.logout');
};
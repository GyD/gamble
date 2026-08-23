<?php

declare(strict_types=1);

use App\Controller\HealthController;
use App\Controller\HomeController;
use Slim\App;

return static function (App $app): void {
    $app->get('/', HomeController::class)->setName('home');
    $app->get('/health', HealthController::class)->setName('health');
};
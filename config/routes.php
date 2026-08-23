<?php

declare(strict_types=1);

use App\Controller\AuthController;
use App\Controller\Admin\UserController;
use App\Controller\ContactController;
use App\Controller\HealthController;
use App\Controller\HomeController;
use App\Middleware\RequireActiveUserMiddleware;
use App\Middleware\RequireContactsCreatePermissionMiddleware;
use App\Middleware\RequireContactsDeletePermissionMiddleware;
use App\Middleware\RequireContactsEditPermissionMiddleware;
use App\Middleware\RequireContactsViewPermissionMiddleware;
use App\Middleware\RequirePermissionsManagePermissionMiddleware;
use App\Middleware\RequireUsersManagePermissionMiddleware;
use App\Middleware\RequireUsersViewPermissionMiddleware;
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

    $app->get('/contacts', [ContactController::class, 'index'])
        ->add(RequireContactsViewPermissionMiddleware::class)
        ->add(RequireActiveUserMiddleware::class)
        ->setName('contacts');
    $app->post('/contacts', [ContactController::class, 'create'])
        ->add(RequireContactsCreatePermissionMiddleware::class)
        ->add(RequireActiveUserMiddleware::class)
        ->setName('contacts.create');
    $app->get('/contacts/{id}/edit', [ContactController::class, 'edit'])
        ->add(RequireContactsEditPermissionMiddleware::class)
        ->add(RequireActiveUserMiddleware::class)
        ->setName('contacts.edit');
    $app->post('/contacts/{id}', [ContactController::class, 'update'])
        ->add(RequireContactsEditPermissionMiddleware::class)
        ->add(RequireActiveUserMiddleware::class)
        ->setName('contacts.update');
    $app->post('/contacts/{id}/archive', [ContactController::class, 'archive'])
        ->add(RequireContactsDeletePermissionMiddleware::class)
        ->add(RequireActiveUserMiddleware::class)
        ->setName('contacts.archive');
    $app->post('/contacts/{id}/delete', [ContactController::class, 'delete'])
        ->add(RequireContactsDeletePermissionMiddleware::class)
        ->add(RequireActiveUserMiddleware::class)
        ->setName('contacts.delete');

    $app->get('/admin/users', [UserController::class, 'index'])
        ->add(RequireUsersViewPermissionMiddleware::class)
        ->add(RequireActiveUserMiddleware::class)
        ->setName('admin.users');
    $app->post('/admin/users/{id}/status', [UserController::class, 'changeStatus'])
        ->add(RequireUsersManagePermissionMiddleware::class)
        ->add(RequireActiveUserMiddleware::class)
        ->setName('admin.users.status');
    $app->get('/admin/users/{id}/access', [UserController::class, 'editAccess'])
        ->add(RequirePermissionsManagePermissionMiddleware::class)
        ->add(RequireActiveUserMiddleware::class)
        ->setName('admin.users.access');
    $app->post('/admin/users/{id}/access', [UserController::class, 'updateAccess'])
        ->add(RequirePermissionsManagePermissionMiddleware::class)
        ->add(RequireActiveUserMiddleware::class)
        ->setName('admin.users.access.update');
};
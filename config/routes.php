<?php

declare(strict_types=1);

use App\Controller\AuthController;
use App\Controller\BetController;
use App\Controller\Admin\UserController;
use App\Controller\ContactController;
use App\Controller\GroupController;
use App\Controller\HealthController;
use App\Controller\HomeController;
use App\Controller\StakeController;
use App\Middleware\RequireActiveUserMiddleware;
use App\Middleware\RequireBetsClosePermissionMiddleware;
use App\Middleware\RequireBetsCreatePermissionMiddleware;
use App\Middleware\RequireBetsDeletePermissionMiddleware;
use App\Middleware\RequireBetsEditPermissionMiddleware;
use App\Middleware\RequireBetsSettlePermissionMiddleware;
use App\Middleware\RequireBetsViewPermissionMiddleware;
use App\Middleware\RequireContactsCreatePermissionMiddleware;
use App\Middleware\RequireContactsDeletePermissionMiddleware;
use App\Middleware\RequireContactsEditPermissionMiddleware;
use App\Middleware\RequireContactsViewPermissionMiddleware;
use App\Middleware\RequireGroupsCreatePermissionMiddleware;
use App\Middleware\RequireGroupsDeletePermissionMiddleware;
use App\Middleware\RequireGroupsEditPermissionMiddleware;
use App\Middleware\RequireGroupsViewPermissionMiddleware;
use App\Middleware\RequireStakesCreatePermissionMiddleware;
use App\Middleware\RequireStakesDeletePermissionMiddleware;
use App\Middleware\RequireStakesEditPermissionMiddleware;
use App\Middleware\RequireStakesViewPermissionMiddleware;
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

    $app->get('/bets', [BetController::class, 'index'])
        ->add(RequireBetsViewPermissionMiddleware::class)
        ->add(RequireActiveUserMiddleware::class)
        ->setName('bets');
    $app->post('/bets', [BetController::class, 'create'])
        ->add(RequireBetsCreatePermissionMiddleware::class)
        ->add(RequireActiveUserMiddleware::class)
        ->setName('bets.create');
    $app->get('/bets/{id}', [BetController::class, 'show'])
        ->add(RequireBetsViewPermissionMiddleware::class)
        ->add(RequireActiveUserMiddleware::class)
        ->setName('bets.show');
    $app->get('/bets/{id}/edit', [BetController::class, 'edit'])
        ->add(RequireBetsEditPermissionMiddleware::class)
        ->add(RequireActiveUserMiddleware::class)
        ->setName('bets.edit');
    $app->post('/bets/{id}', [BetController::class, 'update'])
        ->add(RequireBetsEditPermissionMiddleware::class)
        ->add(RequireActiveUserMiddleware::class)
        ->setName('bets.update');
    $app->post('/bets/{id}/close', [BetController::class, 'close'])
        ->add(RequireBetsClosePermissionMiddleware::class)
        ->add(RequireActiveUserMiddleware::class)
        ->setName('bets.close');
    $app->post('/bets/{id}/cancel', [BetController::class, 'cancel'])
        ->add(RequireBetsDeletePermissionMiddleware::class)
        ->add(RequireActiveUserMiddleware::class)
        ->setName('bets.cancel');
    $app->post('/bets/{id}/delete', [BetController::class, 'delete'])
        ->add(RequireBetsDeletePermissionMiddleware::class)
        ->add(RequireActiveUserMiddleware::class)
        ->setName('bets.delete');
    $app->post('/bets/{id}/settle', [BetController::class, 'settle'])
        ->add(RequireBetsSettlePermissionMiddleware::class)
        ->add(RequireActiveUserMiddleware::class)
        ->setName('bets.settle');
    $app->get('/bets/{id}/stakes', [StakeController::class, 'index'])
        ->add(RequireStakesViewPermissionMiddleware::class)
        ->add(RequireActiveUserMiddleware::class)
        ->setName('stakes');
    $app->post('/bets/{id}/stakes', [StakeController::class, 'create'])
        ->add(RequireStakesCreatePermissionMiddleware::class)
        ->add(RequireActiveUserMiddleware::class)
        ->setName('stakes.create');
    $app->post('/bets/{id}/stakes/{stakeId}', [StakeController::class, 'update'])
        ->add(RequireStakesEditPermissionMiddleware::class)
        ->add(RequireActiveUserMiddleware::class)
        ->setName('stakes.update');
    $app->post('/bets/{id}/stakes/{stakeId}/payment-status', [StakeController::class, 'setPaid'])
        ->add(RequireStakesEditPermissionMiddleware::class)
        ->add(RequireActiveUserMiddleware::class)
        ->setName('stakes.payment-status');
    $app->post('/bets/{id}/stakes/{stakeId}/cancellation-status', [StakeController::class, 'setCancelled'])
        ->add(RequireStakesDeletePermissionMiddleware::class)
        ->add(RequireActiveUserMiddleware::class)
        ->setName('stakes.cancellation-status');
    $app->post('/bets/{id}/stakes/{stakeId}/refund-status', [StakeController::class, 'setRefunded'])
        ->add(RequireStakesEditPermissionMiddleware::class)
        ->add(RequireActiveUserMiddleware::class)
        ->setName('stakes.refund-status');
    $app->post('/bets/{id}/stakes/{stakeId}/delete', [StakeController::class, 'delete'])
        ->add(RequireStakesDeletePermissionMiddleware::class)
        ->add(RequireActiveUserMiddleware::class)
        ->setName('stakes.delete');

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

    $app->get('/groups', [GroupController::class, 'index'])
        ->add(RequireGroupsViewPermissionMiddleware::class)
        ->add(RequireActiveUserMiddleware::class)
        ->setName('groups');
    $app->post('/groups', [GroupController::class, 'create'])
        ->add(RequireGroupsCreatePermissionMiddleware::class)
        ->add(RequireActiveUserMiddleware::class)
        ->setName('groups.create');
    $app->get('/groups/{id}/edit', [GroupController::class, 'edit'])
        ->add(RequireGroupsEditPermissionMiddleware::class)
        ->add(RequireActiveUserMiddleware::class)
        ->setName('groups.edit');
    $app->post('/groups/{id}', [GroupController::class, 'update'])
        ->add(RequireGroupsEditPermissionMiddleware::class)
        ->add(RequireActiveUserMiddleware::class)
        ->setName('groups.update');
    $app->post('/groups/{id}/archive', [GroupController::class, 'archive'])
        ->add(RequireGroupsDeletePermissionMiddleware::class)
        ->add(RequireActiveUserMiddleware::class)
        ->setName('groups.archive');
    $app->post('/groups/{id}/delete', [GroupController::class, 'delete'])
        ->add(RequireGroupsDeletePermissionMiddleware::class)
        ->add(RequireActiveUserMiddleware::class)
        ->setName('groups.delete');

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
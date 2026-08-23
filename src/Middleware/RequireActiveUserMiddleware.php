<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Domain\User\UserStatus;
use App\Repository\UserStore;
use App\Security\AuthSession;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

final readonly class RequireActiveUserMiddleware implements MiddlewareInterface
{
    public function __construct(
        private AuthSession $session,
        private UserStore   $users,
    )
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $userId = $this->session->userId();
        $user = $userId === null ? null : $this->users->findById($userId);

        if ($user === null) {
            return $this->redirect('/login');
        }

        if ($user->status === UserStatus::Pending) {
            return $this->redirect('/access/pending');
        }

        if ($user->status === UserStatus::Suspended) {
            return $this->redirect('/access/suspended');
        }

        return $handler->handle($request->withAttribute('user', $user));
    }

    private function redirect(string $location): ResponseInterface
    {
        return (new Response(302))->withHeader('Location', $location);
    }
}
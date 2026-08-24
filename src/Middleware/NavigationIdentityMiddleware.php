<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Repository\UserStore;
use App\Security\AuthSession;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Twig\Environment;

final readonly class NavigationIdentityMiddleware implements MiddlewareInterface
{
    public function __construct(
        private AuthSession $session,
        private UserStore $users,
        private Environment $twig,
    )
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $userId = $this->session->userId();
        $user = $userId === null ? null : $this->users->findById($userId);

        $this->twig->addGlobal('navigation_user', $user);
        $this->twig->addGlobal('navigation_csrf', [
            'name_key' => 'csrf_name',
            'name' => $request->getAttribute('csrf_name'),
            'value_key' => 'csrf_value',
            'value' => $request->getAttribute('csrf_value'),
        ]);

        return $handler->handle($request);
    }
}
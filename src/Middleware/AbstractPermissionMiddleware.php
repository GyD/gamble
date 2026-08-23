<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Domain\User\User;
use App\Security\AuthorizationService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

abstract readonly class AbstractPermissionMiddleware implements MiddlewareInterface
{
    public function __construct(private AuthorizationService $authorization)
    {
    }

    final public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $user = $request->getAttribute('user');

        if (!$user instanceof User || !$this->authorization->can($user, $this->permission())) {
            $response = new Response(403);
            $response->getBody()->write('Forbidden');

            return $response;
        }

        return $handler->handle($request);
    }

    abstract protected function permission(): string;
}
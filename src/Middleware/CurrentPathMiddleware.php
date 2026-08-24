<?php

declare(strict_types=1);

namespace App\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Twig\Environment;

final readonly class CurrentPathMiddleware implements MiddlewareInterface
{
    public function __construct(private Environment $twig)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $this->twig->addGlobal('current_path', $request->getUri()->getPath());

        return $handler->handle($request);
    }
}
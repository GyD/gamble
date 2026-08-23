<?php

declare(strict_types=1);

namespace App\Controller;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Twig\Environment;

final readonly class HomeController
{
    public function __construct(private Environment $twig)
    {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $response->getBody()->write($this->twig->render('home.html.twig', [
            'user' => $request->getAttribute('user'),
            'csrf' => [
                'name_key' => $request->getAttribute('csrf_name'),
                'name' => $request->getAttribute('csrf_name_value'),
                'value_key' => $request->getAttribute('csrf_value'),
                'value' => $request->getAttribute('csrf_value_value'),
            ],
        ]));

        return $response;
    }
}
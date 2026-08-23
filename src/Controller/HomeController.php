<?php

declare(strict_types=1);

namespace App\Controller;

use App\Domain\User\User;
use App\Security\AuthorizationService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Twig\Environment;

final readonly class HomeController
{
    public function __construct(
        private Environment $twig,
        private AuthorizationService $authorization,
    )
    {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $request->getAttribute('user');
        $response->getBody()->write($this->twig->render('home.html.twig', [
            'user' => $user,
            'can_view_contacts' => $user instanceof User && $this->authorization->can($user, 'contacts.view'),
            'can_view_groups' => $user instanceof User && $this->authorization->can($user, 'groups.view'),
            'can_view_users' => $user instanceof User && $this->authorization->can($user, 'users.view'),
            'csrf' => [
                'name_key' => 'csrf_name',
                'name' => $request->getAttribute('csrf_name'),
                'value_key' => 'csrf_value',
                'value' => $request->getAttribute('csrf_value'),
            ],
        ]));

        return $response;
    }
}
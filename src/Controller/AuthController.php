<?php

declare(strict_types=1);

namespace App\Controller;

use App\Domain\User\UserStatus;
use App\Security\AuthSession;
use App\Security\TwitchOAuthException;
use App\Service\TwitchAuthenticationService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Twig\Environment;

final readonly class AuthController
{
    public function __construct(
        private TwitchAuthenticationService $authentication,
        private AuthSession                 $session,
        private Environment                 $twig,
        private LoggerInterface             $logger,
    )
    {
    }

    public function login(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->render($request, $response, 'auth/login.html.twig');
    }

    public function redirectToTwitch(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        try {
            return $response
                ->withStatus(302)
                ->withHeader('Location', $this->authentication->beginAuthorization());
        } catch (TwitchOAuthException $exception) {
            $this->logger->error('Unable to start Twitch authentication.', ['exception' => $exception]);

            return $this->render($request, $response->withStatus(503), 'auth/error.html.twig');
        }
    }

    public function callback(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $query = $request->getQueryParams();

        try {
            if (isset($query['error'])) {
                throw new TwitchOAuthException('Twitch authorization was declined.');
            }

            $user = $this->authentication->completeAuthorization(
                is_string($query['code'] ?? null) ? $query['code'] : '',
                is_string($query['state'] ?? null) ? $query['state'] : '',
            );
        } catch (TwitchOAuthException $exception) {
            $this->logger->warning('Twitch authentication failed.', ['exception' => $exception]);

            return $this->render($request, $response->withStatus(400), 'auth/error.html.twig');
        }

        $location = match ($user->status) {
            UserStatus::Active => '/',
            UserStatus::Pending => '/access/pending',
            UserStatus::Suspended => '/access/suspended',
        };

        return $response->withStatus(302)->withHeader('Location', $location);
    }

    public function pending(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->render($request, $response, 'auth/pending.html.twig');
    }

    public function suspended(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->render($request, $response, 'auth/suspended.html.twig');
    }

    public function logout(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->session->logout();

        return $response->withStatus(302)->withHeader('Location', '/login');
    }

    private function render(
        ServerRequestInterface $request,
        ResponseInterface      $response,
        string                 $template,
    ): ResponseInterface
    {
        $response->getBody()->write($this->twig->render($template, [
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
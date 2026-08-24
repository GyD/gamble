<?php

declare(strict_types=1);

namespace Tests\Unit\Middleware;

use App\Domain\User\User;
use App\Domain\User\UserStatus;
use App\Middleware\NavigationIdentityMiddleware;
use App\Repository\UserStore;
use App\Security\AuthSession;
use PHPUnit\Framework\Attributes\BackupGlobals;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

#[BackupGlobals(true)]
final class NavigationIdentityMiddlewareTest extends TestCase
{
    public function testAuthenticatedUserCanLogoutFromEveryPage(): void
    {
        $_SESSION['authenticated_user_id'] = 42;
        $user = new User(42, '123', 'admin', 'Admin', 'https://example.com/avatar.png', UserStatus::Active);
        $users = $this->createStub(UserStore::class);
        $users->method('findById')->with(42)->willReturn($user);
        $twig = new Environment(new FilesystemLoader(dirname(__DIR__, 3) . '/templates'));
        $middleware = new NavigationIdentityMiddleware(new AuthSession(), $users, $twig);
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/bets')
            ->withAttribute('csrf_name', 'csrf-name-token')
            ->withAttribute('csrf_value', 'csrf-value-token');

        $html = (string) $middleware->process($request, $this->renderLayoutWith($twig))->getBody();

        self::assertStringContainsString('action="/auth/logout"', $html);
        self::assertStringContainsString('name="csrf_name" value="csrf-name-token"', $html);
        self::assertStringContainsString('name="csrf_value" value="csrf-value-token"', $html);
        self::assertStringContainsString('compte Twitch de Admin', $html);
        self::assertStringContainsString('src="https://example.com/avatar.png"', $html);
    }

    public function testAnonymousUserDoesNotSeeLogoutButton(): void
    {
        $_SESSION = [];
        $twig = new Environment(new FilesystemLoader(dirname(__DIR__, 3) . '/templates'));
        $middleware = new NavigationIdentityMiddleware(
            new AuthSession(),
            $this->createStub(UserStore::class),
            $twig,
        );
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/login');

        $html = (string) $middleware->process($request, $this->renderLayoutWith($twig))->getBody();

        self::assertStringNotContainsString('action="/auth/logout"', $html);
    }

    private function renderLayoutWith(Environment $twig): RequestHandlerInterface
    {
        return new class($twig) implements RequestHandlerInterface {
            public function __construct(private readonly Environment $twig)
            {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $response = new Response();
                $response->getBody()->write($this->twig->render('layout.html.twig'));

                return $response;
            }
        };
    }
}
<?php

declare(strict_types=1);

namespace Tests\Unit\Middleware;

use App\Middleware\CurrentPathMiddleware;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

final class CurrentPathMiddlewareTest extends TestCase
{
    public function testNavigationUsesAccessibleMobileMenuControl(): void
    {
        $html = $this->renderNavigation('/');

        self::assertStringContainsString('<div class="navigation-menu">', $html);
        self::assertStringContainsString(
            '<button class="navigation-toggle" type="button" aria-expanded="false" aria-controls="primary-navigation">',
            $html,
        );
        self::assertStringContainsString('class="navigation-toggle-icon" aria-hidden="true"', $html);
        self::assertStringContainsString(
            '<nav class="primary-navigation" id="primary-navigation" aria-label="Navigation principale">',
            $html,
        );
        self::assertStringContainsString('<script src="/assets/js/navigation-menu.js"></script>', $html);
    }

    #[DataProvider('navigationCases')]
    public function testCurrentNavigationItemIsActive(string $path, string $activeHref): void
    {
        $html = $this->renderNavigation($path);

        self::assertSame(1, substr_count($html, 'aria-current="page"'));
        self::assertMatchesRegularExpression(
            sprintf('/<a class="button button-primary" href="%s" aria-current="page">/', preg_quote($activeHref, '/')),
            $html,
        );
    }

    private function renderNavigation(string $path): string
    {
        $twig = new Environment(new FilesystemLoader(dirname(__DIR__, 3) . '/templates'));
        $twig->addGlobal('current_path', '');
        $middleware = new CurrentPathMiddleware($twig);
        $request = (new ServerRequestFactory())->createServerRequest('GET', $path);
        $handler = new class($twig) implements RequestHandlerInterface {
            public function __construct(private readonly Environment $twig)
            {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $response = new Response();
                $response->getBody()->write($this->twig->render('layout.html.twig', [
                    'can_view_bets' => true,
                    'can_view_contacts' => true,
                    'can_view_groups' => true,
                    'can_view_statistics' => true,
                    'can_view_users' => true,
                ]));

                return $response;
            }
        };

        return (string) $middleware->process($request, $handler)->getBody();
    }

    /** @return iterable<string, array{string, string}> */
    public static function navigationCases(): iterable
    {
        yield 'home' => ['/', '/'];
        yield 'bets index' => ['/bets', '/bets'];
        yield 'bet stakes' => ['/bets/12/stakes', '/bets'];
        yield 'contact edit' => ['/contacts/4/edit', '/contacts'];
        yield 'group edit' => ['/groups/3/edit', '/groups'];
        yield 'contact statistics' => ['/statistics/contacts/8', '/statistics'];
        yield 'user access' => ['/admin/users/2/access', '/admin/users'];
    }
}
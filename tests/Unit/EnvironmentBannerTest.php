<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

final class EnvironmentBannerTest extends TestCase
{
    #[DataProvider('nonProductionEnvironments')]
    public function testBannerIsDisplayedOutsideProduction(string $environment, string $label): void
    {
        $html = $this->renderLayout($environment);

        self::assertStringContainsString(
            sprintf('<div class="environment-banner environment-banner-%s" role="status">', $environment),
            $html,
        );
        self::assertStringContainsString(sprintf('Environnement : %s', $label), $html);
    }

    public function testBannerIsHiddenInProduction(): void
    {
        $html = $this->renderLayout('production');

        self::assertStringNotContainsString('environment-banner', $html);
        self::assertStringNotContainsString('Environnement :', $html);
    }

    private function renderLayout(string $environment): string
    {
        $twig = new Environment(new FilesystemLoader(dirname(__DIR__, 2) . '/templates'));
        $twig->addGlobal('current_path', '/');
        $twig->addGlobal('app_environment', $environment);

        return $twig->render('layout.html.twig');
    }

    /** @return iterable<string, array{string, string}> */
    public static function nonProductionEnvironments(): iterable
    {
        yield 'development' => ['development', 'développement'];
        yield 'test' => ['test', 'test'];
    }
}

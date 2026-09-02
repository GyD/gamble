<?php

declare(strict_types=1);

namespace Tests\Unit\Assets;

use App\Service\Market\MarketSettings;
use PHPUnit\Framework\TestCase;

/**
 * Behaviour of the pricing assistant script itself.
 *
 * The assistant only prefills the odds inputs of its form, so its generated
 * values must obey exactly the constraints of a typed odds: nothing below the
 * configured `minimum_odds`, which the inputs carry in their `min` attribute.
 *
 * The real script is executed through a small DOM harness so no browser and no
 * front-end tooling is needed.
 */
final class OddsAssistantTest extends TestCase
{
    public function testGeneratedOddsFollowTheDocumentedFormula(): void
    {
        // Two balanced choices at a 10 % target margin: 1.82 each, as documented.
        self::assertSame(['1.82', '1.82'], $this->generate([50.0, 50.0], 10.0)['odds']);
        // A favourite shortens while the outsider lengthens.
        self::assertSame(['1.52', '2.27'], $this->generate([60.0, 40.0], 10.0)['odds']);
        // Probabilities are normalised, so their sum needs not be exact.
        self::assertSame(['2.73', '2.73', '2.73'], $this->generate([50.0, 50.0, 50.0], 10.0)['odds']);
    }

    public function testOddsAboveTheFloorAreLeftUntouched(): void
    {
        $odds = $this->generate([80.0, 20.0], 10.0)['odds'];

        self::assertSame(['1.14', '4.55'], $odds);
        foreach ($odds as $value) {
            self::assertGreaterThan(1.01, (float) $value);
        }
    }

    public function testAnOverwhelmingProbabilityIsRaisedToTheMinimumOdds(): void
    {
        // Priced alone, this choice would be worth 1 / (0.999 x 1.5) = 0.67.
        $odds = $this->generate([99.9, 0.05, 0.05], 50.0)['odds'];

        self::assertSame('1.01', $odds[0]);
    }

    public function testTheFloorFollowsTheConfiguredMinimumOddsInsteadOfAHardcodedValue(): void
    {
        // The floor is read from the inputs, whose `min` is server-rendered from
        // `minimum_odds`: raising the configuration raises the generated odds.
        $odds = $this->generate([99.9, 0.05, 0.05], 50.0, 1.50)['odds'];

        self::assertSame('1.50', $odds[0]);
    }

    public function testNoGeneratedOddsCanEverViolateTheFormValidation(): void
    {
        $minimum = (new MarketSettings())->minimumOdds;

        // Extreme distributions and margins are the ones that used to price
        // below the floor: none of them may produce an invalid field anymore.
        foreach ([[99.99, 0.01], [1.0, 0.001], [50.0, 0.02, 0.02], [100.0, 0.05]] as $probabilities) {
            foreach ([0.0, 10.0, 50.0] as $margin) {
                foreach ($this->generate($probabilities, $margin)['odds'] as $value) {
                    self::assertGreaterThanOrEqual($minimum, (float) $value);
                    self::assertLessThanOrEqual(1000.0, (float) $value);
                    self::assertMatchesRegularExpression('/^\d{1,4}\.\d{2}$/', $value);
                }
            }
        }
    }

    public function testTheDisplayedMarginIsTheOneCarriedByTheGeneratedOdds(): void
    {
        self::assertSame(
            'Marge réellement portée par les cotes générées : 9,89 %.',
            $this->generate([50.0, 50.0], 10.0)['message'],
        );
    }

    /**
     * Runs the real assistant on the given probabilities.
     *
     * @param list<float> $probabilities
     * @return array{odds: list<string>, message: string}
     */
    private function generate(array $probabilities, float $margin, float $minimumOdds = 1.01): array
    {
        $root = dirname(__DIR__, 3);
        $payload = json_encode([
            'probabilities' => $probabilities,
            'margin' => $margin,
            'min' => number_format($minimumOdds, 2, '.', ''),
            'script' => $root . '/public/assets/js/odds-assistant.js',
        ], JSON_THROW_ON_ERROR);

        $output = shell_exec(sprintf(
            'node %s %s 2>/dev/null',
            escapeshellarg($root . '/tests/Unit/Assets/odds-assistant-harness.js'),
            escapeshellarg($payload),
        ));

        if (!is_string($output) || $output === '') {
            self::markTestSkipped('Node is required to run the pricing assistant script.');
        }

        /** @var array{odds: list<string>, message: string} $decoded */
        $decoded = json_decode($output, true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }
}

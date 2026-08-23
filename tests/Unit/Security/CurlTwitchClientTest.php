<?php

declare(strict_types=1);

namespace Tests\Unit\Security;

use App\Security\CurlTwitchClient;
use PHPUnit\Framework\TestCase;

final class CurlTwitchClientTest extends TestCase
{
    public function testAuthorizationUrlUsesCodeFlowStateAndNoScope(): void
    {
        $client = new CurlTwitchClient(
            'client-id',
            'client-secret',
            'https://gamble.example/auth/twitch/callback',
        );

        $url = $client->authorizationUrl('random-state');
        parse_str((string)parse_url($url, PHP_URL_QUERY), $query);

        self::assertSame('https', parse_url($url, PHP_URL_SCHEME));
        self::assertSame('id.twitch.tv', parse_url($url, PHP_URL_HOST));
        self::assertSame('client-id', $query['client_id']);
        self::assertSame('code', $query['response_type']);
        self::assertSame('random-state', $query['state']);
        self::assertArrayNotHasKey('scope', $query);
        self::assertArrayNotHasKey('client_secret', $query);
    }
}
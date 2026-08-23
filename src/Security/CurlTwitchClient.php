<?php

declare(strict_types=1);

namespace App\Security;

use App\Domain\User\TwitchIdentity;

final readonly class CurlTwitchClient implements TwitchClient
{
    private const AUTHORIZE_URL = 'https://id.twitch.tv/oauth2/authorize';
    private const TOKEN_URL = 'https://id.twitch.tv/oauth2/token';
    private const USER_URL = 'https://api.twitch.tv/helix/users';

    public function __construct(
        private string $clientId,
        private string $clientSecret,
        private string $redirectUri,
    )
    {
    }

    public function authorizationUrl(string $state): string
    {
        $this->assertConfigured();

        return self::AUTHORIZE_URL . '?' . http_build_query([
                'client_id' => $this->clientId,
                'redirect_uri' => $this->redirectUri,
                'response_type' => 'code',
                'state' => $state,
            ], '', '&', PHP_QUERY_RFC3986);
    }

    public function identityFromAuthorizationCode(string $code): TwitchIdentity
    {
        $this->assertConfigured();
        $token = $this->requestToken($code);
        $payload = $this->requestJson(self::USER_URL, [
            'Authorization: Bearer ' . $token,
            'Client-Id: ' . $this->clientId,
        ]);
        $user = $payload['data'][0] ?? null;

        if (!is_array($user) || !isset($user['id'], $user['login'], $user['display_name'])) {
            throw new TwitchOAuthException('Twitch did not return a valid user identity.');
        }

        return new TwitchIdentity(
            (string)$user['id'],
            (string)$user['login'],
            (string)$user['display_name'],
            isset($user['profile_image_url']) && $user['profile_image_url'] !== ''
                ? (string)$user['profile_image_url']
                : null,
        );
    }

    private function requestToken(string $code): string
    {
        $payload = $this->requestJson(self::TOKEN_URL, [], [
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'code' => $code,
            'grant_type' => 'authorization_code',
            'redirect_uri' => $this->redirectUri,
        ]);
        $token = $payload['access_token'] ?? null;

        if (!is_string($token) || $token === '') {
            throw new TwitchOAuthException('Twitch did not return an access token.');
        }

        return $token;
    }

    /**
     * @param list<string> $headers
     * @param array<string, string>|null $postFields
     * @return array<string, mixed>
     */
    private function requestJson(string $url, array $headers = [], ?array $postFields = null): array
    {
        $handle = curl_init($url);
        if ($handle === false) {
            throw new TwitchOAuthException('Unable to initialize the Twitch request.');
        }

        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTPHEADER => array_merge(['Accept: application/json'], $headers),
        ]);

        if ($postFields !== null) {
            curl_setopt($handle, CURLOPT_POST, true);
            curl_setopt($handle, CURLOPT_POSTFIELDS, http_build_query($postFields, '', '&', PHP_QUERY_RFC3986));
        }

        $body = curl_exec($handle);
        $status = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        curl_close($handle);

        if (!is_string($body) || $error !== '' || $status < 200 || $status >= 300) {
            throw new TwitchOAuthException(sprintf('Twitch request failed with HTTP status %d.', $status));
        }

        try {
            $payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new TwitchOAuthException('Twitch returned invalid JSON.', 0, $exception);
        }

        if (!is_array($payload)) {
            throw new TwitchOAuthException('Twitch returned an invalid response.');
        }

        return $payload;
    }

    private function assertConfigured(): void
    {
        if ($this->clientId === '' || $this->clientSecret === '' || $this->redirectUri === '') {
            throw new TwitchOAuthException('Twitch OAuth is not configured.');
        }
    }
}
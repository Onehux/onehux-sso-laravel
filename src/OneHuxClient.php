<?php
// src/OneHuxClient.php
// OneHuxClient -- PKCE generation, the hosted-login redirect, the authorization_code token
// exchange, /userinfo, and the RP-initiated /end-session redirect. Deliberately framework-
// agnostic (plain strings/arrays in and out, Guzzle for HTTP) -- Onehux\Sso\OneHuxSSOServiceProvider
// wires this to a real Laravel Session; usable directly in any PHP framework or a plain script.
//
// Two distinct hosts, by design (README.md ADR-070 in the backend repo, found by a real
// end-to-end manual walkthrough against production): the hosted login/logout pages live on
// loginBaseUrl, the actual OAuth API lives on the separate apiBaseUrl. Mixing them up
// previously produced a silent 404 HTML body instead of a JSON error -- this client never lets
// that ambiguity exist, since the two are separate constructor arguments, not one shared value.

namespace Onehux\Sso;

use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Exception\GuzzleException;
use Onehux\Sso\Exceptions\InvalidStateException;
use Onehux\Sso\Exceptions\TokenExchangeException;
use Onehux\Sso\Exceptions\TokenExpiredException;

final class OneHuxClient
{
    private HttpClient $http;

    public function __construct(
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly string $redirectUri,
        private readonly string $postLogoutRedirectUri,
        private readonly string $loginBaseUrl = 'https://accounts.onehux.com',
        private readonly string $apiBaseUrl = 'https://api-accounts.onehux.com',
        private readonly string $scope = 'openid profile email',
        ?HttpClient $httpClient = null,
    ) {
        $this->http = $httpClient ?? new HttpClient(['timeout' => 10.0]);
    }

    private static function base64url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * Generate a fresh PKCE pair + state and build the hosted-login redirect URL. The caller
     * is responsible for persisting codeVerifier/state server-side (a real session) until the
     * callback -- never in a cookie the browser itself can read.
     */
    public function startAuthorization(): PendingAuthorization
    {
        $codeVerifier = self::base64url(random_bytes(48));
        $codeChallenge = self::base64url(hash('sha256', $codeVerifier, true));
        $state = self::base64url(random_bytes(16));

        $query = http_build_query([
            'client_id' => $this->clientId,
            'redirect_uri' => $this->redirectUri,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',
            'scope' => $this->scope,
            'state' => $state,
        ]);

        return new PendingAuthorization(
            codeVerifier: $codeVerifier,
            state: $state,
            authorizationUrl: rtrim($this->loginBaseUrl, '/') . '/login?' . $query,
        );
    }

    /**
     * Verify state, then exchange $code for real tokens via
     * POST {apiBaseUrl}/api/v1/oauth/token/. Throws InvalidStateException on a state
     * mismatch/missing code (never attempts the exchange in that case), and
     * TokenExchangeException carrying the real OAuth error/error_description on a non-2xx
     * response.
     */
    public function exchangeCode(
        string $code,
        string $state,
        ?string $expectedState,
        ?string $codeVerifier,
    ): TokenResult {
        if ($code === '' || $state === '' || $state !== $expectedState) {
            throw new InvalidStateException(
                'Callback state did not match the pending authorization, or code/state was '
                . 'missing -- this callback is either stale, replayed, or forged.'
            );
        }

        try {
            $response = $this->http->post(
                rtrim($this->apiBaseUrl, '/') . '/api/v1/oauth/token/',
                ['json' => [
                    'grant_type' => 'authorization_code',
                    'code' => $code,
                    'redirect_uri' => $this->redirectUri,
                    'client_id' => $this->clientId,
                    'client_secret' => $this->clientSecret,
                    'code_verifier' => $codeVerifier,
                ], 'http_errors' => false]
            );
        } catch (GuzzleException $exception) {
            throw new TokenExchangeException('network_error', $exception->getMessage(), 0);
        }

        $body = json_decode((string) $response->getBody(), true) ?? [];
        if ($response->getStatusCode() >= 300) {
            throw new TokenExchangeException(
                $body['error'] ?? 'unknown_error',
                $body['error_description'] ?? '',
                $response->getStatusCode(),
            );
        }

        return new TokenResult(
            accessToken: $body['access_token'],
            idToken: $body['id_token'],
            tokenType: $body['token_type'],
            expiresIn: $body['expires_in'],
            scope: $body['scope'],
        );
    }

    /**
     * GET {apiBaseUrl}/api/v1/oauth/userinfo/ -- real claims (sub, name, email, picture,
     * roles, permissions, ...), recomputed live by the backend on every call, never cached
     * here. Throws TokenExpiredException on any non-2xx response: OneHux Accounts access
     * tokens are a 15-minute, single-issue lifetime with no refresh token today, so an
     * expired/invalid token here means "send the user through startAuthorization() again,"
     * not "retry."
     *
     * @return array<string, mixed>
     */
    public function getUserinfo(string $accessToken): array
    {
        try {
            $response = $this->http->get(
                rtrim($this->apiBaseUrl, '/') . '/api/v1/oauth/userinfo/',
                ['headers' => ['Authorization' => "Bearer {$accessToken}"], 'http_errors' => false]
            );
        } catch (GuzzleException $exception) {
            throw new TokenExpiredException($exception->getMessage());
        }

        if ($response->getStatusCode() >= 300) {
            throw new TokenExpiredException(
                'The access token was rejected by /userinfo -- it has either expired '
                . '(15-minute lifetime, no refresh token issued by this platform today) or was '
                . 'revoked. Send the user back through OneHuxClient::startAuthorization().'
            );
        }

        return json_decode((string) $response->getBody(), true) ?? [];
    }

    /**
     * Build the RP-initiated logout redirect (README.md ADR-070, backend repo):
     * postLogoutRedirectUri must already be registered in this Application's own redirect_uris
     * list -- the same list the login callback uses, not a separate one -- or the platform
     * rejects this with a real 400.
     */
    public function buildLogoutUrl(?string $state = null): string
    {
        $params = [
            'client_id' => $this->clientId,
            'post_logout_redirect_uri' => $this->postLogoutRedirectUri,
        ];
        if ($state !== null) {
            $params['state'] = $state;
        }

        return rtrim($this->loginBaseUrl, '/') . '/end-session?' . http_build_query($params);
    }
}

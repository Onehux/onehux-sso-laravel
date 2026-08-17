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
use Onehux\Sso\Exceptions\InvalidLogoutTokenException;
use Onehux\Sso\Exceptions\InvalidStateException;
use Onehux\Sso\Exceptions\TokenExchangeException;
use Onehux\Sso\Exceptions\TokenExpiredException;

final class OneHuxClient
{
    private const LOGOUT_EVENT_CLAIM_KEY = 'http://schemas.openid.net/event/backchannel-logout';

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

    private static function base64urlDecode(string $data): string
    {
        $padded = strtr($data, '-_', '+/');
        $remainder = strlen($padded) % 4;
        if ($remainder !== 0) {
            $padded .= str_repeat('=', 4 - $remainder);
        }
        $decoded = base64_decode($padded, true);
        return $decoded === false ? '' : $decoded;
    }

    /**
     * Pull the `sid` claim out of an id_token WITHOUT verifying its signature -- this package
     * cannot verify an id_token's signature at all (OneHux Accounts signs it with a server-only
     * key never shared with any client -- the backend repo's oauth.services.build_jwt()
     * docstring flags this as a deliberate Phase-1 gap), and doesn't need to for this purpose:
     * the token was retrieved directly from a clientSecret-authenticated POST to
     * /api/v1/oauth/token/ over TLS, not an untrusted redirect parameter, so trusting its
     * contents here (indexing this local session for a later logout_token match) is standard
     * OIDC RP practice. Returns null if the token doesn't decode or has no sid claim.
     */
    public function extractSidFromIdToken(string $idToken): ?string
    {
        $parts = explode('.', $idToken);
        if (count($parts) !== 3) {
            return null;
        }
        $payload = json_decode(self::base64urlDecode($parts[1]), true);
        return is_array($payload) && isset($payload['sid']) && is_string($payload['sid'])
            ? $payload['sid']
            : null;
    }

    /**
     * Real OIDC Back-Channel Logout validation (spec section 2.6), HS256-verified by hand via
     * hash_hmac() -- this package's only other dependency is Guzzle, and a full JWT library
     * isn't worth pulling in for one HMAC check. $signingSecret is the dedicated secret shown
     * once when you registered your backchannel_logout_uri via
     * PATCH /api/v1/applications/{id}/backchannel-logout/ -- deliberately NOT this client's own
     * clientSecret (the backend cannot read that back to sign anything with it; see the backend
     * repo's README.md ADR-074). Throws InvalidLogoutTokenException on any validation failure.
     *
     * @return array<string, mixed>
     */
    public function verifyLogoutToken(string $logoutToken, ?string $signingSecret): array
    {
        if ($signingSecret === null || $signingSecret === '') {
            throw new InvalidLogoutTokenException(
                'No backchannel logout signing secret configured '
                . '(onehux-sso.backchannel_logout_signing_secret).'
            );
        }

        $parts = explode('.', $logoutToken);
        if (count($parts) !== 3) {
            throw new InvalidLogoutTokenException('logout_token is not a well-formed JWT.');
        }
        [$headerB64, $payloadB64, $signatureB64] = $parts;

        $header = json_decode(self::base64urlDecode($headerB64), true);
        $payload = json_decode(self::base64urlDecode($payloadB64), true);
        if (!is_array($header) || !is_array($payload)) {
            throw new InvalidLogoutTokenException('logout_token header/payload is not valid JSON.');
        }
        if (($header['alg'] ?? null) !== 'HS256') {
            $alg = is_string($header['alg'] ?? null) ? $header['alg'] : 'none';
            throw new InvalidLogoutTokenException("Unsupported logout_token alg: {$alg}");
        }

        $expectedSignature = hash_hmac('sha256', "{$headerB64}.{$payloadB64}", $signingSecret, true);
        $actualSignature = self::base64urlDecode($signatureB64);
        if (!hash_equals($expectedSignature, $actualSignature)) {
            throw new InvalidLogoutTokenException('logout_token signature verification failed.');
        }

        if (!isset($payload['exp']) || !is_int($payload['exp']) || $payload['exp'] < time()) {
            throw new InvalidLogoutTokenException('logout_token has expired.');
        }
        if (($payload['aud'] ?? null) !== $this->clientId) {
            throw new InvalidLogoutTokenException('logout_token aud does not match this client_id.');
        }
        if (array_key_exists('nonce', $payload)) {
            throw new InvalidLogoutTokenException('logout_token MUST NOT contain a nonce claim.');
        }
        $events = $payload['events'] ?? null;
        if (!is_array($events) || !array_key_exists(self::LOGOUT_EVENT_CLAIM_KEY, $events)) {
            throw new InvalidLogoutTokenException(
                'logout_token is missing the required events claim (' . self::LOGOUT_EVENT_CLAIM_KEY . ').'
            );
        }
        if (empty($payload['sub']) && empty($payload['sid'])) {
            throw new InvalidLogoutTokenException(
                'logout_token must contain a sub claim, a sid claim, or both.'
            );
        }

        return $payload;
    }
}

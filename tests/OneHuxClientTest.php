<?php
// tests/OneHuxClientTest.php
// Real unit tests for OneHuxClient -- PKCE generation/matching, every error-type branch, every
// URL-building method, and logout_token HMAC verification. No live network calls: Guzzle's
// MockHandler stands in for OneHux's API via the client's injectable HttpClient constructor arg.

namespace Onehux\Sso\Tests;

use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Onehux\Sso\Exceptions\InvalidStateException;
use Onehux\Sso\Exceptions\OrganizationNotFoundException;
use Onehux\Sso\Exceptions\StepUpRequiredException;
use Onehux\Sso\Exceptions\TokenExchangeException;
use Onehux\Sso\Exceptions\TokenExpiredException;
use Onehux\Sso\OneHuxClient;
use PHPUnit\Framework\TestCase;

final class OneHuxClientTest extends TestCase
{
    private function makeClient(?MockHandler $mock = null): OneHuxClient
    {
        $httpClient = null;
        if ($mock !== null) {
            $httpClient = new HttpClient(['handler' => HandlerStack::create($mock)]);
        }
        return new OneHuxClient(
            clientId: 'test-client-id',
            clientSecret: 'test-client-secret',
            redirectUri: 'https://app.example.com/auth/callback',
            postLogoutRedirectUri: 'https://app.example.com/auth/logged-out',
            loginBaseUrl: 'https://accounts.example.com',
            apiBaseUrl: 'https://api.example.com',
            httpClient: $httpClient,
        );
    }

    private static function base64url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    // --- PKCE generation/matching ---

    public function testStartAuthorizationCodeChallengeMatchesVerifier(): void
    {
        $client = $this->makeClient();
        $pending = $client->startAuthorization();

        $this->assertNotEmpty($pending->codeVerifier);
        $this->assertNotEmpty($pending->state);

        parse_str(parse_url($pending->authorizationUrl, PHP_URL_QUERY), $query);
        $this->assertSame($pending->state, $query['state']);
        $this->assertSame('S256', $query['code_challenge_method']);

        $expectedChallenge = self::base64url(hash('sha256', $pending->codeVerifier, true));
        $this->assertSame($expectedChallenge, $query['code_challenge']);
    }

    public function testStartAuthorizationGeneratesFreshValuesEachCall(): void
    {
        $client = $this->makeClient();
        $first = $client->startAuthorization();
        $second = $client->startAuthorization();

        $this->assertNotSame($first->state, $second->state);
        $this->assertNotSame($first->codeVerifier, $second->codeVerifier);
    }

    // --- exchangeCode error branches ---

    public function testExchangeCodeThrowsInvalidStateExceptionOnMismatch(): void
    {
        $client = $this->makeClient();
        $this->expectException(InvalidStateException::class);
        $client->exchangeCode('real-code', 'a', 'b', 'verifier');
    }

    public function testExchangeCodeThrowsInvalidStateExceptionOnMissingCode(): void
    {
        $client = $this->makeClient();
        $this->expectException(InvalidStateException::class);
        $client->exchangeCode('', 's', 's', 'verifier');
    }

    public function testExchangeCodeThrowsStepUpRequiredException(): void
    {
        $mock = new MockHandler([
            new Response(403, [], json_encode([
                'error' => 'step_up_required',
                'error_description' => 'New device or location detected.',
            ])),
        ]);
        $client = $this->makeClient($mock);

        try {
            $client->exchangeCode('code', 's', 's', 'verifier');
            $this->fail('Expected StepUpRequiredException');
        } catch (StepUpRequiredException $exception) {
            $this->assertSame('New device or location detected.', $exception->errorDescription);
        }
    }

    public function testExchangeCodeThrowsTokenExchangeExceptionOnOtherOAuthError(): void
    {
        $mock = new MockHandler([
            new Response(400, [], json_encode([
                'error' => 'invalid_grant',
                'error_description' => 'Authorization code is expired.',
            ])),
        ]);
        $client = $this->makeClient($mock);

        try {
            $client->exchangeCode('code', 's', 's', 'verifier');
            $this->fail('Expected TokenExchangeException');
        } catch (TokenExchangeException $exception) {
            $this->assertSame('invalid_grant', $exception->error);
            $this->assertSame(400, $exception->statusCode);
        }
    }

    public function testExchangeCodeSuccess(): void
    {
        $mock = new MockHandler([
            new Response(200, [], json_encode([
                'access_token' => 'at-123',
                'id_token' => 'id-456',
                'token_type' => 'Bearer',
                'expires_in' => 900,
                'scope' => 'openid profile email',
            ])),
        ]);
        $client = $this->makeClient($mock);

        $tokens = $client->exchangeCode('code', 's', 's', 'verifier');
        $this->assertSame('at-123', $tokens->accessToken);
        $this->assertSame(900, $tokens->expiresIn);
    }

    // --- getUserinfo ---

    public function testGetUserinfoThrowsTokenExpiredExceptionOnNon2xx(): void
    {
        $mock = new MockHandler([new Response(401)]);
        $client = $this->makeClient($mock);

        $this->expectException(TokenExpiredException::class);
        $client->getUserinfo('expired-token');
    }

    // --- getPublicApplications ---

    public function testGetPublicApplicationsSuccess(): void
    {
        $mock = new MockHandler([
            new Response(200, [], json_encode([
                ['name' => 'ODS', 'logo_url' => 'https://example.com/logo.png', 'home_url' => 'https://ods.example.com'],
            ])),
        ]);
        $client = $this->makeClient($mock);

        $apps = $client->getPublicApplications('onehux');
        $this->assertCount(1, $apps);
        $this->assertSame('ODS', $apps[0]->name);
        $this->assertSame('https://ods.example.com', $apps[0]->homeUrl);
    }

    public function testGetPublicApplicationsThrowsOrganizationNotFoundException(): void
    {
        $mock = new MockHandler([
            new Response(404, [], json_encode([
                'error' => 'not_found',
                'error_description' => 'No Organization matches that slug.',
            ])),
        ]);
        $client = $this->makeClient($mock);

        $this->expectException(OrganizationNotFoundException::class);
        $client->getPublicApplications('nope');
    }

    // --- URL-building methods ---

    public function testBuildLogoutUrlNoStateWhenOmitted(): void
    {
        $client = $this->makeClient();
        $url = $client->buildLogoutUrl();

        $this->assertStringStartsWith('https://accounts.example.com/end-session?', $url);
        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame('test-client-id', $query['client_id']);
        $this->assertSame('https://app.example.com/auth/logged-out', $query['post_logout_redirect_uri']);
        $this->assertArrayNotHasKey('state', $query);
    }

    public function testBuildLogoutUrlIncludesStateWhenGiven(): void
    {
        $client = $this->makeClient();
        parse_str(parse_url($client->buildLogoutUrl('xyz'), PHP_URL_QUERY), $query);
        $this->assertSame('xyz', $query['state']);
    }

    public function testBuildStepUpRedirectUrl(): void
    {
        $client = $this->makeClient();
        $codeVerifier = 'abc123verifier';
        $url = $client->buildStepUpRedirectUrl($codeVerifier, 'state-xyz');

        $this->assertStringStartsWith('https://accounts.example.com/login/email-otp?', $url);
        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame('step_up', $query['reason']);
        $this->assertSame('test-client-id', $query['client_id']);
        $this->assertSame('https://app.example.com/auth/callback', $query['redirect_uri']);
        $this->assertSame('state-xyz', $query['state']);

        $expectedChallenge = self::base64url(hash('sha256', $codeVerifier, true));
        $this->assertSame($expectedChallenge, $query['code_challenge']);
    }

    // --- logout_token HMAC verification ---

    private function buildLogoutToken(string $secret, array $claims): string
    {
        $header = self::base64url(json_encode(['alg' => 'HS256', 'typ' => 'logout+jwt']));
        $payload = self::base64url(json_encode($claims));
        $signature = self::base64url(hash_hmac('sha256', "{$header}.{$payload}", $secret, true));
        return "{$header}.{$payload}.{$signature}";
    }

    private function validClaims(): array
    {
        $now = time();
        return [
            'iss' => 'https://accounts.onehux.com',
            'aud' => 'test-client-id',
            'iat' => $now,
            'exp' => $now + 120,
            'jti' => 'unique-id',
            'events' => ['http://schemas.openid.net/event/backchannel-logout' => new \stdClass()],
            'sid' => 'session-123',
        ];
    }

    public function testVerifyLogoutTokenAcceptsValidToken(): void
    {
        $client = $this->makeClient();
        $token = $this->buildLogoutToken('shared-secret', $this->validClaims());
        $payload = $client->verifyLogoutToken($token, 'shared-secret');
        $this->assertSame('session-123', $payload['sid']);
    }

    public function testVerifyLogoutTokenRejectsWrongSignature(): void
    {
        $client = $this->makeClient();
        $token = $this->buildLogoutToken('shared-secret', $this->validClaims());
        $this->expectException(\Onehux\Sso\Exceptions\InvalidLogoutTokenException::class);
        $client->verifyLogoutToken($token, 'wrong-secret');
    }

    public function testVerifyLogoutTokenRejectsExpiredToken(): void
    {
        $client = $this->makeClient();
        $claims = $this->validClaims();
        $claims['exp'] = time() - 60;
        $token = $this->buildLogoutToken('shared-secret', $claims);
        $this->expectException(\Onehux\Sso\Exceptions\InvalidLogoutTokenException::class);
        $client->verifyLogoutToken($token, 'shared-secret');
    }

    public function testVerifyLogoutTokenRejectsWrongAudience(): void
    {
        $client = $this->makeClient();
        $claims = $this->validClaims();
        $claims['aud'] = 'some-other-client';
        $token = $this->buildLogoutToken('shared-secret', $claims);
        $this->expectException(\Onehux\Sso\Exceptions\InvalidLogoutTokenException::class);
        $client->verifyLogoutToken($token, 'shared-secret');
    }

    public function testVerifyLogoutTokenRejectsNoncePresent(): void
    {
        $client = $this->makeClient();
        $claims = $this->validClaims();
        $claims['nonce'] = 'should-not-be-here';
        $token = $this->buildLogoutToken('shared-secret', $claims);
        $this->expectException(\Onehux\Sso\Exceptions\InvalidLogoutTokenException::class);
        $client->verifyLogoutToken($token, 'shared-secret');
    }

    public function testVerifyLogoutTokenRejectsMissingEventsClaim(): void
    {
        $client = $this->makeClient();
        $claims = $this->validClaims();
        unset($claims['events']);
        $token = $this->buildLogoutToken('shared-secret', $claims);
        $this->expectException(\Onehux\Sso\Exceptions\InvalidLogoutTokenException::class);
        $client->verifyLogoutToken($token, 'shared-secret');
    }

    public function testVerifyLogoutTokenRejectsMissingSubAndSid(): void
    {
        $client = $this->makeClient();
        $claims = $this->validClaims();
        unset($claims['sid']);
        $token = $this->buildLogoutToken('shared-secret', $claims);
        $this->expectException(\Onehux\Sso\Exceptions\InvalidLogoutTokenException::class);
        $client->verifyLogoutToken($token, 'shared-secret');
    }

    public function testVerifyLogoutTokenRejectsNoSigningSecretConfigured(): void
    {
        $client = $this->makeClient();
        $token = $this->buildLogoutToken('shared-secret', $this->validClaims());
        $this->expectException(\Onehux\Sso\Exceptions\InvalidLogoutTokenException::class);
        $client->verifyLogoutToken($token, null);
    }
}

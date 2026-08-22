<?php
// tests/OneHuxSSOControllerTest.php
// Real HTTP feature tests for the package's registered routes, run through a full Laravel
// application (Orchestra Testbench) -- not just unit tests of OneHuxClient in isolation.
//
// Exists specifically to verify that TokenExpiredException thrown deep inside
// OneHuxClient::getUserinfo() actually reaches the browser/API caller as a clean, documented
// 401 response rather than a raw framework exception page or a silently-succeeding request.
//
// Important architectural fact this test confirms rather than assumes: this package has NO
// middleware of its own. {prefix}/userinfo is a plain JSON API endpoint (the BFF pattern
// described in its own docblock) that catches TokenExpiredException explicitly in
// OneHuxSSOController::userinfo() and returns 401 JSON -- exactly the same "catch it in the
// controller, don't register a render()" convention this package already uses for
// StepUpRequiredException in callback(). There is no server-side "protected route" that
// redirects a browser on token expiry; the frontend consuming {prefix}/userinfo is responsible
// for redirecting to {prefix}/login on a 401, per the README.

namespace Onehux\Sso\Tests;

use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Onehux\Sso\OneHuxClient;
use Onehux\Sso\OneHuxSSOServiceProvider;
use Orchestra\Testbench\TestCase;

final class OneHuxSSOControllerTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [OneHuxSSOServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('onehux-sso.client_id', 'test-client-id');
        $app['config']->set('onehux-sso.client_secret', 'test-client-secret');
        $app['config']->set('onehux-sso.redirect_uri', 'https://app.example.com/auth/callback');
        $app['config']->set('onehux-sso.post_logout_redirect_uri', 'https://app.example.com/auth/logged-out');
        $app['config']->set('onehux-sso.session_access_token_key', 'onehux_access_token');

        // Real session config, same defaults an integrating app would ship (see
        // example/.env.example) so this test exercises the real 'web' middleware group's
        // session handling, not a stubbed-out one.
        $app['config']->set('session.driver', 'array');

        // Required for the 'web' middleware group (EncryptCookies) to boot; irrelevant to
        // what this test is actually verifying, just infrastructure to let a real HTTP
        // request through the real middleware stack.
        $app['config']->set('app.key', 'base64:' . base64_encode(random_bytes(32)));
    }

    /** OneHuxClient is `final` and constructed by the real ServiceProvider from config, with
     * its own internal Guzzle client -- so rather than subclassing it (impossible) or reaching
     * into private state, this rebinds the singleton to a real OneHuxClient instance wired to
     * a Guzzle MockHandler, exactly like tests/OneHuxClientTest.php does for its own unit tests.
     * This exercises the real getUserinfo() HTTP-error-handling code path, not a stand-in. */
    private function bindClientReturning(int $statusCode, string $body = '{}'): void
    {
        $mock = new MockHandler([new Response($statusCode, [], $body)]);
        $httpClient = new HttpClient(['handler' => HandlerStack::create($mock)]);

        $this->app->instance(
            OneHuxClient::class,
            new OneHuxClient(
                clientId: 'test-client-id',
                clientSecret: 'test-client-secret',
                redirectUri: 'https://app.example.com/auth/callback',
                postLogoutRedirectUri: 'https://app.example.com/auth/logged-out',
                httpClient: $httpClient,
            ),
        );
    }

    /** No token in session at all: 401 "Not signed in", never a 500 -- the unauthenticated
     * case, distinct from the expired-token case below. */
    public function testUserinfoWithNoSessionTokenReturns401NotSignedIn(): void
    {
        $response = $this->get('/auth/userinfo');

        $response->assertStatus(401);
        $response->assertJson(['detail' => 'Not signed in.']);
    }

    /** The real bug-fix scenario: a token IS present in the session (so the caller believes
     * itself signed in) but OneHux Accounts has expired/revoked it. This asserts the full
     * request -- through the package's registered route, its 'web' middleware group, and the
     * controller's own try/catch around OneHuxClient::getUserinfo() -- ends in a clean 401 JSON
     * body carrying the real exception message, never a raw exception/debug page (500) and
     * never a 200 as if nothing were wrong. */
    public function testUserinfoWithExpiredTokenReturns401CleanlyNotA500(): void
    {
        $this->bindClientReturning(401, '{"detail":"token expired or revoked"}');

        $response = $this->withSession(['onehux_access_token' => 'an-expired-token'])
            ->get('/auth/userinfo');

        // The whole point: this must be a clean, catchable 401 -- not Laravel's default
        // exception renderer (500/debug page) and not a 200 that would let the caller believe
        // it still holds a valid session.
        $response->assertStatus(401);
        $response->assertJsonStructure(['detail']);
        $this->assertNotSame(500, $response->getStatusCode());
        $this->assertStringContainsString(
            'access token was rejected',
            $response->json('detail'),
        );
    }

    /** Confirms the same route wiring succeeds end-to-end on a genuinely valid token, so the
     * 401 assertions above are meaningful contrasts rather than the route being broken outright. */
    public function testUserinfoWithValidTokenReturns200WithClaims(): void
    {
        $this->bindClientReturning(200, '{"sub":"user-123","email":"user@example.com"}');

        $response = $this->withSession(['onehux_access_token' => 'a-valid-token'])
            ->get('/auth/userinfo');

        $response->assertStatus(200);
        $response->assertJson(['sub' => 'user-123', 'email' => 'user@example.com']);
    }
}

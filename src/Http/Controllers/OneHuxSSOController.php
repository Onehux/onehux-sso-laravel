<?php
// src/Http/Controllers/OneHuxSSOController.php
// Real, runnable Laravel controller wiring OneHuxClient to a real Session -- the BFF
// discipline the platform's own dashboard follows on itself: the access token lives only in
// Laravel's server-side session store, never sent to the browser.

namespace Onehux\Sso\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Session;
use Onehux\Sso\Exceptions\InvalidLogoutTokenException;
use Onehux\Sso\Exceptions\InvalidStateException;
use Onehux\Sso\Exceptions\StepUpRequiredException;
use Onehux\Sso\Exceptions\TokenExchangeException;
use Onehux\Sso\Exceptions\TokenExpiredException;
use Onehux\Sso\OneHuxClient;

class OneHuxSSOController extends Controller
{
    private const STATE_SESSION_KEY = 'onehux_sso_state';
    private const VERIFIER_SESSION_KEY = 'onehux_sso_pkce_verifier';
    private const SID_SESSION_KEY = 'onehux_sso_sid';
    private const SID_CACHE_KEY_PREFIX = 'onehux_sso:sid:';

    public function __construct(private readonly OneHuxClient $client)
    {
    }

    /** GET {prefix}/login -- starts the flow: generates PKCE + state, stashes them in the
     * session, redirects to the real hosted login page. */
    public function login(): RedirectResponse
    {
        $pending = $this->client->startAuthorization();
        Session::put(self::STATE_SESSION_KEY, $pending->state);
        Session::put(self::VERIFIER_SESSION_KEY, $pending->codeVerifier);

        return redirect()->away($pending->authorizationUrl);
    }

    /** GET {prefix}/callback -- verifies state, exchanges the code, stores the access token in
     * the session, redirects to config('onehux-sso.login_success_redirect').
     *
     * On a step_up_required response specifically (README.md ADR-076, backend repo), this
     * redirects the browser to complete step-up rather than failing -- the pending PKCE
     * state/verifier is deliberately NOT cleared in that one case, since the browser will land
     * back on this exact action shortly with a brand-new code for the same state. Every other
     * outcome (success, InvalidStateException, any other TokenExchangeException) clears it
     * exactly as before -- this is a narrow, additive branch, not a change to the general
     * discard behavior. */
    public function callback(Request $request): RedirectResponse|Response
    {
        if ($request->has('error')) {
            Session::forget(self::STATE_SESSION_KEY);
            Session::forget(self::VERIFIER_SESSION_KEY);
            return response(
                "Sign-in failed: {$request->query('error')} — {$request->query('error_description', '')}",
                400,
            );
        }

        // Peeked, not pulled: the step_up_required branch below needs these to still be in the
        // session if it fires. Every other branch forgets them explicitly before returning.
        $expectedState = Session::get(self::STATE_SESSION_KEY);
        $codeVerifier = Session::get(self::VERIFIER_SESSION_KEY);
        $state = $request->query('state', '');

        try {
            $tokens = $this->client->exchangeCode(
                $request->query('code', ''),
                $state,
                $expectedState,
                $codeVerifier,
            );
        } catch (InvalidStateException $exception) {
            Session::forget(self::STATE_SESSION_KEY);
            Session::forget(self::VERIFIER_SESSION_KEY);
            return response($exception->getMessage(), 400);
        } catch (StepUpRequiredException) {
            if ($codeVerifier === null || $state === '') {
                return response('step_up_required with no pending PKCE state to resume.', 400);
            }
            return redirect()->away($this->client->buildStepUpRedirectUrl($codeVerifier, $state));
        } catch (TokenExchangeException $exception) {
            Session::forget(self::STATE_SESSION_KEY);
            Session::forget(self::VERIFIER_SESSION_KEY);
            return response("{$exception->error}: {$exception->errorDescription}", 400);
        }

        Session::forget(self::STATE_SESSION_KEY);
        Session::forget(self::VERIFIER_SESSION_KEY);
        Session::put(config('onehux-sso.session_access_token_key'), $tokens->accessToken);

        // OIDC Back-Channel Logout (optional): index this Laravel session by the OneHux
        // Session id (`sid`) carried in the id_token, so a later logout_token POST to
        // {prefix}/backchannel-logout can find and destroy exactly this session. Cache is used
        // (not a package-private in-memory map) so this index works correctly across multiple
        // PHP-FPM/queue worker processes, backed by whatever CACHE_STORE this app already runs.
        $sid = $this->client->extractSidFromIdToken($tokens->idToken);
        if ($sid !== null) {
            Session::put(self::SID_SESSION_KEY, $sid);
            Cache::put(self::SID_CACHE_KEY_PREFIX . $sid, Session::getId(), now()->addDays(7));
        }

        return redirect(config('onehux-sso.login_success_redirect'));
    }

    /** GET {prefix}/logout -- clears the local session access token, then redirects through
     * the real RP-initiated /end-session flow, ending the platform-wide session, not just this
     * app's own local one. */
    public function logout(): RedirectResponse
    {
        Session::forget(config('onehux-sso.session_access_token_key'));

        return redirect()->away($this->client->buildLogoutUrl());
    }

    /** GET {prefix}/userinfo -- a ready-to-use JSON endpoint for your own frontend to call,
     * matching the BFF pattern: your frontend never talks to OneHux directly. Returns 401 with
     * the real TokenExpiredException message when the session's token is expired/invalid, so
     * the caller knows to redirect through {prefix}/login again rather than retry. */
    public function userinfo(): JsonResponse
    {
        $accessToken = Session::get(config('onehux-sso.session_access_token_key'));
        if (! $accessToken) {
            return response()->json(['detail' => 'Not signed in.'], 401);
        }

        try {
            $claims = $this->client->getUserinfo($accessToken);
        } catch (TokenExpiredException $exception) {
            return response()->json(['detail' => $exception->getMessage()], 401);
        }

        return response()->json($claims);
    }

    /**
     * POST {prefix}/backchannel-logout -- OIDC Back-Channel Logout receiving endpoint (spec:
     * openid-connect-backchannel-1_0.html section 2.6). Register this exact URL (e.g.
     * https://yourapp.example.com/auth/backchannel-logout) via
     * PATCH /api/v1/applications/{id}/backchannel-logout/ on OneHux, and set
     * ONEHUX_BACKCHANNEL_LOGOUT_SIGNING_SECRET to the secret returned by that call.
     *
     * This is what makes an IdP-initiated logout (a user clicking "log out" directly on
     * accounts.onehux.com, or an admin revoking their session) actually terminate THIS app's
     * own local Laravel session in real time, rather than only being discovered the next time
     * this app happens to call /userinfo and gets a 401. The platform-side session is genuinely
     * revoked immediately either way -- without this route mounted and registered, only the
     * notification is missing.
     *
     * Deliberately registered OUTSIDE the 'web' middleware group (see
     * OneHuxSSOServiceProvider::boot()) -- this is a server-to-server POST from OneHux's own
     * backend with no browser session/cookie of its own to carry a CSRF token, so the 'web'
     * group's VerifyCsrfToken middleware would incorrectly reject every real delivery. The
     * logout_token's own HS256 signature (verified below) is this route's real authenticity
     * check, not Laravel's CSRF protection.
     *
     * Per spec: responds 200 on success, 400 on any validation failure, always with
     * Cache-Control: no-store.
     */
    public function backchannelLogout(Request $request): Response|JsonResponse
    {
        $logoutToken = (string) $request->input('logout_token', '');
        if ($logoutToken === '') {
            return $this->backchannelLogoutError('Missing logout_token.');
        }

        try {
            $payload = $this->client->verifyLogoutToken(
                $logoutToken,
                config('onehux-sso.backchannel_logout_signing_secret'),
            );
        } catch (InvalidLogoutTokenException $exception) {
            return $this->backchannelLogoutError($exception->getMessage());
        }

        // A miss here (already logged out locally, or this process never saw the login) is a
        // correct, spec-defined success case ("the logout is considered to have succeeded"),
        // not an error -- still responds 200 below.
        $sid = $payload['sid'] ?? null;
        if (is_string($sid)) {
            $sessionId = Cache::pull(self::SID_CACHE_KEY_PREFIX . $sid);
            if (is_string($sessionId)) {
                // Resolved via the configured session driver's own handler (file/database/
                // redis/...) -- generic across every SESSION_DRIVER this app might use, never
                // hardcoded to one backend.
                app('session')->driver()->getHandler()->destroy($sessionId);
            }
        }

        return response('', 200)->header('Cache-Control', 'no-store');
    }

    private function backchannelLogoutError(string $detail): JsonResponse
    {
        return response()->json(['error' => 'invalid_request', 'error_description' => $detail], 400)
            ->header('Cache-Control', 'no-store');
    }
}

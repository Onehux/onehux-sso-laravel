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
use Illuminate\Support\Facades\Session;
use Onehux\Sso\Exceptions\InvalidStateException;
use Onehux\Sso\Exceptions\TokenExchangeException;
use Onehux\Sso\Exceptions\TokenExpiredException;
use Onehux\Sso\OneHuxClient;

class OneHuxSSOController extends Controller
{
    private const STATE_SESSION_KEY = 'onehux_sso_state';
    private const VERIFIER_SESSION_KEY = 'onehux_sso_pkce_verifier';

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
     * the session, redirects to config('onehux-sso.login_success_redirect'). */
    public function callback(Request $request): RedirectResponse|Response
    {
        if ($request->has('error')) {
            return response(
                "Sign-in failed: {$request->query('error')} — {$request->query('error_description', '')}",
                400,
            );
        }

        $expectedState = Session::pull(self::STATE_SESSION_KEY);
        $codeVerifier = Session::pull(self::VERIFIER_SESSION_KEY);

        try {
            $tokens = $this->client->exchangeCode(
                $request->query('code', ''),
                $request->query('state', ''),
                $expectedState,
                $codeVerifier,
            );
        } catch (InvalidStateException $exception) {
            return response($exception->getMessage(), 400);
        } catch (TokenExchangeException $exception) {
            return response("{$exception->error}: {$exception->errorDescription}", 400);
        }

        Session::put(config('onehux-sso.session_access_token_key'), $tokens->accessToken);

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
}

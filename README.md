# onehux/sso

A real, installable PHP/Laravel SDK wrapping OneHux Accounts' Authorization Code + PKCE flow
against its real hosted login page — formalizing what
[the Laravel integration guide](https://accounts.onehux.com/dashboard/docs/integrate/backend/laravel)
otherwise only shows as copy-paste example code.

Framework-agnostic at its core (`Onehux\Sso\OneHuxClient`, using Guzzle for HTTP) — usable in
any PHP project — plus a Laravel service provider (`Onehux\Sso\OneHuxSSOServiceProvider`,
auto-discovered) that wires it to a real Laravel `Session` and registers four real routes.

## Install

```bash
composer config repositories.onehux-sso path /path/to/onehux_sso_client/php-laravel-package
composer require onehux/sso:@dev
```

(Not yet published to Packagist — install via a local path repository until that's decided.)

## Two hosts — don't mix them up

`accounts.onehux.com` serves the hosted login/logout pages a browser is redirected to.
`api-accounts.onehux.com` serves the actual OAuth API your backend calls server-to-server. This
package keeps them as two separate config values (`login_base_url` / `api_base_url`) precisely
because collapsing them into one host was a real, confirmed bug in the original integration
guides (see the backend repo's `README.md`, ADR-070) — the wrong host doesn't error loudly, it
silently 404s.

## Setup (Laravel)

1. Register a real confidential-client `Application` in your OneHux Accounts Organization
   (Dashboard → Applications), with a `redirect_uri` pointing at wherever this package's
   `{prefix}/callback` route resolves (default prefix `auth`, so
   `https://yourapp.example.com/auth/callback`), **and** your `post_logout_redirect_uri`
   registered in that same list — OneHux Accounts validates both against the one
   `redirect_uris` list, not two separate ones.

2. Add to your `.env`:

   ```env
   ONEHUX_CLIENT_ID=onehux_client_...
   ONEHUX_CLIENT_SECRET=onehux_secret_...
   ONEHUX_REDIRECT_URI=https://yourapp.example.com/auth/callback
   ONEHUX_POST_LOGOUT_REDIRECT_URI=https://yourapp.example.com/auth/logged-out
   ```

3. (Optional) publish the config to customize the route prefix, success redirect, or the two
   hosts:

   ```bash
   php artisan vendor:publish --tag=onehux-sso-config
   ```

That's it — the service provider is auto-discovered. This gives you four real, working routes:
`/auth/login`, `/auth/callback`, `/auth/logout`, and `/auth/userinfo` (a ready-to-use JSON
endpoint your own frontend can call with credentials included, matching the BFF pattern — your
frontend never talks to OneHux directly) — plus a fifth, `/auth/backchannel-logout`, which only
does anything once you configure it (see "Logging out" below).

## Using the client directly (any PHP framework, or a custom flow)

```php
use Onehux\Sso\OneHuxClient;

$client = new OneHuxClient(
    clientId: 'onehux_client_...',
    clientSecret: 'onehux_secret_...',
    redirectUri: 'https://yourapp.example.com/auth/callback',
    postLogoutRedirectUri: 'https://yourapp.example.com/auth/logged-out',
);

$pending = $client->startAuthorization();
// stash $pending->state / $pending->codeVerifier in your own session, then redirect the
// browser to $pending->authorizationUrl

$tokens = $client->exchangeCode(
    code: $_GET['code'],
    state: $_GET['state'],
    expectedState: $session['onehux_sso_state'],
    codeVerifier: $session['onehux_sso_pkce_verifier'],
);

$claims = $client->getUserinfo($tokens->accessToken);

$logoutUrl = $client->buildLogoutUrl();
```

## Logging out — what the user actually sees

There are two different triggers, and — once you wire up back-channel logout (below) — they
produce the same fast, correct result. Understanding both is still worth it, since the second
one only becomes immediate if you actually complete the setup:

**1. The user clicks "Log out" inside your app (SP-initiated).** This package's own
`{prefix}/logout` route clears its local session *and* redirects through `/end-session` in the
same action, which ends the real, shared platform session immediately. From the user's point
of view: they click Log out, land on your app's own logged-out page, and if they then open the
dashboard or any other app, they're asked to log in again — everywhere, right away. This works
cleanly because your own app is the one driving both halves of the logout at once, with no
dependency on back-channel logout at all.

**2. The user logs out somewhere else — a different app, or directly at
`accounts.onehux.com`/the dashboard (IdP-initiated).** The shared platform session is revoked
immediately and correctly on the backend — same underlying revocation call as case 1. Whether
*your app* finds out immediately depends entirely on whether you've completed the back-channel
logout setup below:

- **With it wired up:** OneHux POSTs a signed `logout_token` to your `/auth/backchannel-logout`
  route the instant the session is revoked. This package verifies it and destroys the matching
  local Laravel session server-side (via `app('session')->driver()->getHandler()->destroy()`,
  generic across whatever `SESSION_DRIVER` you use). From the user's point of view:
  functionally identical to case 1 — if they reload or navigate, they're asked to log in again
  right away, even though they never touched this app's own logout button.
- **Without it:** your app has no way to find out proactively. It'll keep showing the user as
  signed in — its own local session cookie hasn't changed — right up until the moment it makes
  its next real call to `/userinfo`, which returns a real `401`/`TokenExpiredException`. In the
  worst realistic case, that's **up to 15 minutes** of stale "signed in" UI, bounded by the
  access token's own lifetime. This is not a security hole — no protected data actually leaks,
  since the real API call starts failing the moment it's tried — but the *displayed* state can
  look stale for that window.

**To wire up back-channel logout:**

1. Register the exact URL with OneHux:
   ```
   PATCH /api/v1/applications/{id}/backchannel-logout/
   { "backchannel_logout_uri": "https://yourapp.example.com/auth/backchannel-logout" }
   ```
   The response includes `backchannel_logout_secret` **exactly once** — this is a dedicated
   signing secret, deliberately **not** your `ONEHUX_CLIENT_SECRET` (the backend stores that
   only as a one-way hash and can never read it back to sign anything with it).
2. Add it to your `.env`:
   ```env
   ONEHUX_BACKCHANNEL_LOGOUT_SIGNING_SECRET=bcls_...
   ```

That's the whole setup — `{prefix}/backchannel-logout` is already mounted (see Setup above), and
starts verifying/acting on real `logout_token` deliveries as soon as the secret is configured.

Spec: [openid-connect-backchannel-1_0](https://openid.net/specs/openid-connect-backchannel-1_0.html).

## No refresh token today — this is real, not a bug

OneHux Accounts access tokens are a 15-minute, single-issue lifetime. This platform does not
currently issue a refresh token. `$client->getUserinfo()` throws `TokenExpiredException` when
the token has expired or been revoked — catch it and send the user back through
`$client->startAuthorization()` for a fresh login. There is no silent-refresh path to fall back
to; this package makes that explicit rather than hiding it behind a generic error.

## Example project

See `example/` for a complete, runnable Laravel application using this package end-to-end —
registered against a real disposable test `Application` and actually run through the full
browser flow against production, not just unit-tested in isolation.

## License

MIT (see `LICENSE`) — a default choice, not yet a final decision; change before any public
release if OneHux wants different terms.

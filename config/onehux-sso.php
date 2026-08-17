<?php
// config/onehux-sso.php
// Published via `php artisan vendor:publish --tag=onehux-sso-config`. Every value below can be
// overridden from your own .env -- CLIENT_ID/CLIENT_SECRET/REDIRECT_URI/POST_LOGOUT_REDIRECT_URI
// are real per-Application secrets and have no sensible default; LOGIN_BASE_URL/API_BASE_URL/
// SCOPE default to this platform's own production hosts and OpenID's standard scope.

return [
    'client_id' => env('ONEHUX_CLIENT_ID'),
    'client_secret' => env('ONEHUX_CLIENT_SECRET'),
    'redirect_uri' => env('ONEHUX_REDIRECT_URI'),
    'post_logout_redirect_uri' => env('ONEHUX_POST_LOGOUT_REDIRECT_URI'),

    'login_base_url' => env('ONEHUX_LOGIN_BASE_URL', 'https://accounts.onehux.com'),
    'api_base_url' => env('ONEHUX_API_BASE_URL', 'https://api-accounts.onehux.com'),
    'scope' => env('ONEHUX_SCOPE', 'openid profile email'),

    // Where the routes below are mounted, and where a successful login/logout redirects.
    'route_prefix' => env('ONEHUX_ROUTE_PREFIX', 'auth'),
    'login_success_redirect' => env('ONEHUX_LOGIN_SUCCESS_REDIRECT', '/'),
    'session_access_token_key' => 'onehux_access_token',

    // OIDC Back-Channel Logout (optional). The dedicated signing secret returned exactly once
    // by PATCH /api/v1/applications/{id}/backchannel-logout/ when you register your
    // backchannel_logout_uri -- deliberately NOT the same as client_secret above (the backend
    // stores that only as a one-way hash and can never read it back to sign anything with it).
    // Leave unset if you don't use back-channel logout; the route will respond 400 to every
    // request rather than silently accepting an unverifiable one.
    'backchannel_logout_signing_secret' => env('ONEHUX_BACKCHANNEL_LOGOUT_SIGNING_SECRET'),
];

<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>onehux/sso example</title>
    <style>
        body { font-family: system-ui, sans-serif; max-width: 640px; margin: 3rem auto; padding: 0 1rem; color: #111; }
        a.btn { display: inline-block; padding: .5rem 1rem; border: 1px solid #333; border-radius: 4px; text-decoration: none; color: #111; margin-right: .5rem; }
        pre { background: #f4f4f4; padding: 1rem; overflow-x: auto; white-space: pre-wrap; }
    </style>
</head>
<body>
@if ($signedIn)
    <h1>onehux/sso example — signed in</h1>
    <p>Real claims from GET /api/v1/oauth/userinfo/:</p>
    <pre>{{ json_encode($claims, JSON_PRETTY_PRINT) }}</pre>
    <a class="btn" href="/auth/logout">Log out (RP-initiated SLO)</a>
    <p style="margin-top:2rem;color:#666">To confirm true single-logout, separately open
        <a href="https://accounts.onehux.com/dashboard" target="_blank">accounts.onehux.com/dashboard</a>
        after logging out — it should demand login again.</p>
@else
    @if (isset($expiredMessage))
        <h1>Token expired</h1>
        <pre>{{ $expiredMessage }}</pre>
    @else
        <h1>onehux/sso example — signed out</h1>
        <p>Real end-to-end demo of the onehux/sso Composer package against production.</p>
    @endif
    <a class="btn" href="/auth/login">Sign in</a>
@endif
</body>
</html>

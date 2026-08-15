<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Session;
use Onehux\Sso\Exceptions\TokenExpiredException;
use Onehux\Sso\OneHuxClient;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Real end-to-end demo of the onehux/sso package against production. The
| package's own OneHuxSSOServiceProvider registers /auth/login, /auth/callback,
| /auth/logout, /auth/userinfo -- this file only adds the demo home page and
| the post-logout landing page.
|
*/

Route::get('/', function (OneHuxClient $client) {
    $accessToken = Session::get(config('onehux-sso.session_access_token_key'));
    if (! $accessToken) {
        return view('home', ['signedIn' => false]);
    }

    try {
        $claims = $client->getUserinfo($accessToken);
    } catch (TokenExpiredException $exception) {
        return view('home', ['signedIn' => false, 'expiredMessage' => $exception->getMessage()]);
    }

    return view('home', ['signedIn' => true, 'claims' => $claims]);
});

Route::get('/auth/logged-out', function () {
    return view('logged-out');
});

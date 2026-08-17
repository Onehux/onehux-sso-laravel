<?php
// src/OneHuxSSOServiceProvider.php
// Registers OneHuxClient as a singleton built from config/onehux-sso.php, publishes that
// config, and registers the four real routes ({route_prefix}/login, /callback, /logout,
// /userinfo) -- discovered automatically by Laravel's package auto-discovery
// (composer.json's extra.laravel.providers), no manual registration required.

namespace Onehux\Sso;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Onehux\Sso\Http\Controllers\OneHuxSSOController;

class OneHuxSSOServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/onehux-sso.php', 'onehux-sso');

        $this->app->singleton(OneHuxClient::class, function () {
            return new OneHuxClient(
                clientId: (string) config('onehux-sso.client_id'),
                clientSecret: (string) config('onehux-sso.client_secret'),
                redirectUri: (string) config('onehux-sso.redirect_uri'),
                postLogoutRedirectUri: (string) config('onehux-sso.post_logout_redirect_uri'),
                loginBaseUrl: (string) config('onehux-sso.login_base_url'),
                apiBaseUrl: (string) config('onehux-sso.api_base_url'),
                scope: (string) config('onehux-sso.scope'),
            );
        });
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../config/onehux-sso.php' => config_path('onehux-sso.php'),
        ], 'onehux-sso-config');

        // 'web' middleware explicitly required here: unlike routes/web.php (wrapped in 'web'
        // automatically by RouteServiceProvider), routes registered directly in a package
        // service provider's boot() get NO middleware group by default -- without this, the
        // session middleware never runs, so Session::put()/pull() below silently never persist
        // and every callback fails InvalidStateException. Found by a real end-to-end run, not
        // assumed (README.md ADR-073, backend repo).
        $prefix = config('onehux-sso.route_prefix', 'auth');
        Route::middleware('web')->prefix($prefix)->group(function () {
            Route::get('login', [OneHuxSSOController::class, 'login'])->name('onehux-sso.login');
            Route::get('callback', [OneHuxSSOController::class, 'callback'])->name('onehux-sso.callback');
            Route::get('logout', [OneHuxSSOController::class, 'logout'])->name('onehux-sso.logout');
            Route::get('userinfo', [OneHuxSSOController::class, 'userinfo'])->name('onehux-sso.userinfo');
        });

        // OIDC Back-Channel Logout: deliberately OUTSIDE the 'web' middleware group above --
        // see OneHuxSSOController::backchannelLogout()'s own docstring for why (a server-to-
        // server POST with no CSRF token to verify). No middleware at all is applied here; the
        // logout_token's own HS256 signature is the real authenticity check.
        Route::post("{$prefix}/backchannel-logout", [OneHuxSSOController::class, 'backchannelLogout'])
            ->name('onehux-sso.backchannel-logout');
    }
}

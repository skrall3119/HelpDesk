<?php

namespace App\Providers;

use App\Auth\BearerTokens;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Auth::viaRequest('tenant-bearer', fn (Request $request) => app(BearerTokens::class)->authenticate($request));
        RateLimiter::for('login', function (Request $request): array {
            $email = $request->input('email');
            $account = hash('sha256', $request->getHost().'|'.(is_string($email) ? strtolower(trim($email)) : ''));

            return [
                Limit::perMinute(30)->by('login-ip:'.$request->ip()),
                Limit::perMinute(5)->by('login-account:'.$account),
            ];
        });
    }
}

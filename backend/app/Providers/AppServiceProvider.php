<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
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
        RateLimiter::for('crm-login', function (Request $request): array {
            $email = strtolower(trim((string) $request->input('email', '')));

            return [
                Limit::perMinute(6)->by('crm-login:acct:'.$request->ip().'|'.$email),
                Limit::perMinute(20)->by('crm-login:ip:'.$request->ip()),
            ];
        });

        RateLimiter::for('member-login', function (Request $request): array {
            $ident = ! empty($request->input('email'))
                ? strtolower(trim((string) $request->input('email', '')))
                : (string) preg_replace('/\D+/', '', (string) $request->input('phone', ''));

            return [
                Limit::perMinute(6)->by('member-login:acct:'.$request->ip().'|'.$ident),
                Limit::perMinute(20)->by('member-login:ip:'.$request->ip()),
            ];
        });

        RateLimiter::for('member-register', function (Request $request): Limit {
            return Limit::perMinute(3)->by('member-register:'.$request->ip());
        });
    }
}

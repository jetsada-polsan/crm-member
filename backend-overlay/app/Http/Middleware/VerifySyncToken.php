<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class VerifySyncToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = (string) config('popstar.sync_api_token', '');
        $provided = (string) $request->bearerToken();
        if (strlen($token) < 32 || $provided === '' || ! hash_equals($token, $provided)) {
            return response()->json(['error' => 'Unauthorized sync client'], 401);
        }

        return $next($request);
    }
}

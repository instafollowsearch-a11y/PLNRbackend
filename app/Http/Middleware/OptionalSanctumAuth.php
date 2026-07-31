<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class OptionalSanctumAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->bearerToken() !== null && $request->user() === null) {
            $user = $request->user('sanctum');

            if ($user !== null) {
                auth()->setUser($user);
            }
        }

        return $next($request);
    }
}

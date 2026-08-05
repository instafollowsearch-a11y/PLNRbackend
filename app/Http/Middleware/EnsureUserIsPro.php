<?php

namespace App\Http\Middleware;

use App\Services\Pro\ProAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsPro
{
    public function __construct(
        private readonly ProAccess $proAccess,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            abort(401, 'Unauthenticated.');
        }

        $this->proAccess->assertActive($user);

        return $next($request);
    }
}

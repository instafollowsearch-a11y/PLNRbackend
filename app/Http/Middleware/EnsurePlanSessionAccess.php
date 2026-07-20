<?php

namespace App\Http\Middleware;

use App\Models\PlanSession;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePlanSessionAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var PlanSession|null $planSession */
        $planSession = $request->route('planSession');

        if (! $planSession instanceof PlanSession) {
            return $next($request);
        }

        $user = $request->user();

        if ($planSession->user_id !== null && ($user === null || $planSession->user_id !== $user->id)) {
            abort(403, 'You do not have access to this plan session.');
        }

        return $next($request);
    }
}

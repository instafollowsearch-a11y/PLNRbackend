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

        if ($planSession->user_id === null) {
            return $next($request);
        }

        if ($user !== null && $planSession->user_id === $user->id) {
            return $next($request);
        }

        if ($user !== null && $planSession->isMember($user)) {
            return $next($request);
        }

        abort(403, 'You do not have access to this plan session.');
    }
}

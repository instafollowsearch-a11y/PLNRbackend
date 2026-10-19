<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\UpdateUserRoleRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\Notifications\ExpoPushService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class UserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $perPage = min(100, max(1, $request->integer('per_page', 20)));
        $search = trim((string) $request->query('search', ''));

        $query = User::query()->withCount('devicePushTokens')->orderByDesc('created_at');

        if ($search !== '') {
            $query->where(function ($builder) use ($search): void {
                $builder->where('email', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%");
            });
        }

        $paginator = $query->paginate($perPage);

        return response()->json([
            'data' => [
                'users' => UserResource::collection($paginator->items())->resolve(),
                'meta' => [
                    'current_page' => $paginator->currentPage(),
                    'last_page' => $paginator->lastPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                ],
            ],
            'message' => 'Users retrieved.',
        ]);
    }

    public function update(UpdateUserRoleRequest $request, User $user): JsonResponse
    {
        $role = $request->string('role')->toString();

        if ($user->isAdmin() && $role === User::ROLE_USER) {
            $adminCount = User::query()->where('role', User::ROLE_ADMIN)->count();

            if ($adminCount <= 1) {
                throw ValidationException::withMessages([
                    'role' => ['Cannot demote the last admin.'],
                ]);
            }
        }

        $user->forceFill(['role' => $role])->save();

        return response()->json([
            'data' => [
                'user' => new UserResource($user->fresh()->loadCount('devicePushTokens')),
            ],
            'message' => 'User updated.',
        ]);
    }

    public function testNotification(User $user, ExpoPushService $push): JsonResponse
    {
        $tokenCount = $user->devicePushTokens()->count();

        if ($tokenCount === 0) {
            return response()->json([
                'data' => [
                    'sent' => false,
                    'token_count' => 0,
                    'reason' => 'no_tokens',
                ],
                'message' => 'This account has no push token — open the latest app build, sign in, allow notifications.',
            ], 422);
        }

        $result = $push->deliverToUser(
            $user,
            'PLNR test',
            'If you can read this, push is working.',
            ['screen' => 'test'],
        );

        if (! $result->sent) {
            return response()->json([
                'data' => [
                    'sent' => false,
                    'token_count' => $result->tokenCount,
                    'reason' => 'expo_failed',
                    'ticket_errors' => $result->ticketErrors,
                ],
                'message' => 'Expo did not accept the test notification.',
            ], 502);
        }

        return response()->json([
            'data' => [
                'sent' => true,
                'token_count' => $result->tokenCount,
            ],
            'message' => 'Test notification sent.',
        ]);
    }
}

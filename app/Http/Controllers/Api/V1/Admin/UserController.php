<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\UpdateUserRoleRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class UserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $perPage = min(100, max(1, $request->integer('per_page', 20)));
        $search = trim((string) $request->query('search', ''));

        $query = User::query()->orderByDesc('created_at');

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
                'user' => new UserResource($user->fresh()),
            ],
            'message' => 'User updated.',
        ]);
    }
}

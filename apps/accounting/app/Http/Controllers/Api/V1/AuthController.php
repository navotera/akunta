<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use Akunta\Rbac\Models\Entity;
use Akunta\Rbac\Models\User;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'remember' => ['sometimes', 'boolean'],
        ]);

        if (! Auth::attempt(
            ['email' => $data['email'], 'password' => $data['password']],
            (bool) ($data['remember'] ?? false)
        )) {
            throw ValidationException::withMessages([
                'email' => __('auth.failed'),
            ]);
        }

        $request->session()->regenerate();

        /** @var User $user */
        $user = Auth::user();
        $user->forceFill(['last_login_at' => now()])->save();

        return response()->json([
            'data' => $this->userPayload($user),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['data' => ['message' => 'logged_out']]);
    }

    public function me(Request $request): JsonResponse
    {
        /** @var User|null $user */
        $user = $request->user();
        if (! $user) {
            return response()->json([
                'errors' => [['code' => 'unauthenticated', 'message' => 'Not authenticated.']],
            ], 401);
        }

        return response()->json([
            'data' => $this->userPayload($user),
        ]);
    }

    private function userPayload(User $user): array
    {
        $tenants = Entity::query()
            ->whereIn('id', $user->assignments()
                ->whereNull('revoked_at')
                ->pluck('entity_id')
                ->filter()
                ->unique()
                ->values())
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Entity $e) => [
                'id' => $e->id,
                'name' => $e->name,
                'slug' => null,
            ])
            ->all();

        return [
            'id' => $user->id,
            'email' => $user->email,
            'name' => $user->name,
            'tenants' => $tenants,
            'is_sso_admin' => session('ecopa.app_role') === 'admin',
        ];
    }
}

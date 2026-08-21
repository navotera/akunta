<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use Akunta\Rbac\Models\Entity;
use Akunta\Rbac\Models\User;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
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

    public function localLogin(Request $request): JsonResponse
    {
        abort_unless(app()->environment('local') && ! config('ecopa.client_id'), 404);

        $email = (string) env('SUPER_ADMIN_EMAIL', 'superadmin@akunta.local');
        /** @var User|null $user */
        $user = User::query()->where('email', $email)->first();

        if (! $user) {
            throw ValidationException::withMessages([
                'email' => 'Local super admin belum tersedia. Jalankan seeder lokal terlebih dahulu.',
            ]);
        }

        Auth::guard('web')->login($user, remember: true);
        $request->session()->regenerate();
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
            ->get(['id', 'tenant_id', 'name', 'is_active', 'is_fake_data', 'workspace_settings', 'theme_color', 'logo_path'])
            ->map(fn (Entity $e) => [
                'id' => $e->id,
                'tenant_id' => $e->tenant_id,
                'name' => $e->name,
                'slug' => null,
                'theme_color' => $e->theme_color,
                'logo_url' => $e->logo_path ? Storage::disk('public')->url($e->logo_path) : null,
                'is_active' => (bool) $e->is_active,
                'is_fake_data' => (bool) $e->is_fake_data,
                'bookkeeping_mode' => data_get($e->workspace_settings, 'bookkeeping_mode', 'independent_books'),
                'issue_report_url' => data_get($e->workspace_settings, 'issue_report_url'),
            ])
            ->all();

        return [
            'id' => $user->id,
            'email' => $user->email,
            'name' => $user->name,
            'roles' => $user->assignments()->whereNull('revoked_at')->with('role')->get()->pluck('role.code')->filter()->unique()->values()->all(),
            'tenants' => $tenants,
            'is_sso_admin' => session('ecopa.app_role') === 'admin',
            'is_admin' => session('ecopa.app_role') === 'admin' || $user->hasPermission('workspace.manage'),
            'is_impersonating' => session()->has('impersonator_id'),
            'impersonator_id' => session('impersonator_id'),
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Services;

use Akunta\Rbac\Models\User;
use App\Models\ApiToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class UserAccessRevoker
{
    public function disable(User $user): void
    {
        DB::transaction(function () use ($user): void {
            $user->forceFill([
                'disabled_at' => $user->disabled_at ?? now(),
                'remember_token' => null,
            ])->save();

            $user->assignments()
                ->whereNull('revoked_at')
                ->update(['revoked_at' => now()]);

            $this->revokeSessionsAndTokens($user);
        });
    }

    public function enable(User $user): void
    {
        $user->forceFill([
            'disabled_at' => null,
            'remember_token' => null,
        ])->save();
    }

    public function revokeSessionsAndTokens(User $user): void
    {
        ApiToken::query()
            ->where('user_id', $user->id)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);

        if (Schema::hasTable('personal_access_tokens')) {
            DB::table('personal_access_tokens')
                ->where('tokenable_type', $user->getMorphClass())
                ->where('tokenable_id', $user->id)
                ->delete();
        }

        if (Schema::hasTable('sessions')) {
            DB::table('sessions')->where('user_id', $user->id)->delete();
        }
    }
}

<?php

namespace App\Providers;

use Akunta\Rbac\Models\User;
use App\Models\PayrollRun;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->registerPayrollGates();
    }

    protected function registerPayrollGates(): void
    {
        Gate::define('payroll.approve', function (?User $user, PayrollRun $run): bool {
            return $user?->hasPermission('payroll.approve', $run->entity_id) ?? false;
        });

        Gate::define('payroll.pay', function (?User $user, PayrollRun $run): bool {
            return $user?->hasPermission('payroll.pay', $run->entity_id) ?? false;
        });
    }
}

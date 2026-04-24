<?php

namespace App\Providers;

use Akunta\Rbac\Models\User;
use App\Models\Expense;
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
        $this->registerExpenseGates();
    }

    protected function registerExpenseGates(): void
    {
        Gate::define('expense.approve', function (?User $user, Expense $expense): bool {
            return $user?->hasPermission('expense.approve', $expense->entity_id) ?? false;
        });

        Gate::define('expense.pay', function (?User $user, Expense $expense): bool {
            return $user?->hasPermission('expense.pay', $expense->entity_id) ?? false;
        });
    }
}

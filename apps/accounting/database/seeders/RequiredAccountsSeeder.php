<?php

declare(strict_types=1);

namespace Database\Seeders;

use Akunta\Rbac\Models\Entity;
use App\Services\RequiredAccountService;
use Illuminate\Database\Seeder;

final class RequiredAccountsSeeder extends Seeder
{
    public function run(RequiredAccountService $requiredAccounts): void
    {
        Entity::query()->orderBy('id')->each(function (Entity $entity) use ($requiredAccounts): void {
            $requiredAccounts->ensure($entity);
        });
    }
}

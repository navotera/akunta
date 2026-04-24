<?php

declare(strict_types=1);

namespace App\Tenancy\Exceptions;

use RuntimeException;

class TenantDatabaseAlreadyExists extends RuntimeException
{
    public static function for(string $dbName): self
    {
        return new self("Tenant database [{$dbName}] already exists.");
    }
}

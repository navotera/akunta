<?php

declare(strict_types=1);

namespace App\Tenancy\Exceptions;

use InvalidArgumentException;

class InvalidTenantIdentifier extends InvalidArgumentException
{
    public static function for(string $identifier): self
    {
        return new self("Tenant DB identifier [{$identifier}] is invalid — must match /^[a-zA-Z][a-zA-Z0-9_]{0,62}$/.");
    }
}

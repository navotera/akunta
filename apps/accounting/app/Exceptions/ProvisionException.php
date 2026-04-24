<?php

namespace App\Exceptions;

use RuntimeException;

class ProvisionException extends RuntimeException
{
    public static function duplicateSlug(string $slug): self
    {
        return new self("Tenant slug [{$slug}] already exists.");
    }

    public static function seedFailed(string $reason): self
    {
        return new self("Tenant seed failed: {$reason}");
    }
}

<?php

declare(strict_types=1);

namespace App\Tenancy;

use App\Tenancy\Contracts\TenantProvisioner;
use App\Tenancy\Exceptions\InvalidTenantIdentifier;
use App\Tenancy\Exceptions\TenantDatabaseAlreadyExists;
use RuntimeException;

/**
 * Tenant DB-per-file on the local filesystem. Maps:
 *   create($dbName) → touch("{storagePath}/{dbName}.sqlite")
 *   drop($dbName)   → unlink(...)
 *   exists         → file_exists(...)
 *
 * Intended for local dev + test environments where running PG adds friction.
 * Production uses PostgresTenantProvisioner.
 */
class SqliteTenantProvisioner implements TenantProvisioner
{
    public function __construct(private readonly string $storagePath) {}

    public function create(string $dbName): void
    {
        $this->guardIdentifier($dbName);

        if (! is_dir($this->storagePath)) {
            if (! mkdir($this->storagePath, 0755, true) && ! is_dir($this->storagePath)) {
                throw new RuntimeException("Cannot create tenant storage directory [{$this->storagePath}].");
            }
        }

        $path = $this->pathFor($dbName);

        if (file_exists($path)) {
            throw TenantDatabaseAlreadyExists::for($dbName);
        }

        if (touch($path) === false) {
            throw new RuntimeException("Cannot create tenant DB file [{$path}].");
        }
    }

    public function drop(string $dbName): void
    {
        $this->guardIdentifier($dbName);

        $path = $this->pathFor($dbName);

        if (! file_exists($path)) {
            return;
        }

        if (unlink($path) === false) {
            throw new RuntimeException("Cannot delete tenant DB file [{$path}].");
        }
    }

    public function exists(string $dbName): bool
    {
        $this->guardIdentifier($dbName);

        return file_exists($this->pathFor($dbName));
    }

    public function connectionConfig(string $dbName): array
    {
        $this->guardIdentifier($dbName);

        return [
            'driver' => 'sqlite',
            'database' => $this->pathFor($dbName),
            'prefix' => '',
            'foreign_key_constraints' => true,
            'busy_timeout' => null,
            'journal_mode' => null,
            'synchronous' => null,
        ];
    }

    public function pathFor(string $dbName): string
    {
        return rtrim($this->storagePath, '/').'/'.$dbName.'.sqlite';
    }

    private function guardIdentifier(string $dbName): void
    {
        if (! preg_match(TenantProvisioner::IDENTIFIER_PATTERN, $dbName)) {
            throw InvalidTenantIdentifier::for($dbName);
        }
    }
}

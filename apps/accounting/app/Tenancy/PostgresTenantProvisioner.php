<?php

declare(strict_types=1);

namespace App\Tenancy;

use App\Tenancy\Contracts\TenantProvisioner;
use App\Tenancy\Exceptions\InvalidTenantIdentifier;
use App\Tenancy\Exceptions\TenantDatabaseAlreadyExists;
use Illuminate\Database\DatabaseManager;

/**
 * Issues CREATE/DROP DATABASE against the control connection.
 *
 * Requires the control DB role to hold `CREATEDB` privilege. Identifier is
 * whitelisted via `IDENTIFIER_PATTERN` before being embedded (PG does not allow
 * parameterized DB names in DDL — the whitelist IS the defense).
 */
class PostgresTenantProvisioner implements TenantProvisioner
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly string $controlConnection,
        private readonly string $tenantConnection,
    ) {}

    public function create(string $dbName): void
    {
        $this->guardIdentifier($dbName);

        if ($this->exists($dbName)) {
            throw TenantDatabaseAlreadyExists::for($dbName);
        }

        $this->db->connection($this->controlConnection)->statement(
            'CREATE DATABASE "'.$dbName.'"'
        );
    }

    public function drop(string $dbName): void
    {
        $this->guardIdentifier($dbName);

        if (! $this->exists($dbName)) {
            return;
        }

        $this->db->connection($this->controlConnection)->statement(
            'DROP DATABASE "'.$dbName.'"'
        );
    }

    public function exists(string $dbName): bool
    {
        $this->guardIdentifier($dbName);

        $row = $this->db->connection($this->controlConnection)
            ->selectOne('SELECT 1 AS found FROM pg_database WHERE datname = ?', [$dbName]);

        return $row !== null;
    }

    public function connectionConfig(string $dbName): array
    {
        $this->guardIdentifier($dbName);

        $template = config("database.connections.{$this->tenantConnection}");

        $template['database'] = $dbName;

        return $template;
    }

    private function guardIdentifier(string $dbName): void
    {
        if (! preg_match(TenantProvisioner::IDENTIFIER_PATTERN, $dbName)) {
            throw InvalidTenantIdentifier::for($dbName);
        }
    }
}

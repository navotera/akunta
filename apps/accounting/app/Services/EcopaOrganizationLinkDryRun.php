<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

/**
 * Internal signal that rolls back a dry-run link transaction while carrying
 * the result a real run would have produced.
 */
final class EcopaOrganizationLinkDryRun extends RuntimeException
{
    /**
     * @param  array{status: string, tenant_id: string|null, assignments_backfilled: int}  $result
     */
    public function __construct(public readonly array $result)
    {
        parent::__construct('Dry run — rolled back.');
    }
}

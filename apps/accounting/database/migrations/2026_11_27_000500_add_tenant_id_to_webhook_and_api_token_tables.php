<?php

declare(strict_types=1);

use App\Support\Tenancy\TenantColumnMigrator as Tenancy;
use Illuminate\Database\Migrations\Migration;

/**
 * Ecopa multi-organisation, stage 3 (runbook "Tahap 3"): webhook subscriptions,
 * their deliveries, and integration API tokens.
 *
 * A subscription follows its entity; a global one (no entity) follows its
 * creator's tenant when the creator's assignments agree on one, otherwise the
 * only tenant of a single-tenant installation. API tokens have no entity: their
 * user's tenant by the same rule. Anything still unresolved stops the migration
 * with the ids listed; no tenant is guessed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Tenancy::addColumn('webhook_subscriptions', 'entity_id');
        Tenancy::backfillFromParent('webhook_subscriptions', 'entities', 'entity_id');
        Tenancy::backfillFromUser('webhook_subscriptions', 'created_by');
        Tenancy::assertNoOrphans('webhook_subscriptions');
        Tenancy::enforceNotNull('webhook_subscriptions');
        Tenancy::compositeForeignKey('webhook_subscriptions', 'entity_id', 'entities');
        Tenancy::uniqueWithId('webhook_subscriptions');

        Tenancy::migrateParentDerived('webhook_deliveries', 'webhook_subscriptions', 'subscription_id');

        Tenancy::addColumn('api_tokens', null);
        Tenancy::backfillFromUser('api_tokens', 'user_id');
        Tenancy::assertNoOrphans('api_tokens');
        Tenancy::enforceNotNull('api_tokens');
    }

    public function down(): void
    {
        Tenancy::dropColumn('api_tokens', null);
        Tenancy::dropColumn('webhook_deliveries', 'subscription_id', ['subscription_id']);
        Tenancy::dropUniqueWithId('webhook_subscriptions');
        Tenancy::dropColumn('webhook_subscriptions', 'entity_id', ['entity_id']);
    }
};

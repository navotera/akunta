<?php

declare(strict_types=1);

use App\Models\User;

/**
 * Prompt stage 8 - the security gate, part 1: route sweeps.
 *
 * Two complete organisations, A and B, each with an Organization Owner, a Member, and a fiscal
 * Inspector, and data in every tenant-owned table. Organisation B's people must be unable to read or
 * change anything of organisation A through any SPA route. "Reading" is detected by A's marker
 * strings, which live only in A's data; "changing" by fingerprinting every tenant-owned row of A
 * before and after. See TwoOrganizationChannelsTest for tokens, webhooks, jobs, and the rest.
 */
require_once __DIR__.'/Concerns_two_organizations.php';

beforeEach(twoOrganizationFixture());

it('refuses organisation B on every SPA route that names organisation A, and leaks nothing, for owner, member, and inspector', function () {
    $before = ($this->fingerprint)($this->A->tenant->id);

    $violations = [];
    foreach ([
        'owner' => [$this->B->owner, 'admin'],
        'member' => [$this->B->member, 'user'],
        'inspector' => [$this->B->inspectorUser, 'user'],
    ] as $label => [$user, $role]) {
        // Own workspace in the header, A's ids and A's workspace in paths, bodies, and filters.
        ($this->actAs)($user, 'org-b', $role, $this->B->entity->id);
        array_push($violations, ...sweepAs($this, $this->A, "{$label}/own header", $this->B->entity->id, true, true));
    }

    expect($violations)->toBe([]);
    expect(($this->fingerprint)($this->A->tenant->id))->toBe($before);
});

it('refuses organisation B when it puts organisation A\'s workspace in the header', function () {
    $before = ($this->fingerprint)($this->A->tenant->id);

    $violations = [];
    foreach (['owner' => [$this->B->owner, 'admin'], 'member' => [$this->B->member, 'user'], 'inspector' => [$this->B->inspectorUser, 'user']] as $label => [$user, $role]) {
        ($this->actAs)($user, 'org-b', $role, $this->A->entity->id);
        // Nothing that depends on the workspace may succeed at all.
        $violations = [...$violations, ...array_values(array_filter(
            sweepAs($this, $this->A, "{$label}/foreign header", $this->A->entity->id, false, false),
            // Routes bound to the signed-in identity, not to a workspace: the person's own setup state, their own tenant's
            // installation status, and the list of Ecopa apps they may open. None of them returns tenant data.
            fn (string $v) => ! preg_match('#organization-onboarding/status|installation-onboarding/status|widgets/ecosystem#', $v),
        ))];
    }

    expect($violations)->toBe([]);
    expect(($this->fingerprint)($this->A->tenant->id))->toBe($before);
});

it('does not let the Platform Owner of one organisation read or change the business data of another', function () {
    // Decision 2026-11-19: the PO runs the installation and the default organisation; it is not a window into the others.
    $po = User::query()->create(['name' => 'PO', 'email' => 'po-'.uniqid().'@example.test', 'main_tier_user_id' => 'ecopa-po-'.uniqid(), 'email_verified_at' => now()]);
    config()->set('tenancy.multi_organization', true);
    $before = ($this->fingerprint)($this->B->tenant->id);

    app('auth')->forgetGuards();
    $this->flushSession();
    $this->defaultHeaders = [];
    $this->actingAs($po)->withSession(platformOwnerSession($po, ['ecopa.organization' => ['id' => 'org-a', 'slug' => 'a', 'name' => 'A']]))
        ->withHeader('X-Tenant-Slug', $this->B->entity->id);

    // With organisation B's workspace in the header nothing of B is reachable. Only routes bound to the
    // signed-in identity or to the installation (not to a workspace) may answer, and the marker check in
    // the sweep proves none of them carries tenant content: the person's own setup state, the Ecopa
    // integration status and webhook log, the scheduler status, and the usage overview (counts only).
    $identityBound = '#organization-onboarding/status|installation-onboarding/status|widgets/ecosystem|auth/branding'
        .'|spa/ecopa-integration|spa/scheduler/status|spa/platform/usage#';
    expect(array_values(array_filter(
        sweepAs($this, $this->B, 'PO of A -> B (foreign header)', $this->B->entity->id, false, false),
        fn (string $v) => preg_match($identityBound, $v) !== 1,
    )))->toBe([]);
    $this->withHeader('X-Tenant-Slug', $this->A->entity->id);
    expect(sweepAs($this, $this->B, 'PO of A -> B (own header)', $this->A->entity->id, true, true))->toBe([]);

    // The usage overview is the PO's window: counts and times per organisation, never business content.
    $usage = $this->getJson('/api/v1/spa/platform/usage')->assertOk();
    expect($usage->getContent())->not->toContain($this->B->mark)->not->toContain($this->A->mark);
    expect(($this->fingerprint)($this->B->tenant->id))->toBe($before);
});

<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Encounter\Presentation;

use App\Domains\Encounter\Contracts\Event\EncounterStarted;
use App\Domains\Encounter\Domain\Enum\EncounterStatus;
use App\Domains\Encounter\Domain\Model\Encounter;
use App\Shared\Tenancy\Tenancy;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * @param  list<string>  $permissions
 * @return array{tenant: mixed, facility: mixed, user: mixed}
 */
function startHttpFixture(array $permissions = ['encounter.encounters.update']): array
{
    $fx = makeTenantWithUser($permissions, []);

    return ['tenant' => $fx['tenant'], 'facility' => $fx['facility'], 'user' => $fx['user']];
}

function seedArrivedHttpEncounter(string $tenantId, string $facilityId): string
{
    return TenantContextScope::run($tenantId, null, fn () => (string) Encounter::factory()
        ->forTenant($tenantId)->atFacility($facilityId)
        ->forPatient((string) Str::uuid7())
        ->arrived()
        ->create()->id);
}

function startUrl(string $encounterId): string
{
    return "/workspaces/reception/encounters/{$encounterId}/start";
}

it('starts an ARRIVED encounter via HTTP, resulting in ACTIVE with server-populated started_at', function (): void {
    $fx = startHttpFixture();
    $tenantId = (string) $fx['tenant']->id;
    $facilityId = (string) $fx['facility']->id;
    $encounterId = seedArrivedHttpEncounter($tenantId, $facilityId);

    $response = $this->actingAs($fx['user'])
        ->withSession(['active_facility_id' => $facilityId])
        ->from('/workspaces/reception')
        ->post(startUrl($encounterId));

    $response->assertRedirect('/workspaces/reception');
    $response->assertSessionHas('status');

    $row = Tenancy::run($tenantId, fn () => DB::table('enc_encounters')->where('id', $encounterId)->first());
    expect($row->status)->toBe('ACTIVE')
        ->and($row->started_at)->not->toBeNull();
});

it('returns JSON ACTIVE payload when starting via JSON', function (): void {
    $fx = startHttpFixture();
    $tenantId = (string) $fx['tenant']->id;
    $facilityId = (string) $fx['facility']->id;
    $encounterId = seedArrivedHttpEncounter($tenantId, $facilityId);

    $this->actingAs($fx['user'])
        ->withSession(['active_facility_id' => $facilityId])
        ->postJson(startUrl($encounterId))
        ->assertOk()
        ->assertJsonPath('data.encounter_id', $encounterId)
        ->assertJsonPath('data.status', 'ACTIVE');
});

it('ignores client-supplied actor/timestamp/status fields when starting', function (): void {
    $fx = startHttpFixture();
    $tenantId = (string) $fx['tenant']->id;
    $facilityId = (string) $fx['facility']->id;
    $encounterId = seedArrivedHttpEncounter($tenantId, $facilityId);

    $this->actingAs($fx['user'])
        ->withSession(['active_facility_id' => $facilityId])
        ->from('/workspaces/reception')
        ->post(startUrl($encounterId), [
            'actor_id' => (string) Str::uuid7(),
            'started_at' => '2020-01-01T00:00:00Z',
            'status' => 'COMPLETED',
            'facility_id' => (string) Str::uuid7(),
            'patient_id' => (string) Str::uuid7(),
        ])
        ->assertRedirect('/workspaces/reception')
        ->assertSessionHas('status');

    $row = Tenancy::run($tenantId, fn () => DB::table('enc_encounters')->where('id', $encounterId)->first());
    expect($row->status)->toBe('ACTIVE')
        ->and($row->started_at)->not->toBe('2020-01-01T00:00:00Z')
        ->and($row->facility_id)->toBe($facilityId);

    $audit = Tenancy::run($tenantId, fn () => DB::table('shared_audit_log')->where('action', 'encounter.started')->first());
    expect($audit)->not->toBeNull()
        ->and($audit->actor_id)->toBe((string) $fx['user']->id);
});

it('writes the EncounterStarted outbox event and audit row on HTTP start', function (): void {
    $fx = startHttpFixture();
    $tenantId = (string) $fx['tenant']->id;
    $facilityId = (string) $fx['facility']->id;
    $encounterId = seedArrivedHttpEncounter($tenantId, $facilityId);

    $this->actingAs($fx['user'])
        ->withSession(['active_facility_id' => $facilityId])
        ->post(startUrl($encounterId))
        ->assertRedirect();

    $outbox = Tenancy::run($tenantId, fn () => DB::table('shared_outbox_events')
        ->where('event_class', EncounterStarted::class)->first());
    expect($outbox)->not->toBeNull()
        ->and($outbox->event_name)->toBe('encounter.EncounterStarted');

    $audit = Tenancy::run($tenantId, fn () => DB::table('shared_audit_log')->where('action', 'encounter.started')->first());
    expect($audit)->not->toBeNull()
        ->and($audit->actor_id)->toBe((string) $fx['user']->id)
        ->and($audit->target_id)->toBe($encounterId);
});

it('rejects HTTP start without the update permission (403)', function (): void {
    $fx = startHttpFixture([]);
    $tenantId = (string) $fx['tenant']->id;
    $facilityId = (string) $fx['facility']->id;
    $encounterId = seedArrivedHttpEncounter($tenantId, $facilityId);

    $this->actingAs($fx['user'])
        ->withSession(['active_facility_id' => $facilityId])
        ->postJson(startUrl($encounterId))
        ->assertForbidden();

    $this->actingAs($fx['user'])
        ->withSession(['active_facility_id' => $facilityId])
        ->post(startUrl($encounterId))
        ->assertForbidden();

    expect(Tenancy::run($tenantId, fn () => DB::table('enc_encounters')->where('id', $encounterId)->value('status')))->toBe('ARRIVED');
});

it('rejects HTTP start at the wrong facility (403 per policy)', function (): void {
    $fx = startHttpFixture(['encounter.encounters.update']);
    $tenantId = (string) $fx['tenant']->id;
    $facilityId = (string) $fx['facility']->id;

    $otherFacilityId = (string) Str::uuid7();
    $encounterId = seedArrivedHttpEncounter($tenantId, $otherFacilityId);

    $this->actingAs($fx['user'])
        ->withSession(['active_facility_id' => $facilityId])
        ->postJson(startUrl($encounterId))
        ->assertForbidden();

    expect(Tenancy::run($tenantId, fn () => DB::table('enc_encounters')->where('id', $encounterId)->value('status')))->toBe('ARRIVED');
});

it('returns 404 for a cross-tenant encounter id on HTTP start', function (): void {
    $fxA = startHttpFixture();
    $fxB = startHttpFixture();
    $encounterId = seedArrivedHttpEncounter((string) $fxA['tenant']->id, (string) $fxA['facility']->id);

    $this->actingAs($fxB['user'])
        ->withSession(['active_facility_id' => (string) $fxB['facility']->id])
        ->postJson(startUrl($encounterId))
        ->assertNotFound()
        ->assertJsonPath('error.code', 'not_found');
});

it('returns 409 illegal transition when starting a non-ARRIVED encounter via HTTP', function (): void {
    $fx = startHttpFixture();
    $tenantId = (string) $fx['tenant']->id;
    $facilityId = (string) $fx['facility']->id;

    $encounterId = TenantContextScope::run($tenantId, null, fn () => (string) Encounter::factory()
        ->forTenant($tenantId)->atFacility($facilityId)
        ->forPatient((string) Str::uuid7())
        ->withStatus(EncounterStatus::Active)
        ->create(['started_at' => now()->subHour()])->id);

    $this->actingAs($fx['user'])
        ->withSession(['active_facility_id' => $facilityId])
        ->postJson(startUrl($encounterId))
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'illegal_encounter_transition');

    $this->actingAs($fx['user'])
        ->withSession(['active_facility_id' => $facilityId])
        ->from('/workspaces/reception')
        ->post(startUrl($encounterId))
        ->assertRedirect('/workspaces/reception')
        ->assertSessionHasErrors('start');
});

it('returns 404 for an unknown encounter id on HTTP start', function (): void {
    $fx = startHttpFixture();
    $facilityId = (string) $fx['facility']->id;

    $this->actingAs($fx['user'])
        ->withSession(['active_facility_id' => $facilityId])
        ->postJson(startUrl((string) Str::uuid7()))
        ->assertNotFound()
        ->assertJsonPath('error.code', 'not_found');
});

it('redirects guests to login on HTTP start', function (): void {
    $this->post(startUrl((string) Str::uuid7()))->assertRedirect('/login');
});

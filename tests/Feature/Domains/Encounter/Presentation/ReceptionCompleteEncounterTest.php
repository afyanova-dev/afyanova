<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Encounter\Presentation;

use App\Domains\Encounter\Contracts\Event\EncounterCompleted;
use App\Domains\Encounter\Domain\Enum\EncounterDisposition;
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
function completeHttpFixture(array $permissions = ['encounter.encounters.complete']): array
{
    $fx = makeTenantWithUser($permissions, []);

    return ['tenant' => $fx['tenant'], 'facility' => $fx['facility'], 'user' => $fx['user']];
}

function seedActiveHttpEncounter(string $tenantId, string $facilityId, ?EncounterDisposition $disposition): string
{
    return TenantContextScope::run($tenantId, null, fn () => (string) Encounter::factory()
        ->forTenant($tenantId)->atFacility($facilityId)
        ->forPatient((string) Str::uuid7())
        ->active()
        ->create(['disposition' => $disposition])->id);
}

function completeUrl(string $encounterId): string
{
    return "/workspaces/reception/encounters/{$encounterId}/complete";
}

it('completes an ACTIVE encounter with disposition via HTTP, preserving disposition and populating completed_at', function (): void {
    $fx = completeHttpFixture();
    $tenantId = (string) $fx['tenant']->id;
    $facilityId = (string) $fx['facility']->id;
    $encounterId = seedActiveHttpEncounter($tenantId, $facilityId, EncounterDisposition::Discharged);

    $response = $this->actingAs($fx['user'])
        ->withSession(['active_facility_id' => $facilityId])
        ->from('/workspaces/reception')
        ->post(completeUrl($encounterId));

    $response->assertRedirect('/workspaces/reception');
    $response->assertSessionHas('status');

    $row = Tenancy::run($tenantId, fn () => DB::table('enc_encounters')->where('id', $encounterId)->first());
    expect($row->status)->toBe('COMPLETED')
        ->and($row->completed_at)->not->toBeNull()
        ->and($row->disposition)->toBe(EncounterDisposition::Discharged->value);
});

it('returns JSON COMPLETED payload when completing via JSON', function (): void {
    $fx = completeHttpFixture();
    $tenantId = (string) $fx['tenant']->id;
    $facilityId = (string) $fx['facility']->id;
    $encounterId = seedActiveHttpEncounter($tenantId, $facilityId, EncounterDisposition::Referred);

    $this->actingAs($fx['user'])
        ->withSession(['active_facility_id' => $facilityId])
        ->postJson(completeUrl($encounterId))
        ->assertOk()
        ->assertJsonPath('data.encounter_id', $encounterId)
        ->assertJsonPath('data.status', 'COMPLETED');

    expect(Tenancy::run($tenantId, fn () => DB::table('enc_encounters')->where('id', $encounterId)->value('disposition')))
        ->toBe(EncounterDisposition::Referred->value);
});

it('writes the EncounterCompleted outbox event and audit row on HTTP complete', function (): void {
    $fx = completeHttpFixture();
    $tenantId = (string) $fx['tenant']->id;
    $facilityId = (string) $fx['facility']->id;
    $encounterId = seedActiveHttpEncounter($tenantId, $facilityId, EncounterDisposition::Discharged);

    $this->actingAs($fx['user'])
        ->withSession(['active_facility_id' => $facilityId])
        ->post(completeUrl($encounterId))
        ->assertRedirect();

    $outbox = Tenancy::run($tenantId, fn () => DB::table('shared_outbox_events')
        ->where('event_class', EncounterCompleted::class)->first());
    expect($outbox)->not->toBeNull()
        ->and($outbox->event_name)->toBe('encounter.EncounterCompleted');
    $payload = json_decode((string) $outbox->payload, true);
    expect($payload['encounterId'])->toBe($encounterId)
        ->and($payload['disposition'])->toBe(EncounterDisposition::Discharged->value);

    $audit = Tenancy::run($tenantId, fn () => DB::table('shared_audit_log')->where('action', 'encounter.completed')->first());
    expect($audit)->not->toBeNull()
        ->and($audit->actor_id)->toBe((string) $fx['user']->id)
        ->and($audit->target_id)->toBe($encounterId);
});

it('rejects HTTP complete without the complete permission (403)', function (): void {
    $fx = completeHttpFixture([]);
    $tenantId = (string) $fx['tenant']->id;
    $facilityId = (string) $fx['facility']->id;
    $encounterId = seedActiveHttpEncounter($tenantId, $facilityId, EncounterDisposition::Discharged);

    $this->actingAs($fx['user'])
        ->withSession(['active_facility_id' => $facilityId])
        ->postJson(completeUrl($encounterId))
        ->assertForbidden();

    $this->actingAs($fx['user'])
        ->withSession(['active_facility_id' => $facilityId])
        ->post(completeUrl($encounterId))
        ->assertForbidden();

    expect(Tenancy::run($tenantId, fn () => DB::table('enc_encounters')->where('id', $encounterId)->value('status')))->toBe('ACTIVE');
});

it('rejects HTTP complete with only the update key (needs its own dedicated key)', function (): void {
    $fx = completeHttpFixture(['encounter.encounters.update']);
    $tenantId = (string) $fx['tenant']->id;
    $facilityId = (string) $fx['facility']->id;
    $encounterId = seedActiveHttpEncounter($tenantId, $facilityId, EncounterDisposition::Discharged);

    $this->actingAs($fx['user'])
        ->withSession(['active_facility_id' => $facilityId])
        ->postJson(completeUrl($encounterId))
        ->assertForbidden();
});

it('rejects HTTP complete at the wrong facility (403 per policy)', function (): void {
    $fx = completeHttpFixture(['encounter.encounters.complete']);
    $tenantId = (string) $fx['tenant']->id;
    $facilityId = (string) $fx['facility']->id;

    $otherFacilityId = (string) Str::uuid7();
    $encounterId = seedActiveHttpEncounter($tenantId, $otherFacilityId, EncounterDisposition::Discharged);

    $this->actingAs($fx['user'])
        ->withSession(['active_facility_id' => $facilityId])
        ->postJson(completeUrl($encounterId))
        ->assertForbidden();

    expect(Tenancy::run($tenantId, fn () => DB::table('enc_encounters')->where('id', $encounterId)->value('status')))->toBe('ACTIVE');
});

it('returns 404 for a cross-tenant encounter id on HTTP complete', function (): void {
    $fxA = completeHttpFixture();
    $fxB = completeHttpFixture();
    $encounterId = seedActiveHttpEncounter((string) $fxA['tenant']->id, (string) $fxA['facility']->id, EncounterDisposition::Discharged);

    $this->actingAs($fxB['user'])
        ->withSession(['active_facility_id' => (string) $fxB['facility']->id])
        ->postJson(completeUrl($encounterId))
        ->assertNotFound()
        ->assertJsonPath('error.code', 'not_found');
});

it('returns 409 illegal transition when completing a non-ACTIVE encounter via HTTP', function (): void {
    $fx = completeHttpFixture();
    $tenantId = (string) $fx['tenant']->id;
    $facilityId = (string) $fx['facility']->id;

    $encounterId = TenantContextScope::run($tenantId, null, fn () => (string) Encounter::factory()
        ->forTenant($tenantId)->atFacility($facilityId)
        ->forPatient((string) Str::uuid7())
        ->withStatus(EncounterStatus::Arrived)
        ->create(['disposition' => EncounterDisposition::Discharged])->id);

    $this->actingAs($fx['user'])
        ->withSession(['active_facility_id' => $facilityId])
        ->postJson(completeUrl($encounterId))
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'illegal_encounter_transition');

    $this->actingAs($fx['user'])
        ->withSession(['active_facility_id' => $facilityId])
        ->from('/workspaces/reception')
        ->post(completeUrl($encounterId))
        ->assertRedirect('/workspaces/reception')
        ->assertSessionHasErrors('complete');
});

it('returns 409 disposition-required with a clear message when completing ACTIVE without disposition', function (): void {
    $fx = completeHttpFixture();
    $tenantId = (string) $fx['tenant']->id;
    $facilityId = (string) $fx['facility']->id;
    $encounterId = seedActiveHttpEncounter($tenantId, $facilityId, null);

    $response = $this->actingAs($fx['user'])
        ->withSession(['active_facility_id' => $facilityId])
        ->postJson(completeUrl($encounterId));

    $response->assertStatus(409)
        ->assertJsonPath('error.code', 'illegal_encounter_transition');
    expect((string) $response->json('error.message'))->toContain('disposition')
        ->and((string) $response->json('error.message'))->toContain('ChangeEncounterDisposition');

    $this->actingAs($fx['user'])
        ->withSession(['active_facility_id' => $facilityId])
        ->from('/workspaces/reception')
        ->post(completeUrl($encounterId))
        ->assertRedirect('/workspaces/reception')
        ->assertSessionHasErrors('complete');

    expect(Tenancy::run($tenantId, fn () => DB::table('enc_encounters')->where('id', $encounterId)->value('status')))->toBe('ACTIVE');
});

it('returns 404 for an unknown encounter id on HTTP complete', function (): void {
    $fx = completeHttpFixture();
    $facilityId = (string) $fx['facility']->id;

    $this->actingAs($fx['user'])
        ->withSession(['active_facility_id' => $facilityId])
        ->postJson(completeUrl((string) Str::uuid7()))
        ->assertNotFound()
        ->assertJsonPath('error.code', 'not_found');
});

it('redirects guests to login on HTTP complete', function (): void {
    $this->post(completeUrl((string) Str::uuid7()))->assertRedirect('/login');
});

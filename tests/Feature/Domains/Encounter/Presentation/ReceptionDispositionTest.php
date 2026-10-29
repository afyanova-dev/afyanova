<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Encounter\Presentation;

use App\Domains\Encounter\Contracts\Event\EncounterDispositionChanged;
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
function dispositionHttpFixture(array $permissions = ['encounter.encounters.manage_disposition']): array
{
    $fx = makeTenantWithUser($permissions, []);

    return ['tenant' => $fx['tenant'], 'facility' => $fx['facility'], 'user' => $fx['user']];
}

function seedActiveHttpEncounterForDisposition(string $tenantId, string $facilityId, ?EncounterDisposition $disposition = null): string
{
    return TenantContextScope::run($tenantId, null, fn () => (string) Encounter::factory()
        ->forTenant($tenantId)->atFacility($facilityId)
        ->forPatient((string) Str::uuid7())
        ->active()
        ->create(['disposition' => $disposition])->id);
}

function dispositionUrl(string $encounterId): string
{
    return "/workspaces/reception/encounters/{$encounterId}/disposition";
}

it('records each settable disposition via HTTP, keeping ACTIVE and emitting event plus audit', function (EncounterDisposition $disposition): void {
    $fx = dispositionHttpFixture();
    $tenantId = (string) $fx['tenant']->id;
    $facilityId = (string) $fx['facility']->id;
    $encounterId = seedActiveHttpEncounterForDisposition($tenantId, $facilityId);

    $response = $this->actingAs($fx['user'])
        ->withSession(['active_facility_id' => $facilityId])
        ->from('/workspaces/reception')
        ->post(dispositionUrl($encounterId), ['disposition' => $disposition->value]);

    $response->assertRedirect('/workspaces/reception');
    $response->assertSessionHas('status');

    $row = Tenancy::run($tenantId, fn () => DB::table('enc_encounters')->where('id', $encounterId)->first());
    expect($row->status)->toBe('ACTIVE')
        ->and($row->disposition)->toBe($disposition->value);

    $outbox = Tenancy::run($tenantId, fn () => DB::table('shared_outbox_events')
        ->where('event_class', EncounterDispositionChanged::class)->first());
    expect($outbox)->not->toBeNull()
        ->and($outbox->event_name)->toBe('encounter.EncounterDispositionChanged');
    $payload = json_decode((string) $outbox->payload, true);
    expect($payload['encounterId'])->toBe($encounterId)
        ->and($payload['disposition'])->toBe($disposition->value);

    $audit = Tenancy::run($tenantId, fn () => DB::table('shared_audit_log')->where('action', 'encounter.disposition_changed')->first());
    expect($audit)->not->toBeNull()
        ->and($audit->actor_id)->toBe((string) $fx['user']->id)
        ->and($audit->target_id)->toBe($encounterId);
})->with([
    'DISCHARGED' => [EncounterDisposition::Discharged],
    'REFERRED' => [EncounterDisposition::Referred],
    'LEFT' => [EncounterDisposition::Left],
    'FOLLOW_UP' => [EncounterDisposition::FollowUp],
    'DECEASED' => [EncounterDisposition::Deceased],
    'OTHER' => [EncounterDisposition::Other],
]);

it('returns JSON disposition payload when recording via JSON', function (): void {
    $fx = dispositionHttpFixture();
    $facilityId = (string) $fx['facility']->id;
    $encounterId = seedActiveHttpEncounterForDisposition((string) $fx['tenant']->id, $facilityId);

    $this->actingAs($fx['user'])
        ->withSession(['active_facility_id' => $facilityId])
        ->postJson(dispositionUrl($encounterId), ['disposition' => 'DISCHARGED'])
        ->assertOk()
        ->assertJsonPath('data.encounter_id', $encounterId)
        ->assertJsonPath('data.disposition', 'DISCHARGED');
});

it('rejects disposition change on a non-ACTIVE encounter with 409', function (): void {
    $fx = dispositionHttpFixture();
    $tenantId = (string) $fx['tenant']->id;
    $facilityId = (string) $fx['facility']->id;

    $encounterId = TenantContextScope::run($tenantId, null, fn () => (string) Encounter::factory()
        ->forTenant($tenantId)->atFacility($facilityId)
        ->forPatient((string) Str::uuid7())
        ->withStatus(EncounterStatus::Arrived)
        ->create()->id);

    $this->actingAs($fx['user'])
        ->withSession(['active_facility_id' => $facilityId])
        ->postJson(dispositionUrl($encounterId), ['disposition' => 'DISCHARGED'])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'illegal_encounter_transition');

    $this->actingAs($fx['user'])
        ->withSession(['active_facility_id' => $facilityId])
        ->from('/workspaces/reception')
        ->post(dispositionUrl($encounterId), ['disposition' => 'DISCHARGED'])
        ->assertRedirect('/workspaces/reception')
        ->assertSessionHasErrors('disposition');
});

it('rejects ADMITTED via HTTP with 409', function (): void {
    $fx = dispositionHttpFixture();
    $facilityId = (string) $fx['facility']->id;
    $encounterId = seedActiveHttpEncounterForDisposition((string) $fx['tenant']->id, $facilityId);

    $this->actingAs($fx['user'])
        ->withSession(['active_facility_id' => $facilityId])
        ->postJson(dispositionUrl($encounterId), ['disposition' => 'ADMITTED'])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'illegal_encounter_transition');
});

it('rejects TRANSFERRED via HTTP with 409', function (): void {
    $fx = dispositionHttpFixture();
    $facilityId = (string) $fx['facility']->id;
    $encounterId = seedActiveHttpEncounterForDisposition((string) $fx['tenant']->id, $facilityId);

    $this->actingAs($fx['user'])
        ->withSession(['active_facility_id' => $facilityId])
        ->postJson(dispositionUrl($encounterId), ['disposition' => 'TRANSFERRED'])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'illegal_encounter_transition');
});

it('rejects repeating the current disposition via HTTP with 409', function (): void {
    $fx = dispositionHttpFixture();
    $tenantId = (string) $fx['tenant']->id;
    $facilityId = (string) $fx['facility']->id;
    $encounterId = seedActiveHttpEncounterForDisposition($tenantId, $facilityId, EncounterDisposition::Discharged);

    $this->actingAs($fx['user'])
        ->withSession(['active_facility_id' => $facilityId])
        ->postJson(dispositionUrl($encounterId), ['disposition' => 'DISCHARGED'])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'illegal_encounter_transition');
});

it('rejects an invalid disposition value with validation error', function (): void {
    $fx = dispositionHttpFixture();
    $facilityId = (string) $fx['facility']->id;
    $encounterId = seedActiveHttpEncounterForDisposition((string) $fx['tenant']->id, $facilityId);

    $this->actingAs($fx['user'])
        ->withSession(['active_facility_id' => $facilityId])
        ->postJson(dispositionUrl($encounterId), ['disposition' => 'BOGUS'])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'validation_error');

    $this->actingAs($fx['user'])
        ->withSession(['active_facility_id' => $facilityId])
        ->from('/workspaces/reception')
        ->post(dispositionUrl($encounterId), ['disposition' => 'BOGUS'])
        ->assertRedirect('/workspaces/reception')
        ->assertSessionHasErrors('disposition');
});

it('returns 404 for a malformed encounter route', function (): void {
    $fx = dispositionHttpFixture();
    $facilityId = (string) $fx['facility']->id;

    $this->actingAs($fx['user'])
        ->withSession(['active_facility_id' => $facilityId])
        ->postJson('/workspaces/reception/encounters/not-a-uuid/disposition', ['disposition' => 'DISCHARGED'])
        ->assertNotFound();
});

it('rejects HTTP disposition without the manage_disposition permission with 403', function (): void {
    $fx = dispositionHttpFixture([]);
    $tenantId = (string) $fx['tenant']->id;
    $facilityId = (string) $fx['facility']->id;
    $encounterId = seedActiveHttpEncounterForDisposition($tenantId, $facilityId);

    $this->actingAs($fx['user'])
        ->withSession(['active_facility_id' => $facilityId])
        ->postJson(dispositionUrl($encounterId), ['disposition' => 'DISCHARGED'])
        ->assertForbidden();

    $this->actingAs($fx['user'])
        ->withSession(['active_facility_id' => $facilityId])
        ->post(dispositionUrl($encounterId), ['disposition' => 'DISCHARGED'])
        ->assertForbidden();

    expect(Tenancy::run($tenantId, fn () => DB::table('enc_encounters')->where('id', $encounterId)->value('disposition')))->toBeNull();
});

it('rejects HTTP disposition at the wrong facility with 403', function (): void {
    $fx = dispositionHttpFixture();
    $tenantId = (string) $fx['tenant']->id;
    $facilityId = (string) $fx['facility']->id;
    $encounterId = seedActiveHttpEncounterForDisposition($tenantId, (string) Str::uuid7());

    $this->actingAs($fx['user'])
        ->withSession(['active_facility_id' => $facilityId])
        ->postJson(dispositionUrl($encounterId), ['disposition' => 'DISCHARGED'])
        ->assertForbidden();
});

it('returns 404 for a missing or cross-tenant encounter on HTTP disposition', function (): void {
    $fx = dispositionHttpFixture();
    $facilityId = (string) $fx['facility']->id;

    $this->actingAs($fx['user'])
        ->withSession(['active_facility_id' => $facilityId])
        ->postJson(dispositionUrl((string) Str::uuid7()), ['disposition' => 'DISCHARGED'])
        ->assertNotFound()
        ->assertJsonPath('error.code', 'not_found');

    $other = dispositionHttpFixture();
    $otherEncounterId = seedActiveHttpEncounterForDisposition((string) $other['tenant']->id, (string) $other['facility']->id);

    $this->actingAs($fx['user'])
        ->withSession(['active_facility_id' => $facilityId])
        ->postJson(dispositionUrl($otherEncounterId), ['disposition' => 'DISCHARGED'])
        ->assertNotFound()
        ->assertJsonPath('error.code', 'not_found');
});

it('ignores a client-supplied actor_id and audits the authenticated user', function (): void {
    $fx = dispositionHttpFixture();
    $tenantId = (string) $fx['tenant']->id;
    $facilityId = (string) $fx['facility']->id;
    $encounterId = seedActiveHttpEncounterForDisposition($tenantId, $facilityId);

    $this->actingAs($fx['user'])
        ->withSession(['active_facility_id' => $facilityId])
        ->from('/workspaces/reception')
        ->post(dispositionUrl($encounterId), [
            'disposition' => 'REFERRED',
            'actor_id' => (string) Str::uuid7(),
            'patient_id' => (string) Str::uuid7(),
            'facility_id' => (string) Str::uuid7(),
            'status' => 'COMPLETED',
        ])
        ->assertRedirect('/workspaces/reception')
        ->assertSessionHas('status');

    $audit = Tenancy::run($tenantId, fn () => DB::table('shared_audit_log')->where('action', 'encounter.disposition_changed')->first());
    expect($audit)->not->toBeNull()
        ->and($audit->actor_id)->toBe((string) $fx['user']->id);
});

it('verifies the JSON error contract for a domain rejection', function (): void {
    $fx = dispositionHttpFixture();
    $tenantId = (string) $fx['tenant']->id;
    $facilityId = (string) $fx['facility']->id;

    $encounterId = TenantContextScope::run($tenantId, null, fn () => (string) Encounter::factory()
        ->forTenant($tenantId)->atFacility($facilityId)
        ->forPatient((string) Str::uuid7())
        ->withStatus(EncounterStatus::Completed)
        ->create(['disposition' => EncounterDisposition::Discharged])->id);

    $response = $this->actingAs($fx['user'])
        ->withSession(['active_facility_id' => $facilityId])
        ->postJson(dispositionUrl($encounterId), ['disposition' => 'REFERRED']);

    $response->assertStatus(409);
    expect($response->json('error.code'))->toBe('illegal_encounter_transition')
        ->and((string) $response->json('error.message'))->not->toBe('');
});

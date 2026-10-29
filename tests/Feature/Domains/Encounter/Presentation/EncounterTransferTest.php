<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Encounter\Presentation;

use App\Domains\Encounter\Domain\Enum\EncounterType;
use App\Domains\Patient\Domain\Model\Patient;
use App\Models\User;
use App\Platform\Queueing\Domain\Model\Queue;
use App\Platform\Queueing\Domain\Repository\QueueRepository;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function transferFixture(array $permissions = []): array
{
    $fx = makeTenantWithUser(array_merge([
        'encounter.encounters.create',
        'encounter.encounters.read',
        'patient.patients.read',
        'queue.ticket.place',
        'queue.ticket.transfer',
    ], $permissions), ['OUTPATIENT_CARE']);

    return ['tenant' => $fx['tenant'], 'facility' => $fx['facility'], 'user' => $fx['user']];
}

function makeQueue(string $tenantId, string $facilityId, string $code, string $name): Queue
{
    $queue = new Queue([
        'tenant_id' => $tenantId,
        'facility_id' => $facilityId,
        'code' => $code,
        'name' => $name,
        'ticket_prefix' => 'T',
        'is_active' => true,
    ]);
    TenantContextScope::run($tenantId, $facilityId, fn () => app(QueueRepository::class)->save($queue));
    $room = queueTestRoom($tenantId, $facilityId, 'ROOM-'.$code);
    queueAssignRoom($tenantId, $facilityId, (string) $queue->id, (string) $room->id);

    return $queue;
}

function checkInToQueue(User $user, string $facilityId, string $patientId, string $queueId): string
{
    $returnTo = "/patient/{$patientId}/chart/visits";
    $response = test()->actingAs($user)
        ->withSession(['active_facility_id' => $facilityId])
        ->post('/workspaces/reception/check-in', [
            'patient_id' => $patientId,
            'encounter_type' => EncounterType::Outpatient->value,
            'queue_id' => $queueId,
            'priority' => 'ROUTINE',
            'return_to' => $returnTo,
        ]);
    $response->assertRedirect($returnTo)->assertSessionHas('status');

    $encounterId = TenantContextScope::run(
        (string) $user->tenant_id,
        null,
        fn (): string => (string) DB::table('enc_encounters')->where('patient_id', $patientId)->value('id'),
    );
    expect($encounterId)->not->toBe('');

    return $encounterId;
}

it('moves a patient to another service location', function (): void {
    $fx = transferFixture();
    $tenantId = (string) $fx['tenant']->id;
    $facilityId = (string) $fx['facility']->id;

    $patientId = TenantContextScope::run($tenantId, null, fn () => (string) Patient::factory()
        ->forTenant($tenantId)->create(['display_name' => 'Fatuma Ali', 'status' => 'ACTIVE'])->id);

    $triage = makeQueue($tenantId, $facilityId, 'OPD_TRIAGE', 'OPD Triage Desk');
    $consult = makeQueue($tenantId, $facilityId, 'OPD_CONSULT', 'Outpatient Consultation');

    $encounterId = checkInToQueue($fx['user'], $facilityId, $patientId, (string) $triage->id);

    $returnTo = "/patient/{$patientId}/chart/visits";
    $response = $this->actingAs($fx['user'])
        ->withSession(['active_facility_id' => $facilityId])
        ->post('/workspaces/reception/transfer', [
            'encounter_id' => $encounterId,
            'queue_id' => (string) $consult->id,
            'comment' => 'Needs doctor review',
            'return_to' => $returnTo,
        ]);

    $response->assertRedirect($returnTo);
    $response->assertSessionHas('status');

    $entry = DB::table('qms_queue_entries')->where('encounter_id', $encounterId)->orderByDesc('id')->first();
    expect($entry)->not->toBeNull()
        ->and((string) $entry->queue_id)->toBe((string) $consult->id);
});

it('rejects a move when the encounter has no queue entry', function (): void {
    $fx = transferFixture();
    $tenantId = (string) $fx['tenant']->id;
    $facilityId = (string) $fx['facility']->id;

    $patientId = TenantContextScope::run($tenantId, null, fn () => (string) Patient::factory()
        ->forTenant($tenantId)->create(['display_name' => 'Fatuma Ali', 'status' => 'ACTIVE'])->id);
    makeQueue($tenantId, $facilityId, 'OPD_CONSULT', 'Outpatient Consultation');

    $encounterId = TenantContextScope::run($tenantId, null, fn () => (string) DB::table('enc_encounters')->insertGetId([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $tenantId,
        'facility_id' => $facilityId,
        'patient_id' => $patientId,
        'encounter_number' => 'ENC-1',
        'encounter_type' => EncounterType::Outpatient->value,
        'status' => 'ARRIVED',
        'created_by_user_id' => (string) $fx['user']->id,
        'created_source' => 'RECEPTION',
        'created_at' => now(),
        'updated_at' => now(),
    ]));

    $this->actingAs($fx['user'])
        ->withSession(['active_facility_id' => $facilityId])
        ->post('/workspaces/reception/transfer', [
            'encounter_id' => $encounterId,
            'queue_id' => (string) Str::uuid7(),
        ])
        ->assertSessionHasErrors('transfer');
});

it('rejects a move to the current service location', function (): void {
    $fx = transferFixture();
    $tenantId = (string) $fx['tenant']->id;
    $facilityId = (string) $fx['facility']->id;

    $patientId = TenantContextScope::run($tenantId, null, fn () => (string) Patient::factory()
        ->forTenant($tenantId)->create(['display_name' => 'Fatuma Ali', 'status' => 'ACTIVE'])->id);
    $triage = makeQueue($tenantId, $facilityId, 'OPD_TRIAGE', 'OPD Triage Desk');

    $encounterId = checkInToQueue($fx['user'], $facilityId, $patientId, (string) $triage->id);

    $this->actingAs($fx['user'])
        ->withSession(['active_facility_id' => $facilityId])
        ->post('/workspaces/reception/transfer', [
            'encounter_id' => $encounterId,
            'queue_id' => (string) $triage->id,
        ])
        ->assertSessionHasErrors('queue_id');
});

it('forbids transfers without the transfer permission', function (): void {
    $fx = makeTenantWithUser(['encounter.encounters.create', 'patient.patients.read'], ['OUTPATIENT_CARE']);
    $facilityId = (string) $fx['facility']->id;

    $this->actingAs($fx['user'])
        ->withSession(['active_facility_id' => $facilityId])
        ->post('/workspaces/reception/transfer', [
            'encounter_id' => (string) Str::uuid7(),
            'queue_id' => (string) Str::uuid7(),
        ])
        ->assertForbidden();
});

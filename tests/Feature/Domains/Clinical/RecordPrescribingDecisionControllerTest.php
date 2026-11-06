<?php

declare(strict_types=1);

use App\Domains\Clinical\Contracts\Event\PrescriptionIssued;
use App\Shared\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

require_once __DIR__.'/../../../Support/plan_prescribing.php';

test('a repeated medication submission with the same idempotency key records one decision', function (): void {
    $fixture = planPrescribingFixture();
    $tenantId = (string) $fixture['tenant']->id;
    $facilityId = (string) $fixture['facility']->id;
    [$patientId, $encounterId] = clinicalPatientWithEncounter(
        $tenantId,
        $facilityId,
        (string) $fixture['user']->id,
    );
    $payload = [
        'idempotency_key' => '10000000-0000-7000-8000-000000000001',
        'patient_id' => $patientId,
        'medication_name' => 'Amoxicillin 250 mg',
        'medication_code' => 'MED-AMOX-250',
        'medication_code_system' => 'AfyaNova Inventory',
        'dose' => '250 mg',
        'route' => 'ORAL',
        'frequency' => 'Three times daily',
        'duration' => '7 days',
        'quantity_value' => '',
        'quantity_unit' => 'capsule',
    ];
    $url = route('clinical.encounters.medications.record', $encounterId);

    $this->actingAs($fixture['user'])
        ->withSession(['active_facility_id' => $facilityId])
        ->post($url, $payload)
        ->assertRedirect()
        ->assertSessionHas('status');

    $this->actingAs($fixture['user'])
        ->withSession(['active_facility_id' => $facilityId])
        ->post($url, $payload)
        ->assertRedirect()
        ->assertSessionHas('status');

    expect(Tenancy::run($tenantId, fn () => DB::table('clin_prescribing_decisions')
        ->where('encounter_id', $encounterId)
        ->where('medication_name', 'Amoxicillin 250 mg')
        ->count()))->toBe(1)
        ->and(Tenancy::run($tenantId, fn () => DB::table('shared_outbox_events')
            ->where('event_class', PrescriptionIssued::class)
            ->count()))->toBe(1)
        ->and(Tenancy::run($tenantId, fn () => DB::table('shared_audit_log')
            ->where('action', 'clinical.prescribing_decisions.recorded')
            ->count()))->toBe(1);
});

test('a medication submission requires an idempotency key', function (): void {
    $fixture = planPrescribingFixture();
    $tenantId = (string) $fixture['tenant']->id;
    $facilityId = (string) $fixture['facility']->id;
    [$patientId, $encounterId] = clinicalPatientWithEncounter(
        $tenantId,
        $facilityId,
        (string) $fixture['user']->id,
    );

    $this->actingAs($fixture['user'])
        ->withSession(['active_facility_id' => $facilityId])
        ->post(route('clinical.encounters.medications.record', $encounterId), [
            'patient_id' => $patientId,
            'medication_name' => 'Amoxicillin 250 mg',
        ])
        ->assertRedirect()
        ->assertSessionHasErrors('idempotency_key');

    expect(Tenancy::run($tenantId, fn () => DB::table('clin_prescribing_decisions')
        ->where('encounter_id', $encounterId)
        ->count()))->toBe(0);
});

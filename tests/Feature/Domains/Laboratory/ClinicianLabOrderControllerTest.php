<?php

declare(strict_types=1);

use App\Domains\Laboratory\Domain\Model\LabOrder;
use App\Domains\Laboratory\Domain\Model\LabOrderItem;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

require_once __DIR__.'/../../../Support/laboratory.php';

uses(RefreshDatabase::class);

test('clinician submits a lab order with selected tests and panels', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = laboratoryFixture();
    $tenantId = (string) $tenant->id;
    $facilityId = (string) $facility->id;
    $definition = seedLabTestDefinition($tenantId, ['code' => 'FBC', 'name' => 'Full Blood Count']);
    $panelMember = seedLabTestDefinition($tenantId, ['code' => 'GLU', 'name' => 'Glucose']);
    $panelId = seedLabPanel($tenantId, 'CHEMISTRY', [(string) $panelMember->id]);
    ['encounterId' => $encounterId, 'patientId' => $patientId] =
        seedActiveLabEncounter($tenantId, $facilityId, (string) $user->id);
    $orderId = (string) Str::uuid7();

    $this->withSession(['active_facility_id' => $facilityId])
        ->from(route('clinician.index'))
        ->post(route('clinical.encounters.lab-orders.create', $encounterId), [
            'order_id' => $orderId,
            'idempotency_key' => (string) Str::uuid7(),
            'submit_idempotency_key' => (string) Str::uuid7(),
            'patient_id' => $patientId,
            'test_definition_ids' => [(string) $definition->id],
            'panel_ids' => [$panelId],
            'priority' => 'URGENT',
            'clinical_indication_text' => 'Fever with suspected infection.',
        ])
        ->assertRedirect(route('clinician.index'));

    $order = TenantContextScope::run($tenantId, $facilityId, fn () => LabOrder::query()->findOrFail($orderId));
    $items = TenantContextScope::run(
        $tenantId,
        $facilityId,
        fn () => LabOrderItem::query()->where('order_id', $orderId)->orderBy('test_code')->get(),
    );

    expect($order->status)->toBe(LabOrder::STATUS_ORDERED)
        ->and($order->priority)->toBe('URGENT')
        ->and($items)->toHaveCount(2)
        ->and($items->pluck('test_code')->all())->toBe(['FBC', 'GLU']);
});

test('clinician lab order requires at least one test or panel', function () {
    ['facility' => $facility] = laboratoryFixture();

    $this->withSession(['active_facility_id' => (string) $facility->id])
        ->from(route('clinician.index'))
        ->post(route('clinical.encounters.lab-orders.create', (string) Str::uuid7()), [
            'order_id' => (string) Str::uuid7(),
            'idempotency_key' => (string) Str::uuid7(),
            'submit_idempotency_key' => (string) Str::uuid7(),
            'patient_id' => (string) Str::uuid7(),
        ])
        ->assertRedirect(route('clinician.index'))
        ->assertSessionHasErrors([
            'test_definition_ids' => 'Select at least one lab test or panel.',
        ]);
});

test('clinician lab order request is forbidden without order creation permission', function () {
    ['facility' => $facility] = laboratoryFixture(['laboratory.access']);

    $this->withSession(['active_facility_id' => (string) $facility->id])
        ->post(route('clinical.encounters.lab-orders.create', (string) Str::uuid7()), [])
        ->assertForbidden();
});

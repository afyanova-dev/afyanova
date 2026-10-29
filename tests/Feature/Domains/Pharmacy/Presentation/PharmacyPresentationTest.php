<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Pharmacy\Presentation;

use App\Domains\Billing\Contracts\BillingApi;
use App\Domains\Identity\Contracts\PharmacistRoleDefinition;
use App\Domains\Inventory\Domain\Model\Batch;
use App\Domains\Inventory\Domain\Model\Item;
use App\Domains\Inventory\Domain\Model\StockBalance;
use App\Domains\Inventory\Domain\Model\StockLocation;
use App\Domains\Organization\Domain\Model\Facility;
use App\Domains\Patient\Domain\Model\Patient;
use App\Domains\Patient\Domain\Model\PatientRegistration;
use App\Domains\Pharmacy\Application\Command\AcceptTranscription;
use App\Domains\Pharmacy\Application\Command\CreatePharmacyOrder;
use App\Domains\Pharmacy\Application\Command\Dispense;
use App\Domains\Pharmacy\Application\Command\TranscribePaperPrescription;
use App\Domains\Pharmacy\Application\Command\ValidatePharmacyOrder;
use App\Models\User;
use App\Shared\Authorization\ScopeChecker;
use App\Shared\Tenancy\TenantContextScope;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

function pharmacyCatalogMedication(string $tenantId, string $name = 'Paracetamol 500mg tablet', string $itemType = 'MEDICATION', bool $active = true): string
{
    return (string) TenantContextScope::run($tenantId, null, function () use ($tenantId, $name, $itemType, $active): string {
        $item = Item::factory()->forTenant($tenantId)->create([
            'name' => $name,
            'item_type' => $itemType,
            'active' => $active,
        ]);

        return (string) $item->id;
    });
}

/**
 * Pharmacy workspace presentation (UI-SPECIFICATIONS.md §11.9, phase 1).
 *
 * @return array{t: string, f: string, user: mixed}
 */
function pharmacyPresentationSetup(?array $permissions = null, array $extraPermissions = []): array
{
    $fixture = pharmacyFixture();
    $t = (string) $fixture['tenant']->id;
    $f = (string) $fixture['facility']->id;

    if ($permissions !== null) {
        // Re-key the fixture user down to the requested set.
        $roleId = DB::table('idn_access_assignments')->where('user_id', $fixture['user']->id)->value('role_id');
        DB::table('idn_permission_role')->where('role_id', $roleId)->delete();
        foreach ($permissions as $key) {
            $permId = DB::table('idn_permissions')->where('key', $key)->value('id');
            DB::table('idn_permission_role')->insert([
                'id' => (string) Str::uuid7(), 'tenant_id' => $t,
                'role_id' => $roleId, 'permission_id' => $permId, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    // The presenter resolves patient identity + item/location reads.
    // grantPermissionsToRole registers missing permission rows (the registry
    // alone doesn't create them) across all of the fixture user's roles.
    $roleIds = DB::table('idn_access_assignments')->where('user_id', $fixture['user']->id)->pluck('role_id')->all();
    foreach ($roleIds as $roleId) {
        grantPermissionsToRole($t, (string) $roleId, array_merge(['patient.patients.read', 'inventory.access'], $extraPermissions));
    }

    return ['t' => $t, 'f' => $f, 'user' => $fixture['user']];
}

// Slice pages unbuilt: disable missing-page enforcement so response shapes
// stay testable until the .tsx lands (then delete this block).
beforeEach(function (): void {
    config([
        'inertia.pages.ensure_pages_exist' => false,
        'inertia.testing.ensure_pages_exist' => false,
    ]);
});

it('redirects guests and forbids users without pharmacy.access', function (): void {
    $this->get('/workspaces/pharmacy')->assertRedirect('/login');

    $fx = pharmacyPresentationSetup(['patient.patients.read']);
    $this->actingAs($fx['user'])->withSession(['active_facility_id' => $fx['f']])
        ->get('/workspaces/pharmacy')->assertStatus(403);
});

it('renders the pharmacy workspace for authorized users', function (): void {
    $fx = pharmacyPresentationSetup();
    $this->actingAs($fx['user'])->withSession(['active_facility_id' => $fx['f']])
        ->get('/workspaces/pharmacy')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Pharmacy/Index')
            ->where('facilityId', $fx['f']));
});

it('records an external paper prescription without linking a clinic visit', function (): void {
    $fx = pharmacyPresentationSetup();
    $patient = TenantContextScope::run($fx['t'], $fx['f'], function () use ($fx): string {
        $record = Patient::factory()->forTenant($fx['t'])->create();
        PatientRegistration::factory()->forTenant($fx['t'])
            ->forPatient((string) $record->id)
            ->atFacility($fx['f'])
            ->create();

        return (string) $record->id;
    });
    $itemId = pharmacyCatalogMedication($fx['t'], 'Amoxicillin 250mg capsules');
    $secondItemId = pharmacyCatalogMedication($fx['t'], 'Paracetamol 500mg tablets');
    $prescriptionId = (string) Str::uuid7();
    $orderId = (string) Str::uuid7();
    $secondPrescriptionId = (string) Str::uuid7();
    $secondOrderId = (string) Str::uuid7();
    $externalIntakeId = (string) Str::uuid7();
    $payload = [
        'patient_id' => $patient,
        'source' => 'EXTERNAL_PAPER',
        'prescriber_name' => 'Dr Jane Mwangi',
        'external_intake_id' => $externalIntakeId,
        'lines' => [[
            'prescription_id' => $prescriptionId,
            'pharmacy_order_id' => $orderId,
            'inventory_item_id' => $itemId,
            'dose' => '250mg',
            'frequency' => 'TDS',
            'prescribed_quantity' => '20',
        ], [
            'prescription_id' => $secondPrescriptionId,
            'pharmacy_order_id' => $secondOrderId,
            'inventory_item_id' => $secondItemId,
            'dose' => '500mg',
            'frequency' => 'PRN',
        ]],
    ];
    $this->actingAs($fx['user'])->withSession(['active_facility_id' => $fx['f']])
        ->post('/workspaces/pharmacy/prescriptions/transcribe', $payload)
        ->assertRedirectToRoute('pharmacy.index', [
            'view' => 'orders',
            'order_id' => $orderId,
            'patient_id' => $patient,
            'external_intake' => 1,
        ]);

    $this->actingAs($fx['user'])->withSession(['active_facility_id' => $fx['f']])
        ->post('/workspaces/pharmacy/prescriptions/transcribe', $payload)
        ->assertRedirectToRoute('pharmacy.index', [
            'view' => 'orders',
            'order_id' => $orderId,
            'patient_id' => $patient,
            'external_intake' => 1,
        ]);

    TenantContextScope::run($fx['t'], $fx['f'], function () use (
        $fx,
        $itemId,
        $prescriptionId,
        $orderId,
        $secondPrescriptionId,
        $secondOrderId,
        $externalIntakeId,
    ): void {
        $rx = DB::table('rx_prescriptions')->where('tenant_id', $fx['t'])->where('id', $prescriptionId)->first();
        expect($rx)->not->toBeNull();
        expect((string) $rx->id)->toBe($prescriptionId);
        expect((string) $rx->status)->toBe('ACTIVE');
        expect((string) $rx->source)->toBe('EXTERNAL_PAPER');
        expect((string) $rx->prescriber_name)->toBe('Dr Jane Mwangi');
        expect((string) $rx->external_intake_id)->toBe($externalIntakeId);
        expect((string) $rx->medication_name)->toBe('Amoxicillin 250mg capsules');
        expect((string) $rx->inventory_item_id)->toBe($itemId);
        expect($rx->prescribing_decision_id)->toBeNull();
        expect($rx->encounter_id)->toBeNull();

        $order = DB::table('rx_pharmacy_orders')->where('tenant_id', $fx['t'])->where('id', $orderId)->first();
        expect($order)->not->toBeNull();
        expect((string) $order->id)->toBe($orderId);
        expect((string) $order->prescription_id)->toBe($prescriptionId);
        expect((string) $order->status)->toBe('RECEIVED');
        $secondRx = DB::table('rx_prescriptions')->where('id', $secondPrescriptionId)->first();
        expect($secondRx)->not->toBeNull();
        expect((string) $secondRx->status)->toBe('ACTIVE');
        expect((string) $secondRx->external_intake_id)->toBe($externalIntakeId);

        $secondOrder = DB::table('rx_pharmacy_orders')->where('id', $secondOrderId)->first();
        expect($secondOrder)->not->toBeNull();
        expect((string) $secondOrder->prescription_id)->toBe($secondPrescriptionId);
        expect((string) $secondOrder->status)->toBe('RECEIVED');
        expect(DB::table('rx_prescriptions')->where('tenant_id', $fx['t'])->count())->toBe(2);
        expect(DB::table('rx_pharmacy_orders')->where('tenant_id', $fx['t'])->count())->toBe(2);
        expect(DB::table('inv_stock_movements')->where('tenant_id', $fx['t'])->count())->toBe(0);
        expect(DB::table('inv_stock_reservations')->where('tenant_id', $fx['t'])->count())->toBe(0);
    });
});

it('refuses free-text and non-medication catalog items for external prescriptions', function (): void {
    $fx = pharmacyPresentationSetup();
    $patient = TenantContextScope::run($fx['t'], $fx['f'], function () use ($fx): string {
        $record = Patient::factory()->forTenant($fx['t'])->create();
        PatientRegistration::factory()->forTenant($fx['t'])
            ->forPatient((string) $record->id)
            ->atFacility($fx['f'])
            ->create();

        return (string) $record->id;
    });
    $validItem = pharmacyCatalogMedication($fx['t'], 'Amoxicillin 500mg capsules');
    $wrongTypeItem = pharmacyCatalogMedication($fx['t'], 'Glucose test strips', 'SUPPLY');

    $request = $this->actingAs($fx['user'])->withSession(['active_facility_id' => $fx['f']]);
    $request->post('/workspaces/pharmacy/prescriptions/transcribe', [
        'patient_id' => $patient,
        'source' => 'EXTERNAL_PAPER',
        'prescriber_name' => 'Dr Test',
        'lines' => [
            [
                'prescription_id' => (string) Str::uuid7(),
                'pharmacy_order_id' => (string) Str::uuid7(),
                'inventory_item_id' => $validItem,
            ],
            [
                'prescription_id' => (string) Str::uuid7(),
                'pharmacy_order_id' => (string) Str::uuid7(),
                'inventory_item_id' => $wrongTypeItem,
            ],
        ],
    ])->assertSessionHasErrors(['prescription']);

    $request->post('/workspaces/pharmacy/prescriptions/transcribe', [
        'patient_id' => $patient,
        'source' => 'EXTERNAL_PAPER',
        'prescriber_name' => 'Dr Test',
        'lines' => [[
            'prescription_id' => (string) Str::uuid7(),
            'pharmacy_order_id' => (string) Str::uuid7(),
            'inventory_item_id' => $wrongTypeItem,
            'medication_name' => 'Unverified free-text drug',
        ]],
    ])->assertSessionHasErrors(['lines.0.medication_name']);

    TenantContextScope::run($fx['t'], $fx['f'], function () use ($fx): void {
        expect(DB::table('rx_prescriptions')->where('tenant_id', $fx['t'])->count())->toBe(0);
        expect(DB::table('rx_pharmacy_orders')->where('tenant_id', $fx['t'])->count())->toBe(0);
    });
});

it('refuses transcription for an unknown patient with no side effects', function (): void {
    $fx = pharmacyPresentationSetup();
    $itemId = pharmacyCatalogMedication($fx['t']);

    $this->actingAs($fx['user'])->withSession(['active_facility_id' => $fx['f']])
        ->post('/workspaces/pharmacy/prescriptions/transcribe', [
            'patient_id' => (string) Str::uuid7(),
            'source' => 'EXTERNAL_PAPER',
            'prescriber_name' => 'Nobody',
            'lines' => [[
                'prescription_id' => (string) Str::uuid7(),
                'pharmacy_order_id' => (string) Str::uuid7(),
                'inventory_item_id' => $itemId,
            ]],
        ])
        ->assertSessionHasErrors(['patient']);

    TenantContextScope::run($fx['t'], $fx['f'], function () use ($fx): void {
        expect(DB::table('rx_prescriptions')->where('tenant_id', $fx['t'])->count())->toBe(0);
    });
});

it('rejects external prescription intake for a patient registered only at another facility', function (): void {
    $fx = pharmacyPresentationSetup();
    $itemId = pharmacyCatalogMedication($fx['t']);
    $otherFacilityId = (string) TenantContextScope::run($fx['t'], null, function () use ($fx): string {
        $organizationId = (string) Facility::query()->findOrFail($fx['f'])->organization_id;

        return (string) Facility::factory()->forTenant($fx['t'], $organizationId)->create()->id;
    });

    $remotePatient = TenantContextScope::run($fx['t'], $otherFacilityId, function () use ($fx, $otherFacilityId): string {
        $patient = Patient::factory()->forTenant($fx['t'])->create();
        PatientRegistration::factory()->forTenant($fx['t'])
            ->forPatient((string) $patient->id)
            ->atFacility($otherFacilityId)
            ->create();

        return (string) $patient->id;
    });
    $request = $this->actingAs($fx['user'])->withSession(['active_facility_id' => $fx['f']]);
    $request->post('/workspaces/pharmacy/prescriptions/transcribe', [
        'patient_id' => $remotePatient,
        'source' => 'EXTERNAL_PAPER',
        'prescriber_name' => 'Dr Test',
        'lines' => [[
            'prescription_id' => (string) Str::uuid7(),
            'pharmacy_order_id' => (string) Str::uuid7(),
            'inventory_item_id' => $itemId,
        ]],
    ])->assertSessionHasErrors(['patient']);

    TenantContextScope::run($fx['t'], $fx['f'], function () use ($fx): void {
        expect(DB::table('rx_prescriptions')->where('tenant_id', $fx['t'])->count())->toBe(0);
    });
});

it('creates a minimal provisional pharmacy patient registered only at the active facility', function (): void {
    $fx = pharmacyPresentationSetup(null, ['patient.patients.create_pharmacy_only']);

    $this->actingAs($fx['user'])->withSession(['active_facility_id' => $fx['f']])
        ->post('/workspaces/pharmacy/external-patients', [
            'given_name' => 'Asha',
            'family_name' => 'Mushi',
            'sex' => 'UNKNOWN',
        ])
        ->assertRedirect();

    TenantContextScope::run($fx['t'], $fx['f'], function () use ($fx): void {
        $patient = DB::table('pat_patients')
            ->where('tenant_id', $fx['t'])
            ->where('given_name', 'Asha')
            ->where('family_name', 'Mushi')
            ->first();
        expect($patient)->not->toBeNull()
            ->and($patient->is_provisional)->toBeTrue()
            ->and($patient->registration_source)->toBe('PHARMACY_EXTERNAL_PRESCRIPTION');

        expect(DB::table('pat_patient_registrations')
            ->where('tenant_id', $fx['t'])
            ->where('patient_id', $patient->id)
            ->where('facility_id', $fx['f'])
            ->exists())->toBeTrue();
        expect(DB::table('enc_encounters')
            ->where('tenant_id', $fx['t'])
            ->where('patient_id', $patient->id)
            ->exists())->toBeFalse();
    });
});

it('creates and validates an order, then dispenses from live stock', function (): void {
    $fx = pharmacyPresentationSetup();
    $actor = (string) $fx['user']->id;
    $catalogItemId = pharmacyCatalogMedication($fx['t']);
    [$patient] = TenantContextScope::run($fx['t'], $fx['f'], function () use ($fx, $actor): array {
        return clinicalPatientWithEncounter($fx['t'], $fx['f'], $actor);
    });
    $rx = TenantContextScope::run($fx['t'], $fx['f'], function () use ($fx, $actor, $patient, $catalogItemId): string {
        $id = (string) Str::uuid7();
        dispatchRx($fx['t'], $fx['f'], new TranscribePaperPrescription(
            prescriptionId: $id, actorId: $actor, patientId: $patient,
            facilityId: $fx['f'], prescriberName: 'Dr Test',
        ));
        dispatchRx($fx['t'], $fx['f'], new AcceptTranscription(
            prescriptionId: $id, actorId: $actor, medication: ['inventory_item_id' => $catalogItemId],
        ));

        return $id;
    });
    ['itemId' => $mismatchedItem, 'locationId' => $mismatchedLocation] = pharmacyStockedItem($fx['t'], $fx['f'], '100');
    ['itemId' => $item, 'locationId' => $loc] = pharmacyStockedItem($fx['t'], $fx['f'], '100', $catalogItemId);

    $this->actingAs($fx['user'])->withSession(['active_facility_id' => $fx['f']])
        ->post('/workspaces/pharmacy/orders', ['prescription_id' => $rx])
        ->assertRedirect();

    $orderId = TenantContextScope::run($fx['t'], $fx['f'], function () use ($fx): string {
        return (string) DB::table('rx_pharmacy_orders')->where('tenant_id', $fx['t'])->value('id');
    });

    $this->actingAs($fx['user'])->withSession(['active_facility_id' => $fx['f']])
        ->post("/workspaces/pharmacy/orders/{$orderId}/validate")
        ->assertSessionHasNoErrors();

    $this->actingAs($fx['user'])->withSession(['active_facility_id' => $fx['f']])
        ->post('/workspaces/pharmacy/dispensings', [
            'pharmacy_order_id' => $orderId,
            'item_id' => $mismatchedItem,
            'location_id' => $mismatchedLocation,
            'quantity' => '20',
        ])
        ->assertSessionHasErrors(['dispense']);
    TenantContextScope::run($fx['t'], $fx['f'], function () use ($fx, $mismatchedItem, $mismatchedLocation): void {
        expect(DB::table('rx_dispensings')->where('tenant_id', $fx['t'])->count())->toBe(0);
        $balance = DB::table('inv_stock_balances')
            ->where('item_id', $mismatchedItem)
            ->where('location_id', $mismatchedLocation)
            ->first();
        expect((float) $balance->on_hand_qty)->toBe(100.0)
            ->and((float) $balance->reserved_qty)->toBe(0.0);
    });

    $this->actingAs($fx['user'])->withSession(['active_facility_id' => $fx['f']])
        ->post('/workspaces/pharmacy/dispensings', [
            'pharmacy_order_id' => $orderId,
            'item_id' => $item,
            'location_id' => $loc,
            'quantity' => '20',
        ])
        ->assertSessionHasNoErrors();

    // Over-dispensing past available stock is refused.
    $this->actingAs($fx['user'])->withSession(['active_facility_id' => $fx['f']])
        ->post('/workspaces/pharmacy/dispensings', [
            'pharmacy_order_id' => $orderId,
            'item_id' => $item,
            'location_id' => $loc,
            'quantity' => '99999',
        ])
        ->assertSessionHasErrors(['dispense']);

    TenantContextScope::run($fx['t'], $fx['f'], function () use ($fx): void {
        expect(DB::table('rx_dispensings')->where('tenant_id', $fx['t'])->count())->toBe(1);
    });

    $this->actingAs($fx['user'])->withSession(['active_facility_id' => $fx['f']])
        ->get('/workspaces/pharmacy?view=prescriptions')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('prescriptions.0.lastDispenserName', $fx['user']->name));
});

it('records a pickup and refuses to reverse a picked-up dispensing', function (): void {
    $fx = pharmacyPresentationSetup(null, ['pharmacy.dispensing.reverse']);
    $t = $fx['t'];
    $f = $fx['f'];
    $a = (string) $fx['user']->id;
    $user = $fx['user'];

    ['prescriptionId' => $rx, 'patientId' => $patient] = TenantContextScope::run($t, $f, function () use ($t, $f, $a): array {
        $acc = pharmacyAcceptedPrescription($t, $f, $a);

        return ['prescriptionId' => $acc['prescriptionId'], 'patientId' => $acc['patientId']];
    });
    $orderId = (string) Str::uuid7();
    dispatchRx($t, $f, new CreatePharmacyOrder(pharmacyOrderId: $orderId, actorId: $a, prescriptionId: $rx));
    dispatchRx($t, $f, new ValidatePharmacyOrder(pharmacyOrderId: $orderId, actorId: $a));
    ['itemId' => $item, 'locationId' => $loc] = pharmacyStockedItem($t, $f, '100');
    $dispensingId = (string) Str::uuid7();
    dispatchRx($t, $f, new Dispense(
        dispensingId: $dispensingId, actorId: $a, pharmacyOrderId: $orderId, itemId: $item, locationId: $loc, quantity: '10',
    ));

    $billing = \Mockery::mock(BillingApi::class);
    $billing->shouldReceive('dispensingClearedForHandover')->with($dispensingId)->andReturn(true);
    app()->instance(BillingApi::class, $billing);

    $this->actingAs($user)->withSession(['active_facility_id' => $f])
        ->post('/workspaces/pharmacy/pickups', [
            'pharmacy_order_id' => $orderId,
            'dispensing_id' => $dispensingId,
            'recipient_type' => 'PATIENT',
        ])
        ->assertSessionHasNoErrors();

    // PICKED_UP dispensings cannot be reversed (stock left with the patient).
    $this->actingAs($user)->withSession(['active_facility_id' => $f])
        ->post("/workspaces/pharmacy/dispensings/{$dispensingId}/reverse", [
            'pharmacy_order_id' => $orderId,
            'reason_code' => 'DISPENSING_ERROR',
        ])
        ->assertSessionHasErrors(['dispense']);
});

it('shows full prescription detail on rx_id without an order', function (): void {
    $fx = pharmacyPresentationSetup();
    $actor = (string) $fx['user']->id;
    $catalogItemId = pharmacyCatalogMedication($fx['t'], 'Detail Drug');
    $rx = TenantContextScope::run($fx['t'], $fx['f'], function () use ($fx, $actor, $catalogItemId): string {
        [$patient, $encounter] = clinicalPatientWithEncounter($fx['t'], $fx['f'], $actor);
        $id = (string) Str::uuid7();
        dispatchRx($fx['t'], $fx['f'], new TranscribePaperPrescription(
            prescriptionId: $id, actorId: $actor, patientId: $patient,
            facilityId: $fx['f'], prescriberName: 'Dr Detail',
        ));
        dispatchRx($fx['t'], $fx['f'], new AcceptTranscription(
            prescriptionId: $id, actorId: $actor,
            medication: ['inventory_item_id' => $catalogItemId, 'dose' => '100mg'],
        ));

        return $id;
    });

    $this->actingAs($fx['user'])->withSession(['active_facility_id' => $fx['f']])
        ->get('/workspaces/pharmacy?rx_id='.$rx)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Pharmacy/Index')
            ->where('selectedPrescriptionId', $rx)
            ->where('selectedMedicationName', 'Detail Drug')
            ->where('selectedDose', '100mg')
            ->where('selectedPrescriberName', 'Dr Detail')
            ->where('selectedOrderId', null));
});

it('rejects, holds, releases and cancels an order with coded reasons', function (): void {
    $fx = pharmacyPresentationSetup();
    $actor = (string) $fx['user']->id;
    [$patient, $encounter] = TenantContextScope::run($fx['t'], $fx['f'], function () use ($fx, $actor): array {
        return clinicalPatientWithEncounter($fx['t'], $fx['f'], $actor);
    });
    $catalogItemId = pharmacyCatalogMedication($fx['t'], 'Valve Drug');
    $mkOrder = function () use ($fx, $actor, $patient, $catalogItemId): string {
        return TenantContextScope::run($fx['t'], $fx['f'], function () use ($fx, $actor, $patient, $catalogItemId): string {
            $rx = (string) Str::uuid7();
            dispatchRx($fx['t'], $fx['f'], new TranscribePaperPrescription(
                prescriptionId: $rx, actorId: $actor, patientId: $patient,
                facilityId: $fx['f'], prescriberName: 'Dr Valve',
            ));
            dispatchRx($fx['t'], $fx['f'], new AcceptTranscription(
                prescriptionId: $rx, actorId: $actor, medication: ['inventory_item_id' => $catalogItemId],
            ));
            $order = (string) Str::uuid7();
            dispatchRx($fx['t'], $fx['f'], new CreatePharmacyOrder(pharmacyOrderId: $order, actorId: $actor, prescriptionId: $rx));

            return $order;
        });
    };

    $asUser = fn () => $this->actingAs($fx['user'])->withSession(['active_facility_id' => $fx['f']]);

    $o1 = $mkOrder();
    $asUser()->post("/workspaces/pharmacy/orders/{$o1}/hold", ['reason_code' => 'STOCK_UNAVAILABLE'])
        ->assertSessionHasNoErrors();
    $asUser()->post("/workspaces/pharmacy/orders/{$o1}/release")->assertSessionHasNoErrors();
    $asUser()->post("/workspaces/pharmacy/orders/{$o1}/reject", ['reason_code' => 'DUPLICATE'])
        ->assertSessionHasNoErrors();
    // Rejecting twice is refused — terminal states stay terminal.
    $asUser()->post("/workspaces/pharmacy/orders/{$o1}/hold", ['reason_code' => 'OTHER'])
        ->assertSessionHasErrors(['order']);

    $o2 = $mkOrder();
    $asUser()->post("/workspaces/pharmacy/orders/{$o2}/cancel", ['reason_code' => 'PATIENT_DECLINED'])
        ->assertSessionHasNoErrors();

    TenantContextScope::run($fx['t'], $fx['f'], function () use ($fx, $o1, $o2): void {
        expect(DB::table('rx_pharmacy_orders')->where('tenant_id', $fx['t'])->where('id', $o1)->value('status'))->toBe('REJECTED');
        expect(DB::table('rx_pharmacy_orders')->where('tenant_id', $fx['t'])->where('id', $o2)->value('status'))->toBe('CANCELLED');
    });
});

it('cancels a prescription and marks a pickup not collected', function (): void {
    $fx = pharmacyPresentationSetup();
    $actor = (string) $fx['user']->id;
    [$patient, $encounter] = TenantContextScope::run($fx['t'], $fx['f'], function () use ($fx, $actor): array {
        return clinicalPatientWithEncounter($fx['t'], $fx['f'], $actor);
    });
    $rx = TenantContextScope::run($fx['t'], $fx['f'], function () use ($fx, $actor, $patient): string {
        $id = (string) Str::uuid7();
        dispatchRx($fx['t'], $fx['f'], new TranscribePaperPrescription(
            prescriptionId: $id, actorId: $actor, patientId: $patient,
            facilityId: $fx['f'], prescriberName: 'Dr Cancel',
        ));

        return $id;
    });

    $asUser = fn () => $this->actingAs($fx['user'])->withSession(['active_facility_id' => $fx['f']]);
    $asUser()->post("/workspaces/pharmacy/prescriptions/{$rx}/cancel", ['reason' => 'Duplicate paper'])
        ->assertSessionHasNoErrors();

    TenantContextScope::run($fx['t'], $fx['f'], function () use ($fx, $rx): void {
        expect(DB::table('rx_prescriptions')->where('tenant_id', $fx['t'])->where('id', $rx)->value('status'))->toBe('CANCELLED');
    });

    // A dispensed-then-abandoned pickup completes the pickup lifecycle.
    $catalogItemId = pharmacyCatalogMedication($fx['t'], 'Pickup Drug');
    $rx2 = TenantContextScope::run($fx['t'], $fx['f'], function () use ($fx, $actor, $patient, $catalogItemId): string {
        $id = (string) Str::uuid7();
        dispatchRx($fx['t'], $fx['f'], new TranscribePaperPrescription(
            prescriptionId: $id, actorId: $actor, patientId: $patient,
            facilityId: $fx['f'], prescriberName: 'Dr Pickup',
        ));
        dispatchRx($fx['t'], $fx['f'], new AcceptTranscription(
            prescriptionId: $id, actorId: $actor, medication: ['inventory_item_id' => $catalogItemId],
        ));

        return $id;
    });
    $orderId = (string) Str::uuid7();
    dispatchRx($fx['t'], $fx['f'], new CreatePharmacyOrder(pharmacyOrderId: $orderId, actorId: $actor, prescriptionId: $rx2));
    dispatchRx($fx['t'], $fx['f'], new ValidatePharmacyOrder(pharmacyOrderId: $orderId, actorId: $actor));
    ['itemId' => $item, 'locationId' => $loc] = pharmacyStockedItem($fx['t'], $fx['f'], '50', $catalogItemId);
    $dsp = (string) Str::uuid7();
    dispatchRx($fx['t'], $fx['f'], new Dispense(
        dispensingId: $dsp, actorId: $actor, pharmacyOrderId: $orderId, itemId: $item, locationId: $loc, quantity: '5',
    ));

    $asUser()->post('/workspaces/pharmacy/pickups/not-collected', [
        'pharmacy_order_id' => $orderId,
        'dispensing_id' => $dsp,
        'reason_code' => 'PATIENT_NO_SHOW',
    ])->assertSessionHasNoErrors();

    TenantContextScope::run($fx['t'], $fx['f'], function () use ($fx, $dsp): void {
        expect(DB::table('rx_pickups')->where('tenant_id', $fx['t'])->where('dispensing_id', $dsp)->value('status'))->toBe('NOT_COLLECTED');
    });
});

it('accepts an electronic prescription over HTTP (RECEIVED → ACTIVE) and denies without pharmacy.prescriptions.accept', function (): void {
    $fx = pharmacyPresentationSetup();
    $actor = (string) $fx['user']->id;

    // A Clinical prescribing decision → the Pharmacy-owned RECEIVED stub (the
    // electronic path: no clinical snapshot until the authenticated accept).
    $rx = TenantContextScope::run($fx['t'], $fx['f'], function () use ($fx, $actor): string {
        [$patient, $encounter] = clinicalPatientWithEncounter($fx['t'], $fx['f'], $actor);

        return pharmacyIssuePrescription($fx['t'], $fx['f'], $patient, $encounter, $actor)['prescriptionId'];
    });

    expect(rxPrescription($fx['t'], $rx)->status)->toBe('RECEIVED');

    $this->actingAs($fx['user'])->withSession(['active_facility_id' => $fx['f']])
        ->post("/workspaces/pharmacy/prescriptions/{$rx}/accept")
        ->assertSessionHasNoErrors();

    expect(rxPrescription($fx['t'], $rx)->status)->toBe('ACTIVE');

    // A second accept is refused (RECEIVED-only) and surfaces on the prescription error bag.
    $this->actingAs($fx['user'])->withSession(['active_facility_id' => $fx['f']])
        ->post("/workspaces/pharmacy/prescriptions/{$rx}/accept")
        ->assertSessionHasErrors(['prescription']);

    // Without the accept key the coarse controller check refuses before dispatch.
    $denied = pharmacyPresentationSetup(permissions: ['pharmacy.access', 'clinical.prescribing.read']);
    $this->actingAs($denied['user'])->withSession(['active_facility_id' => $denied['f']])
        ->post("/workspaces/pharmacy/prescriptions/{$rx}/accept")
        ->assertForbidden();
});

it('owns the view in the URL: garbage view defaults, selection wins', function (): void {
    $fx = pharmacyPresentationSetup();
    $actor = (string) $fx['user']->id;
    [$patient] = TenantContextScope::run($fx['t'], $fx['f'], function () use ($fx, $actor): array {
        [$p] = clinicalPatientWithEncounter($fx['t'], $fx['f'], $actor);

        return [$p];
    });

    $this->actingAs($fx['user'])->withSession(['active_facility_id' => $fx['f']])
        ->get('/workspaces/pharmacy?view=nonsense')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Pharmacy/Index')
            ->where('view', 'orders')
            ->where('viewPatient', null));

    $this->actingAs($fx['user'])->withSession(['active_facility_id' => $fx['f']])
        ->get("/workspaces/pharmacy?view=orders&patient_id={$patient}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('view', 'patient')
            ->where('viewPatient.patientId', $patient));

    $this->actingAs($fx['user'])->withSession(['active_facility_id' => $fx['f']])
        ->get("/workspaces/pharmacy?view=orders&external_intake=1&patient_id={$patient}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('view', 'orders')
            ->where('externalIntake', true)
            ->where('viewPatient', null)
            ->where('transcribePatient.patientId', $patient));
});

it('grants the pharmacist the full phase-1 keys', function (): void {
    $fixture = makeTenantWithUser(PharmacistRoleDefinition::PERMISSIONS);
    $pharmacist = $fixture['user'];
    $scope = app(ScopeChecker::class);

    $facilityId = (string) $fixture['facility']->id;
    TenantContextScope::run($pharmacist->tenant_id, null, function () use ($pharmacist, $scope, $facilityId): void {
        $perms = $scope->effectivePermissions($pharmacist, $facilityId);
        expect($perms)->toContain('pharmacy.dispensing.record')
            ->and($perms)->toContain('pharmacy.dispensing.reverse')
            ->and($perms)->toContain('inventory.access')
            ->and($perms)->toContain('patient.patients.create_pharmacy_only')
            ->and($perms)->not->toContain('billing.payments.manage');
    });

    $this->actingAs($pharmacist)->withSession(['active_facility_id' => $facilityId])
        ->get('/workspaces/pharmacy')->assertOk();
});

it('does not leak another tenant pharmacy queue', function (): void {
    $a = pharmacyPresentationSetup();
    $b = pharmacyPresentationSetup();
    $actorB = (string) $b['user']->id;
    TenantContextScope::run($b['t'], $b['f'], function () use ($b, $actorB): void {
        [$patient, $encounter] = clinicalPatientWithEncounter($b['t'], $b['f'], $actorB);
        dispatchRx($b['t'], $b['f'], new TranscribePaperPrescription(
            prescriptionId: (string) Str::uuid7(), actorId: $actorB, patientId: $patient,
            facilityId: $b['f'], prescriberName: 'Other Tenant',
        ));
    });

    $this->actingAs($a['user'])->withSession(['active_facility_id' => $a['f']])
        ->get('/workspaces/pharmacy')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Pharmacy/Index')
            ->where('prescriptions', []));
});

it('exposes per-location, per-batch availability with expiry eligibility for the selected dispense item', function (): void {
    $fx = pharmacyPresentationSetup();
    $t = $fx['t'];
    $f = $fx['f'];

    [$itemId, $locationId] = TenantContextScope::run($t, null, function () use ($t, $f): array {
        $item = Item::factory()->forTenant($t)->create(['item_type' => 'MEDICATION', 'active' => true, 'track_batch' => true]);
        $location = StockLocation::factory()->forTenant($t)->forFacility($f)->create();

        $usable = Batch::factory()->forTenant($t)->forItem((string) $item->id)->create([
            'batch_number' => 'LOT-OK', 'expiry_date' => CarbonImmutable::now()->addYear()->toDateString(),
        ]);
        $expired = Batch::factory()->forTenant($t)->forItem((string) $item->id)->create([
            'batch_number' => 'LOT-EXP', 'expiry_date' => CarbonImmutable::now()->subDay()->toDateString(),
        ]);

        StockBalance::factory()->forTenant($t)->onHand('60')->create([
            'facility_id' => $f, 'item_id' => (string) $item->id, 'location_id' => (string) $location->id, 'batch_id' => (string) $usable->id,
        ]);
        StockBalance::factory()->forTenant($t)->onHand('40')->create([
            'facility_id' => $f, 'item_id' => (string) $item->id, 'location_id' => (string) $location->id, 'batch_id' => (string) $expired->id,
        ]);

        return [(string) $item->id, (string) $location->id];
    });

    $this->actingAs($fx['user'])->withSession(['active_facility_id' => $f])
        ->get("/workspaces/pharmacy?item_id={$itemId}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Pharmacy/Index')
            ->where('availabilityStatus', 'AVAILABLE')
            ->where('stockByLocation', function (Collection $rows) use ($locationId): bool {
                if ($rows->count() !== 2) {
                    return false;
                }
                $byBatch = $rows->keyBy('batchNumber');
                $usable = $byBatch->get('LOT-OK');
                $expired = $byBatch->get('LOT-EXP');

                return is_array($usable) && is_array($expired)
                    && $usable['eligible'] === true
                    && $usable['available'] === '60.0000'
                    && $usable['locationId'] === $locationId
                    && $expired['eligible'] === false
                    && $expired['batchId'] !== null
                    && $expired['expiryDate'] !== null;
            }));

    // No item selected → the availability has not been checked (never shown as zero).
    $this->actingAs($fx['user'])->withSession(['active_facility_id' => $f])
        ->get('/workspaces/pharmacy')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('availabilityStatus', 'NOT_CHECKED')
            ->where('stockByLocation', []));
});

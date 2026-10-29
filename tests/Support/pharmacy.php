<?php

declare(strict_types=1);

use App\Domains\Inventory\Domain\Model\Item;
use App\Domains\Inventory\Domain\Model\StockBalance;
use App\Domains\Inventory\Domain\Model\StockLocation;
use App\Domains\Pharmacy\Application\Command\AcceptPrescription;
use App\Domains\Pharmacy\Application\Listener\OnPrescriptionIssued;
use App\Domains\Pharmacy\Domain\Model\Dispensing;
use App\Domains\Pharmacy\Domain\Model\PharmacyOrder;
use App\Domains\Pharmacy\Domain\Model\Prescription;
use App\Models\User;
use App\Shared\Bus\CommandBus;
use App\Shared\Bus\QueryBus;
use App\Shared\Events\DispatchListenerJob;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/*
 |----------------------------------------------------------------------
 | Pharmacy (PHASE 9 / Pharm.1 — ADR-181) test helpers
 |----------------------------------------------------------------------
 */

require_once __DIR__.'/plan_prescribing.php'; // planPrescribingFixture + clinicalRecordPrescribingDecision
require_once __DIR__.'/problem.php';          // grantPermissionsToRole
require_once __DIR__.'/inventory.php';        // grantInventoryRole (Pharm.3 dispensing tests)

if (! function_exists('pharmacyFixture')) {
    /**
     * A tenant + facility + user who can both drive a Clinical prescribing
     * decision AND act as a pharmacist (`pharmacy.access` +
     * `pharmacy.prescriptions.accept` + `pharmacy.orders.manage` +
     * `pharmacy.dispensing.record` + the Inventory keys the Pharm.3 dispensing
     * path needs + `clinical.prescribing.read`).
     *
     * @return array{tenant: mixed, organization: mixed, facility: mixed, user: mixed, role_id: string}
     */
    function pharmacyFixture(): array
    {
        $fixture = planPrescribingFixture();
        grantPermissionsToRole((string) $fixture['tenant']->id, $fixture['role_id'], [
            'pharmacy.access',
            'pharmacy.prescriptions.accept',
            'pharmacy.orders.manage',
            'pharmacy.dispensing.record',
            'pharmacy.pickup.record',
            'inventory.access',
            'inventory.reservations.manage',
            'inventory.consumption.record',
        ]);

        return $fixture;
    }
}

if (! function_exists('pharmacyStockedItem')) {
    /**
     * A non-batch inventory item + a stock location at the fixture facility with
     * `$quantity` on hand — the Pharm.3 dispensing target (ADR-184 B1: the
     * pharmacist picks the `inv_items.id` by hand). Seeds the `StockBalance`
     * directly so the fixture pharmacist needs no receiving permission.
     *
     * @return array{itemId: string, locationId: string}
     */
    function pharmacyStockedItem(
        string $tenantId,
        string $facilityId,
        string $quantity = '100',
        ?string $itemId = null,
    ): array
    {
        return TenantContextScope::run($tenantId, null, function () use ($tenantId, $facilityId, $quantity, $itemId): array {
            $item = $itemId !== null
                ? Item::query()->findOrFail($itemId)
                : Item::factory()->forTenant($tenantId)->create();
            $location = StockLocation::factory()->forTenant($tenantId)->forFacility($facilityId)->create();

            StockBalance::factory()->forTenant($tenantId)->onHand($quantity)->create([
                'facility_id' => $facilityId,
                'item_id' => $itemId ?? (string) $item->id,
                'location_id' => (string) $location->id,
                'batch_id' => null,
            ]);

            return ['itemId' => $itemId ?? (string) $item->id, 'locationId' => (string) $location->id];
        });
    }
}

if (! function_exists('rxDispensing')) {
    function rxDispensing(string $tenantId, string $dispensingId): Dispensing
    {
        return TenantContextScope::run($tenantId, null, fn () => Dispensing::query()->findOrFail($dispensingId));
    }
}

if (! function_exists('pharmacyAcceptedPrescription')) {
    /**
     * A fresh patient + encounter + a prescribing decision → `RECEIVED` stub →
     * `AcceptPrescription` → an `ACTIVE` Pharm.1 prescription ready for a
     * Pharmacy Order. `$overrides` flow to `clinicalRecordPrescribingDecision`
     * (e.g. `quantityValue` / `quantityUnit` for the C.10 §112 path — ADR-184).
     *
     * @param  array<string, mixed>  $overrides
     * @return array{prescriptionId: string, patientId: string, encounterId: string, prescribingDecisionId: string}
     */
    function pharmacyAcceptedPrescription(string $tenantId, string $facilityId, string $actorId, array $overrides = []): array
    {
        [$patientId, $encounterId] = clinicalPatientWithEncounter($tenantId, $facilityId, $actorId);
        ['prescriptionId' => $prescriptionId, 'prescribingDecisionId' => $prescribingDecisionId] =
            pharmacyIssuePrescription($tenantId, $facilityId, $patientId, $encounterId, $actorId, $overrides);

        dispatchRx(
            $tenantId,
            $facilityId,
            new AcceptPrescription(
                prescriptionId: $prescriptionId,
                actorId: $actorId,
            ),
        );

        return [
            'prescriptionId' => $prescriptionId,
            'patientId' => $patientId,
            'encounterId' => $encounterId,
            'prescribingDecisionId' => $prescribingDecisionId,
        ];
    }
}

if (! function_exists('rxPharmacyOrder')) {
    function rxPharmacyOrder(string $tenantId, string $orderId): PharmacyOrder
    {
        return TenantContextScope::run($tenantId, null, fn () => PharmacyOrder::query()->findOrFail($orderId));
    }
}

if (! function_exists('dispatchRx')) {
    function dispatchRx(string $tenantId, ?string $facilityId, object $command): mixed
    {
        return TenantContextScope::run($tenantId, $facilityId, fn () => app(CommandBus::class)->dispatch($command));
    }
}

if (! function_exists('queryRx')) {
    function queryRx(string $tenantId, ?string $facilityId, object $query): mixed
    {
        return TenantContextScope::run($tenantId, $facilityId, fn () => app(QueryBus::class)->dispatch($query));
    }
}

if (! function_exists('pharmacyIssuePrescription')) {
    /**
     * Record a Clinical prescribing decision (emits `clinical.PrescriptionIssued`),
     * drain the outbox, execute the queued `OnPrescriptionIssued` listener job,
     * and return the ids. The `DispatchListenerJob` is returned so a test can
     * call `->handle()` again for the redelivery no-op assertion.
     *
     * @param  array<string, mixed>  $overrides
     * @return array{prescribingDecisionId: string, prescriptionId: string, job: DispatchListenerJob}
     */
    function pharmacyIssuePrescription(
        string $tenantId,
        string $facilityId,
        string $patientId,
        string $encounterId,
        string $actorId,
        array $overrides = [],
    ): array {
        Queue::fake([DispatchListenerJob::class]);

        $prescribingDecisionId = clinicalRecordPrescribingDecision(
            $tenantId,
            $facilityId,
            $patientId,
            $encounterId,
            $actorId,
            $overrides,
        );

        test()->artisan('outbox:relay', ['--once' => true])->assertSuccessful();

        /** @var DispatchListenerJob $job */
        $job = collect(Queue::pushed(DispatchListenerJob::class))
            ->first(fn (DispatchListenerJob $j): bool => $j->listenerClass === OnPrescriptionIssued::class);

        $job->handle();

        $prescriptionId = TenantContextScope::run($tenantId, null, fn (): string => (string) Prescription::query()
            ->where('prescribing_decision_id', $prescribingDecisionId)
            ->value('id'));

        return [
            'prescribingDecisionId' => $prescribingDecisionId,
            'prescriptionId' => $prescriptionId,
            'job' => $job,
        ];
    }
}

if (! function_exists('rxPrescription')) {
    function rxPrescription(string $tenantId, string $prescriptionId): Prescription
    {
        return TenantContextScope::run($tenantId, null, fn () => Prescription::query()->findOrFail($prescriptionId));
    }
}

if (! function_exists('pharmacyUserWithKeys')) {
    /**
     * A fresh user in the tenant with an ACTIVE facility-scoped role carrying
     * exactly the given permission keys (the negative-authorization tests).
     *
     * @param  list<string>  $keys
     */
    function pharmacyUserWithKeys(string $tenantId, string $facilityId, array $keys): User
    {
        $user = User::factory()->forTenant($tenantId)->create(['email_verified_at' => now(), 'status' => 'ACTIVE']);

        TenantContextScope::run($tenantId, null, function () use ($tenantId, $facilityId, $user, $keys): void {
            $roleId = (string) Str::uuid7();
            DB::table('idn_roles')->insert([
                'id' => $roleId, 'tenant_id' => $tenantId, 'key' => 'rx_role_'.Str::lower(Str::random(6)),
                'name' => 'Pharmacy', 'status' => 'ACTIVE', 'scope_type' => 'FACILITY',
                'created_at' => now(), 'updated_at' => now(),
            ]);

            foreach ($keys as $key) {
                $permId = DB::table('idn_permissions')->where('key', $key)->value('id')
                    ?? tap((string) Str::uuid7(), fn (string $id) => DB::table('idn_permissions')->insert([
                        'id' => $id, 'key' => $key, 'layer' => str_ends_with($key, '.access') ? 'ACCESS' : 'ACTION',
                        'created_at' => now(), 'updated_at' => now(),
                    ]));

                DB::table('idn_permission_role')->insert([
                    'id' => (string) Str::uuid7(), 'tenant_id' => $tenantId,
                    'role_id' => $roleId, 'permission_id' => $permId, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }

            DB::table('idn_staff_identities')->insertOrIgnore([
                'id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'user_id' => $user->id,
                'is_staff' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);

            DB::table('idn_access_assignments')->insert([
                'id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'user_id' => $user->id,
                'role_id' => $roleId, 'facility_id' => $facilityId, 'status' => 'ACTIVE',
                'is_primary' => false, 'created_at' => now(), 'updated_at' => now(),
            ]);
        });

        return $user;
    }
}

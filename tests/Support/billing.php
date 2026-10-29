<?php

declare(strict_types=1);

use App\Domains\Billing\Application\Command\AddBillingService;
use App\Domains\Billing\Application\Command\AllocatePayment;
use App\Domains\Billing\Application\Command\ApproveAdjustment;
use App\Domains\Billing\Application\Command\ApproveWriteOff;
use App\Domains\Billing\Application\Command\CloseCashSession;
use App\Domains\Billing\Application\Command\CreateInvoice;
use App\Domains\Billing\Application\Command\IssueInvoice;
use App\Domains\Billing\Application\Command\MapItemToBillingService;
use App\Domains\Billing\Application\Command\OpenCashSession;
use App\Domains\Billing\Application\Command\ReconcileCashSession;
use App\Domains\Billing\Application\Command\RecordPayment;
use App\Domains\Billing\Application\Command\RejectAdjustment;
use App\Domains\Billing\Application\Command\RejectWriteOff;
use App\Domains\Billing\Application\Command\RequestAdjustment;
use App\Domains\Billing\Application\Command\RequestWriteOff;
use App\Domains\Billing\Application\Command\ReverseAdjustment;
use App\Domains\Billing\Application\Command\ReversePayment;
use App\Domains\Billing\Application\Command\ReverseWriteOff;
use App\Domains\Billing\Application\Command\SetTariff;
use App\Domains\Billing\Application\Command\UnallocatePayment;
use App\Domains\Billing\Application\Listener\OnMedicationDispensed;
use App\Domains\Billing\Application\Listener\OnMedicationDispensingReversed;
use App\Domains\Billing\Domain\Exception\CashSessionAlreadyOpen;
use App\Domains\Billing\Domain\ValueObject\PaymentMethod;
use App\Domains\Patient\Domain\Model\Patient;
use App\Domains\Pharmacy\Contracts\Event\MedicationDispensed;
use App\Domains\Pharmacy\Contracts\Event\MedicationDispensingReversed;
use App\Models\User;
use App\Shared\Bus\CommandBus;
use App\Shared\Bus\QueryBus;
use App\Shared\Events\DispatchListenerJob;
use App\Shared\Events\OutboxWriter;
use App\Shared\Tenancy\Tenancy;
use App\Shared\Tenancy\TenantContextScope;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/*
 |----------------------------------------------------------------------
 | Billing & Payments (PHASE 10 / Bill.1 — ADR-187) test helpers
 |----------------------------------------------------------------------
 */

require_once __DIR__.'/security.php'; // makeTenantWithUser

if (! function_exists('billingFixture')) {
    /**
     * A tenant + organization + facility + a user holding `billing.access` +
     * `billing.accounts.manage` at the facility.
     *
     * @param  list<string>|null  $permissions
     * @return array{tenant: mixed, organization: mixed, facility: mixed, user: mixed, role_id: string}
     */
    function billingFixture(?array $permissions = null): array
    {
        $permissions ??= ['billing.access', 'billing.accounts.manage', 'billing.catalog.manage', 'billing.charges.manage', 'billing.invoices.manage', 'billing.payments.manage', 'billing.payments.reverse', 'billing.cash_sessions.manage', 'billing.cash_sessions.reconcile', 'billing.writeoffs.request', 'billing.writeoffs.approve', 'billing.adjustments.request', 'billing.adjustments.approve'];

        /** @var array{tenant: mixed, organization: mixed, facility: mixed, user: mixed, role_id: string} $fixture */
        $fixture = makeTenantWithUser($permissions);
        test()->actingAs($fixture['user']);

        return $fixture;
    }
}

if (! function_exists('billingPatient')) {
    /** A fresh ACTIVE patient in the tenant — the `OpenFinancialAccount` target. */
    function billingPatient(string $tenantId): string
    {
        return TenantContextScope::run($tenantId, null, fn (): string => (string) Patient::factory()
            ->forTenant($tenantId)
            ->create()->id);
    }
}

if (! function_exists('billingAddService')) {
    /**
     * Dispatch `AddBillingService` (in the acting user's facility context — the
     * catalog is tenant-wide, but the policy resolves the key at a facility) and
     * return the new service id.
     */
    function billingAddService(string $tenantId, string $actingFacilityId, string $actorId, string $code, string $category = 'CONSULTATION'): string
    {
        $id = (string) Str::uuid7();
        dispatchBilling($tenantId, $actingFacilityId, new AddBillingService(
            billingServiceId: $id, actorId: $actorId, code: $code, name: $code.' service', category: $category,
        ));

        return $id;
    }
}

if (! function_exists('billingSetTariff')) {
    /**
     * Dispatch `SetTariff` for a scope (`$scopeFacilityId` null → the tenant
     * default) and return the new tariff-row id.
     */
    function billingSetTariff(
        string $tenantId,
        string $actingFacilityId,
        string $actorId,
        string $billingServiceId,
        ?string $scopeFacilityId,
        string $amount,
        string $effectiveFrom,
    ): string {
        $id = (string) Str::uuid7();
        dispatchBilling($tenantId, $actingFacilityId, new SetTariff(
            tariffId: $id, actorId: $actorId, billingServiceId: $billingServiceId,
            facilityId: $scopeFacilityId, amount: $amount, effectiveFrom: $effectiveFrom,
        ));

        return $id;
    }
}

if (! function_exists('billingMapItem')) {
    /**
     * Dispatch `MapItemToBillingService` (in the acting facility context) and
     * return the new map-row id.
     */
    function billingMapItem(string $tenantId, string $actingFacilityId, string $actorId, string $invItemId, string $billingServiceId): string
    {
        $id = (string) Str::uuid7();
        dispatchBilling($tenantId, $actingFacilityId, new MapItemToBillingService(
            mapId: $id, actorId: $actorId, invItemId: $invItemId, billingServiceId: $billingServiceId,
        ));

        return $id;
    }
}

if (! function_exists('billingChargeIdForDispensing')) {
    /** The `bill_charges` id captured for a dispensing's `source_ref`, or null. */
    function billingChargeIdForDispensing(string $tenantId, string $dispensingId): ?string
    {
        $id = Tenancy::run($tenantId, fn () => DB::table('bill_billable_events')
            ->where('source_ref', $dispensingId)->value('charge_id'));

        return $id !== null ? (string) $id : null;
    }
}

if (! function_exists('billingCreateInvoice')) {
    /**
     * Dispatch `CreateInvoice` for an account (in the acting facility context)
     * and return the new invoice id.
     *
     * @param  list<string>|null  $chargeIds
     */
    function billingCreateInvoice(string $tenantId, string $actingFacilityId, string $actorId, string $financialAccountId, ?array $chargeIds = null): string
    {
        $id = (string) Str::uuid7();
        dispatchBilling($tenantId, $actingFacilityId, new CreateInvoice(
            invoiceId: $id, actorId: $actorId, financialAccountId: $financialAccountId, chargeIds: $chargeIds,
        ));

        return $id;
    }
}

if (! function_exists('billingIssueInvoice')) {
    /** Dispatch `IssueInvoice` (in the acting facility context). */
    function billingIssueInvoice(string $tenantId, string $actingFacilityId, string $actorId, string $invoiceId): void
    {
        dispatchBilling($tenantId, $actingFacilityId, new IssueInvoice(
            actorId: $actorId, invoiceId: $invoiceId,
        ));
    }
}

if (! function_exists('billingOpenCashSession')) {
    /**
     * Dispatch `OpenCashSession` for a cashier at a facility; return the id.
     * Idempotent — swallows `CashSessionAlreadyOpen` and returns the existing
     * session's id.
     */
    function billingOpenCashSession(string $tenantId, string $facilityId, string $cashierId, string $openingFloat = '0.00'): string
    {
        $id = (string) Str::uuid7();
        try {
            dispatchBilling($tenantId, $facilityId, new OpenCashSession(
                actorId: $cashierId, cashSessionId: $id, facilityId: $facilityId, openingFloat: $openingFloat,
            ));

            return $id;
        } catch (CashSessionAlreadyOpen) {
            return (string) TenantContextScope::run($tenantId, null, fn () => DB::table('bill_cash_sessions')
                ->where('cashier_user_id', $cashierId)->where('facility_id', $facilityId)
                ->whereIn('status', ['OPEN', 'ACTIVE'])->value('id'));
        }
    }
}

if (! function_exists('billingRecordPayment')) {
    /**
     * Dispatch `RecordPayment` (in the acting facility context) and return the
     * new payment id. For a CASH payment, ensures the cashier has an open cash
     * session at the facility first (idempotent).
     */
    function billingRecordPayment(
        string $tenantId,
        string $actingFacilityId,
        string $actorId,
        string $financialAccountId,
        string $amount,
        string $method = PaymentMethod::CASH,
        ?string $paymentReference = null,
    ): string {
        if ($method === PaymentMethod::CASH) {
            billingOpenCashSession($tenantId, $actingFacilityId, $actorId);
        }

        $id = (string) Str::uuid7();
        dispatchBilling($tenantId, $actingFacilityId, new RecordPayment(
            paymentId: $id, actorId: $actorId, financialAccountId: $financialAccountId,
            amount: $amount, method: $method, paymentReference: $paymentReference,
        ));

        return $id;
    }
}

if (! function_exists('billingCloseCashSession')) {
    /** Dispatch `CloseCashSession` with a counted-cash figure. */
    function billingCloseCashSession(string $tenantId, string $facilityId, string $actorId, string $cashSessionId, string $countedCash, ?string $note = null): void
    {
        dispatchBilling($tenantId, $facilityId, new CloseCashSession(
            actorId: $actorId, cashSessionId: $cashSessionId, countedCash: $countedCash, note: $note,
        ));
    }
}

if (! function_exists('billingReconcileCashSession')) {
    /** Dispatch `ReconcileCashSession`. */
    function billingReconcileCashSession(string $tenantId, string $facilityId, string $actorId, string $cashSessionId, ?string $varianceNote = null): void
    {
        dispatchBilling($tenantId, $facilityId, new ReconcileCashSession(
            actorId: $actorId, cashSessionId: $cashSessionId, varianceNote: $varianceNote,
        ));
    }
}

if (! function_exists('billingConfigureFinancialControl')) {
    /** Run `billing:configure-financial-control` for a tenant. `null` = leave unchanged, `''` = clear. */
    function billingConfigureFinancialControl(string $tenantId, ?string $writeOffAbove = null, ?string $adjustmentAbove = null): void
    {
        $options = ['--tenant' => $tenantId];
        if ($writeOffAbove !== null) {
            $options['--writeoff-dual-control-above'] = $writeOffAbove;
        }
        if ($adjustmentAbove !== null) {
            $options['--adjustment-dual-control-above'] = $adjustmentAbove;
        }

        test()->artisan('billing:configure-financial-control', $options)->assertSuccessful();
    }
}

if (! function_exists('billingRequestWriteOff')) {
    /** Dispatch `RequestWriteOff` (in the acting facility context); return the write-off id. */
    function billingRequestWriteOff(
        string $tenantId,
        string $actingFacilityId,
        string $actorId,
        string $financialAccountId,
        string $amount,
        string $reasonCode = 'APPROVED_BAD_DEBT',
        ?string $invoiceId = null,
        ?string $note = null,
    ): string {
        $id = (string) Str::uuid7();
        dispatchBilling($tenantId, $actingFacilityId, new RequestWriteOff(
            writeOffId: $id, actorId: $actorId, financialAccountId: $financialAccountId,
            amount: $amount, reasonCode: $reasonCode, invoiceId: $invoiceId, note: $note,
        ));

        return $id;
    }
}

if (! function_exists('billingApproveWriteOff')) {
    /** Dispatch `ApproveWriteOff`. */
    function billingApproveWriteOff(string $tenantId, string $actingFacilityId, string $actorId, string $writeOffId): void
    {
        dispatchBilling($tenantId, $actingFacilityId, new ApproveWriteOff(actorId: $actorId, writeOffId: $writeOffId));
    }
}

if (! function_exists('billingRejectWriteOff')) {
    /** Dispatch `RejectWriteOff`. */
    function billingRejectWriteOff(string $tenantId, string $actingFacilityId, string $actorId, string $writeOffId, string $reasonCode = 'RAISED_IN_ERROR'): void
    {
        dispatchBilling($tenantId, $actingFacilityId, new RejectWriteOff(actorId: $actorId, writeOffId: $writeOffId, reasonCode: $reasonCode));
    }
}

if (! function_exists('billingReverseWriteOff')) {
    /** Dispatch `ReverseWriteOff`. */
    function billingReverseWriteOff(string $tenantId, string $actingFacilityId, string $actorId, string $writeOffId, string $reasonCode = 'SUBSEQUENTLY_COLLECTED'): void
    {
        dispatchBilling($tenantId, $actingFacilityId, new ReverseWriteOff(actorId: $actorId, writeOffId: $writeOffId, reasonCode: $reasonCode));
    }
}

if (! function_exists('billingRequestAdjustment')) {
    /** Dispatch `RequestAdjustment` (in the acting facility context); return the id. */
    function billingRequestAdjustment(
        string $tenantId,
        string $actingFacilityId,
        string $actorId,
        string $financialAccountId,
        string $amount,
        string $adjustmentType = 'GOODWILL',
        ?string $invoiceId = null,
        ?string $note = null,
    ): string {
        $id = (string) Str::uuid7();
        dispatchBilling($tenantId, $actingFacilityId, new RequestAdjustment(
            adjustmentId: $id, actorId: $actorId, financialAccountId: $financialAccountId,
            amount: $amount, adjustmentType: $adjustmentType, invoiceId: $invoiceId, note: $note,
        ));

        return $id;
    }
}

if (! function_exists('billingApproveAdjustment')) {
    /** Dispatch `ApproveAdjustment`. */
    function billingApproveAdjustment(string $tenantId, string $actingFacilityId, string $actorId, string $adjustmentId): void
    {
        dispatchBilling($tenantId, $actingFacilityId, new ApproveAdjustment(actorId: $actorId, adjustmentId: $adjustmentId));
    }
}

if (! function_exists('billingRejectAdjustment')) {
    /** Dispatch `RejectAdjustment`. */
    function billingRejectAdjustment(string $tenantId, string $actingFacilityId, string $actorId, string $adjustmentId, string $reasonCode = 'RAISED_IN_ERROR'): void
    {
        dispatchBilling($tenantId, $actingFacilityId, new RejectAdjustment(actorId: $actorId, adjustmentId: $adjustmentId, reasonCode: $reasonCode));
    }
}

if (! function_exists('billingReverseAdjustment')) {
    /** Dispatch `ReverseAdjustment`. */
    function billingReverseAdjustment(string $tenantId, string $actingFacilityId, string $actorId, string $adjustmentId, string $reasonCode = 'RAISED_IN_ERROR'): void
    {
        dispatchBilling($tenantId, $actingFacilityId, new ReverseAdjustment(actorId: $actorId, adjustmentId: $adjustmentId, reasonCode: $reasonCode));
    }
}

if (! function_exists('billingReversePayment')) {
    /** Dispatch `ReversePayment` (in the acting facility context). */
    function billingReversePayment(string $tenantId, string $actingFacilityId, string $actorId, string $paymentId, string $reasonCode = 'PAYMENT_ERROR'): void
    {
        dispatchBilling($tenantId, $actingFacilityId, new ReversePayment(
            actorId: $actorId, paymentId: $paymentId, reasonCode: $reasonCode,
        ));
    }
}

if (! function_exists('billingAllocatePayment')) {
    /** Dispatch `AllocatePayment` and return the new allocation id. */
    function billingAllocatePayment(string $tenantId, string $actingFacilityId, string $actorId, string $paymentId, string $invoiceId, string $amount): string
    {
        $id = (string) Str::uuid7();
        dispatchBilling($tenantId, $actingFacilityId, new AllocatePayment(
            actorId: $actorId, allocationId: $id, paymentId: $paymentId, invoiceId: $invoiceId, amount: $amount,
        ));

        return $id;
    }
}

if (! function_exists('billingUnallocatePayment')) {
    /** Dispatch `UnallocatePayment` (in the acting facility context). */
    function billingUnallocatePayment(string $tenantId, string $actingFacilityId, string $actorId, string $paymentId, string $invoiceId): void
    {
        dispatchBilling($tenantId, $actingFacilityId, new UnallocatePayment(
            actorId: $actorId, paymentId: $paymentId, invoiceId: $invoiceId,
        ));
    }
}

if (! function_exists('dispatchBilling')) {
    function dispatchBilling(string $tenantId, ?string $facilityId, object $command): mixed
    {
        return TenantContextScope::run($tenantId, $facilityId, fn () => app(CommandBus::class)->dispatch($command));
    }
}

if (! function_exists('billingRelayDispensed')) {
    /**
     * Write a `MedicationDispensed` event to the outbox, relay it, and run the
     * queued `OnMedicationDispensed` listener job — the real end-to-end path
     * into `CaptureBillableEvent`.
     *
     * @return array{eventId: string, dispensingId: string}
     */
    function billingRelayDispensed(
        string $tenantId,
        string $facilityId,
        string $patientId,
        string $itemId,
        string $quantity,
        string $dispensedByUserId,
        ?string $dispensingId = null,
    ): array {
        Queue::fake([DispatchListenerJob::class]);
        $eventId = (string) Str::uuid7();
        $dispensingId ??= (string) Str::uuid7();

        $event = new MedicationDispensed(
            eventId: $eventId,
            tenantId: $tenantId,
            facilityId: $facilityId,
            patientId: $patientId,
            dispensingId: $dispensingId,
            pharmacyOrderId: (string) Str::uuid7(),
            prescriptionId: (string) Str::uuid7(),
            itemId: $itemId,
            quantity: $quantity,
            batchNumber: null,
            dispensedByUserId: $dispensedByUserId,
            occurredAt: CarbonImmutable::now()->toIso8601String(),
            correlationId: (string) Str::uuid7(),
        );

        Tenancy::run($tenantId, fn () => app(OutboxWriter::class)->write($event));
        test()->artisan('outbox:relay', ['--once' => true])->assertSuccessful();
        billingRunListenerJob(OnMedicationDispensed::class);

        return ['eventId' => $eventId, 'dispensingId' => $dispensingId];
    }
}

if (! function_exists('billingRelayDispensingReversed')) {
    /** Write + relay a `MedicationDispensingReversed` for a dispensing id. */
    function billingRelayDispensingReversed(
        string $tenantId,
        string $facilityId,
        string $patientId,
        string $itemId,
        string $quantity,
        string $dispensingId,
        string $reasonCode = 'DISPENSING_ERROR',
    ): string {
        Queue::fake([DispatchListenerJob::class]);
        $eventId = (string) Str::uuid7();

        $event = new MedicationDispensingReversed(
            eventId: $eventId,
            tenantId: $tenantId,
            facilityId: $facilityId,
            patientId: $patientId,
            dispensingReversalId: (string) Str::uuid7(),
            dispensingId: $dispensingId,
            pharmacyOrderId: (string) Str::uuid7(),
            prescriptionId: (string) Str::uuid7(),
            itemId: $itemId,
            quantity: $quantity,
            reasonCode: $reasonCode,
            reversedByUserId: (string) Str::uuid7(),
            occurredAt: CarbonImmutable::now()->toIso8601String(),
            correlationId: (string) Str::uuid7(),
        );

        Tenancy::run($tenantId, fn () => app(OutboxWriter::class)->write($event));
        test()->artisan('outbox:relay', ['--once' => true])->assertSuccessful();
        billingRunListenerJob(OnMedicationDispensingReversed::class);

        return $eventId;
    }
}

if (! function_exists('billingRunListenerJob')) {
    function billingRunListenerJob(string $listenerClass): void
    {
        /** @var DispatchListenerJob|null $job */
        $job = collect(Queue::pushed(DispatchListenerJob::class))
            ->first(fn (DispatchListenerJob $j): bool => $j->listenerClass === $listenerClass);

        $job?->handle();
    }
}

if (! function_exists('queryBilling')) {
    function queryBilling(string $tenantId, ?string $facilityId, object $query): mixed
    {
        return TenantContextScope::run($tenantId, $facilityId, fn () => app(QueryBus::class)->dispatch($query));
    }
}

if (! function_exists('billingUserWithKeys')) {
    /**
     * A fresh user in the tenant with an ACTIVE facility-scoped role carrying
     * exactly the given permission keys (the negative-authorization tests) —
     * the `pharmacyUserWithKeys` shape.
     *
     * @param  list<string>  $keys
     */
    function billingUserWithKeys(string $tenantId, string $facilityId, array $keys): User
    {
        $user = User::factory()->forTenant($tenantId)->create(['email_verified_at' => now(), 'status' => 'ACTIVE']);

        TenantContextScope::run($tenantId, null, function () use ($tenantId, $facilityId, $user, $keys): void {
            $roleId = (string) Str::uuid7();
            DB::table('idn_roles')->insert([
                'id' => $roleId, 'tenant_id' => $tenantId, 'key' => 'bill_role_'.Str::lower(Str::random(6)),
                'name' => 'Billing', 'status' => 'ACTIVE', 'scope_type' => 'FACILITY',
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

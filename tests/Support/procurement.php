<?php

declare(strict_types=1);

use App\Domains\Inventory\Application\Command\ReceiveStock;
use App\Domains\Procurement\Application\Command\ActivateContract;
use App\Domains\Procurement\Application\Command\ApprovePurchaseOrder;
use App\Domains\Procurement\Application\Command\ApprovePurchaseRequisition;
use App\Domains\Procurement\Application\Command\CloseRfq;
use App\Domains\Procurement\Application\Command\CreateContract;
use App\Domains\Procurement\Application\Command\CreatePurchaseOrder;
use App\Domains\Procurement\Application\Command\CreatePurchaseRequisition;
use App\Domains\Procurement\Application\Command\CreateRfq;
use App\Domains\Procurement\Application\Command\EvaluateQuotation;
use App\Domains\Procurement\Application\Command\IssuePurchaseOrder;
use App\Domains\Procurement\Application\Command\IssueRfq;
use App\Domains\Procurement\Application\Command\RecordQuotation;
use App\Domains\Procurement\Application\Command\SubmitEvaluation;
use App\Domains\Procurement\Application\Command\SubmitPurchaseOrder;
use App\Domains\Procurement\Application\Command\SubmitPurchaseRequisition;
use App\Domains\Procurement\Application\Listener\OnStockReceived;
use App\Domains\Procurement\Domain\Model\ContractLine;
use App\Domains\Procurement\Domain\Model\PurchaseOrder;
use App\Domains\Procurement\Domain\Model\RfqLine;
use App\Domains\Procurement\Domain\Model\Supplier;
use App\Models\User;
use App\Shared\Bus\CommandBus;
use App\Shared\Bus\QueryBus;
use App\Shared\Events\DispatchListenerJob;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

if (! function_exists('procurementFixture')) {
    /**
     * @param  list<string>|null  $permissions
     * @param  list<string>  $capabilities
     * @return array{tenant: mixed, organization: mixed, facility: mixed, user: mixed, role_id: string}
     */
    function procurementFixture(?array $permissions = null, array $capabilities = []): array
    {
        $permissions ??= [
            'procurement.access',
            'procurement.suppliers.manage',
            'procurement.requisitions.create',
            'procurement.requisitions.approve',
            'procurement.orders.create',
            'procurement.orders.approve',
            'procurement.orders.issue',
            'procurement.orders.amend',
            'procurement.emergency.manage',
        ];

        /** @var array{tenant: mixed, organization: mixed, facility: mixed, user: mixed, role_id: string} $fixture */
        $fixture = makeTenantWithUser($permissions, $capabilities);
        test()->actingAs($fixture['user']);

        return $fixture;
    }
}

if (! function_exists('grantProcurementRole')) {
    /**
     * Assign a second actor a fresh role carrying the given permission keys at a
     * facility — the different-approver / different-buyer tests (§24 / §228).
     *
     * @param  list<string>  $keys
     */
    function grantProcurementRole(
        string $tenantId,
        string $facilityId,
        string $userId,
        array $keys,
        ?string $departmentId = null,
    ): void {
        TenantContextScope::run($tenantId, null, function () use ($tenantId, $facilityId, $userId, $keys, $departmentId): void {
            $roleId = (string) Str::uuid7();
            DB::table('idn_roles')->insert([
                'id' => $roleId, 'tenant_id' => $tenantId, 'key' => 'pcr_role_'.Str::lower(Str::random(6)),
                'name' => 'Procurement', 'status' => 'ACTIVE', 'scope_type' => 'FACILITY',
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
                'id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'user_id' => $userId,
                'is_staff' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);

            DB::table('idn_access_assignments')->insert([
                'id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'user_id' => $userId,
                'role_id' => $roleId, 'facility_id' => $facilityId, 'status' => 'ACTIVE',
                'department_id' => $departmentId,
                'is_primary' => false, 'created_at' => now(), 'updated_at' => now(),
            ]);
        });
    }
}

if (! function_exists('dispatchProc')) {
    function dispatchProc(string $tenantId, ?string $facilityId, object $command): mixed
    {
        return TenantContextScope::run($tenantId, $facilityId, fn () => app(CommandBus::class)->dispatch($command));
    }
}

if (! function_exists('queryProc')) {
    function queryProc(string $tenantId, ?string $facilityId, object $query): mixed
    {
        return TenantContextScope::run($tenantId, $facilityId, fn () => app(QueryBus::class)->dispatch($query));
    }
}

if (! function_exists('seedSupplier')) {
    /**
     * @param  array<string, mixed>  $overrides
     */
    function seedSupplier(string $tenantId, array $overrides = []): Supplier
    {
        return TenantContextScope::run($tenantId, null, fn () => Supplier::factory()
            ->forTenant($tenantId)
            ->create($overrides));
    }
}

if (! function_exists('issuePurchaseOrder')) {
    /**
     * Drive a PO through DRAFT → SUBMITTED → APPROVED → ISSUED with three
     * distinct actors, and return `[poId, poNumber]`.
     *
     * @param  list<array<string, mixed>>  $lines
     * @return array{0: string, 1: string}
     */
    function issuePurchaseOrder(string $tenantId, string $facilityId, string $creatorId, string $supplierId, array $lines): array
    {
        $poId = (string) Str::uuid7();
        dispatchProc($tenantId, $facilityId, new CreatePurchaseOrder(
            purchaseOrderId: $poId, actorId: $creatorId, facilityId: $facilityId,
            supplierId: $supplierId, lines: $lines,
        ));
        dispatchProc($tenantId, $facilityId, new SubmitPurchaseOrder($poId, $creatorId));

        $approver = User::factory()->forTenant($tenantId)->create();
        grantProcurementRole($tenantId, $facilityId, (string) $approver->id, ['procurement.access', 'procurement.orders.approve']);
        $issuer = User::factory()->forTenant($tenantId)->create();
        grantProcurementRole($tenantId, $facilityId, (string) $issuer->id, ['procurement.access', 'procurement.orders.issue']);

        test()->actingAs($approver);
        dispatchProc($tenantId, $facilityId, new ApprovePurchaseOrder($poId, (string) $approver->id));
        test()->actingAs($issuer);
        dispatchProc($tenantId, $facilityId, new IssuePurchaseOrder($poId, (string) $issuer->id));

        $poNumber = (string) TenantContextScope::run($tenantId, null, fn () => PurchaseOrder::query()->find($poId)->po_number);

        return [$poId, $poNumber];
    }
}

if (! function_exists('createContractPo')) {
    /**
     * Dispatch `CreatePurchaseOrder(contractId)` with contract-shaped lines
     * (`{contractLineId, quantity}`) and return its id. The actor must already
     * hold `procurement.orders.create` and be `actingAs`.
     *
     * @param  list<array<string, mixed>>  $lines
     */
    function createContractPo(string $tenantId, string $facilityId, string $actorId, string $supplierId, string $contractId, array $lines): string
    {
        $poId = (string) Str::uuid7();
        dispatchProc($tenantId, $facilityId, new CreatePurchaseOrder(
            purchaseOrderId: $poId, actorId: $actorId, facilityId: $facilityId,
            supplierId: $supplierId, lines: $lines, contractId: $contractId,
        ));

        return $poId;
    }
}

if (! function_exists('issueContractPo')) {
    /**
     * Drive a `CreatePurchaseOrder(contractId)` through DRAFT → ISSUED with
     * distinct approver / issuer, and return `[poId, poNumber]`. Leaves the
     * issuer `actingAs`.
     *
     * @param  list<array<string, mixed>>  $lines
     * @return array{0: string, 1: string}
     */
    function issueContractPo(string $tenantId, string $facilityId, string $creatorId, string $supplierId, string $contractId, array $lines): array
    {
        $poId = createContractPo($tenantId, $facilityId, $creatorId, $supplierId, $contractId, $lines);
        dispatchProc($tenantId, $facilityId, new SubmitPurchaseOrder($poId, $creatorId));

        $approver = User::factory()->forTenant($tenantId)->create();
        grantProcurementRole($tenantId, $facilityId, (string) $approver->id, ['procurement.access', 'procurement.orders.approve']);
        $issuer = User::factory()->forTenant($tenantId)->create();
        grantProcurementRole($tenantId, $facilityId, (string) $issuer->id, ['procurement.access', 'procurement.orders.issue', 'procurement.orders.amend']);

        test()->actingAs($approver);
        dispatchProc($tenantId, $facilityId, new ApprovePurchaseOrder($poId, (string) $approver->id));
        test()->actingAs($issuer);
        dispatchProc($tenantId, $facilityId, new IssuePurchaseOrder($poId, (string) $issuer->id));

        $poNumber = (string) TenantContextScope::run($tenantId, null, fn () => PurchaseOrder::query()->find($poId)->po_number);

        return [$poId, $poNumber];
    }
}

if (! function_exists('receiveAgainstPo')) {
    /**
     * Dispatch an Inventory `ReceiveStock` with `poRef` = the PO number, run the
     * outbox relay, and execute the queued `OnStockReceived` DispatchListenerJob
     * — the full cross-domain path. Returns the queued job so a test can call
     * `->handle()` again for the redelivery no-op assertion.
     */
    function receiveAgainstPo(string $tenantId, string $facilityId, string $actorId, string $poNumber, string $itemId, string $locationId, string $quantity): DispatchListenerJob
    {
        Queue::fake([DispatchListenerJob::class]);

        TenantContextScope::run($tenantId, $facilityId, fn () => app(CommandBus::class)->dispatch(new ReceiveStock(
            movementId: (string) Str::uuid7(),
            actorId: $actorId,
            itemId: $itemId,
            locationId: $locationId,
            quantity: $quantity,
            poRef: $poNumber,
        )));

        test()->artisan('outbox:relay', ['--once' => true])->assertSuccessful();

        /** @var DispatchListenerJob $job */
        $job = collect(Queue::pushed(DispatchListenerJob::class))
            ->first(fn (DispatchListenerJob $j): bool => $j->listenerClass === OnStockReceived::class);

        $job->handle();

        return $job;
    }
}

if (! function_exists('seedApprovedRequisition')) {
    /**
     * Drive a requisition through DRAFT → SUBMITTED → APPROVED (a fresh
     * different approver) and return its id.
     *
     * @param  list<array<string, mixed>>  $lines
     */
    function seedApprovedRequisition(string $tenantId, string $facilityId, string $requesterId, string $approverId, array $lines = []): string
    {
        $requisitionId = (string) Str::uuid7();
        $lines = $lines === [] ? [['lineType' => 'SERVICE', 'description' => 'Analyser service contract', 'quantity' => '1', 'uom' => 'EACH']] : $lines;

        dispatchProc($tenantId, $facilityId, new CreatePurchaseRequisition(
            requisitionId: $requisitionId,
            actorId: $requesterId,
            facilityId: $facilityId,
            lines: $lines,
        ));
        dispatchProc($tenantId, $facilityId, new SubmitPurchaseRequisition($requisitionId, $requesterId));
        dispatchProc($tenantId, $facilityId, new ApprovePurchaseRequisition($requisitionId, $approverId));

        return $requisitionId;
    }
}

if (! function_exists('createRfq')) {
    /**
     * Dispatch `CreateRfq` and return `[rfqId, rfqLineIds]`.
     *
     * @param  list<string>  $supplierIds
     * @param  list<array<string, mixed>>  $lines
     * @return array{0: string, 1: list<string>}
     */
    function createRfq(string $tenantId, string $facilityId, string $actorId, array $supplierIds, array $lines = []): array
    {
        $rfqId = (string) Str::uuid7();
        $lines = $lines === []
            ? [['lineType' => 'SERVICE', 'description' => 'Analyser service contract', 'quantity' => '2', 'uom' => 'EACH']]
            : $lines;

        dispatchProc($tenantId, $facilityId, new CreateRfq(
            rfqId: $rfqId, actorId: $actorId, facilityId: $facilityId, title: 'Test RFQ',
            lines: $lines, invitations: $supplierIds,
        ));

        $lineIds = TenantContextScope::run($tenantId, null, fn (): array => RfqLine::query()
            ->where('rfq_id', $rfqId)->orderBy('line_no')->pluck('id')
            ->map(static fn ($id): string => (string) $id)->all());

        return [$rfqId, $lineIds];
    }
}

if (! function_exists('recordQuotation')) {
    /**
     * Dispatch `RecordQuotation` for one supplier against every RFQ line at a
     * given unit price, and return the quotation id.
     *
     * @param  list<string>  $rfqLineIds
     */
    function recordQuotation(string $tenantId, string $facilityId, string $actorId, string $rfqId, string $supplierId, array $rfqLineIds, string $unitPrice, string $offeredQty = '2', ?int $leadTimeDays = 10): string
    {
        $quotationId = (string) Str::uuid7();
        $lines = array_map(
            static fn (string $rfqLineId): array => ['rfqLineId' => $rfqLineId, 'unitPrice' => $unitPrice, 'offeredQty' => $offeredQty],
            $rfqLineIds,
        );

        dispatchProc($tenantId, $facilityId, new RecordQuotation(
            quotationId: $quotationId, actorId: $actorId, rfqId: $rfqId, supplierId: $supplierId,
            lines: $lines, leadTimeDays: $leadTimeDays,
        ));

        return $quotationId;
    }
}

if (! function_exists('closedRfqWithQuotes')) {
    /**
     * Drive an RFQ create → issue → one quotation per supplier → close, so every
     * quotation is SUBMITTED. Returns `[rfqId, rfqLineIds, [quotationId per supplier]]`.
     *
     * @param  list<string>  $supplierIds
     * @param  list<array<string, mixed>>  $lines
     * @param  list<string>  $unitPrices  one per supplier (defaults to '1000' each)
     * @return array{0: string, 1: list<string>, 2: list<string>}
     */
    function closedRfqWithQuotes(string $tenantId, string $facilityId, string $creatorId, array $supplierIds, array $lines = [], array $unitPrices = [], string $offeredQty = '10'): array
    {
        [$rfqId, $lineIds] = createRfq($tenantId, $facilityId, $creatorId, $supplierIds, $lines);
        dispatchProc($tenantId, $facilityId, new IssueRfq($rfqId, $creatorId));

        $quotationIds = [];
        foreach ($supplierIds as $i => $supplierId) {
            $quotationIds[] = recordQuotation(
                $tenantId, $facilityId, $creatorId, $rfqId, $supplierId, $lineIds,
                $unitPrices[$i] ?? '1000', $offeredQty,
            );
        }

        dispatchProc($tenantId, $facilityId, new CloseRfq($rfqId, $creatorId));

        return [$rfqId, $lineIds, $quotationIds];
    }
}

if (! function_exists('seedActiveContract')) {
    /**
     * Drive a purchase contract through DRAFT → ACTIVE with two distinct actors
     * (§24 / §228 SoD — the activator is minted here, granted
     * `procurement.contracts.manage`, and left `actingAs`). Returns
     * `[contractId, contractLineIds]`.
     *
     * @param  list<array<string, mixed>>  $lines
     * @return array{0: string, 1: list<string>}
     */
    function seedActiveContract(string $tenantId, string $facilityId, string $creatorId, string $supplierId, array $lines = [], string $expiryDate = '+1 year'): array
    {
        $contractId = (string) Str::uuid7();
        $lines = $lines === []
            ? [['lineType' => 'SERVICE', 'description' => 'Analyser service contract', 'uom' => 'EACH', 'agreedUnitPrice' => '1000', 'quantityCeiling' => '100']]
            : $lines;

        dispatchProc($tenantId, $facilityId, new CreateContract(
            contractId: $contractId,
            actorId: $creatorId,
            supplierId: $supplierId,
            title: 'Annual supply agreement',
            effectiveDate: date('Y-m-d', (int) strtotime('-1 day')),
            expiryDate: date('Y-m-d', (int) strtotime($expiryDate)),
            lines: $lines,
        ));

        $activator = User::factory()->forTenant($tenantId)->create();
        grantProcurementRole($tenantId, $facilityId, (string) $activator->id, ['procurement.access', 'procurement.contracts.manage']);
        test()->actingAs($activator);
        dispatchProc($tenantId, $facilityId, new ActivateContract($contractId, (string) $activator->id));

        $lineIds = TenantContextScope::run($tenantId, null, fn (): array => ContractLine::query()
            ->where('contract_id', $contractId)->orderBy('line_no')->pluck('id')
            ->map(static fn ($id): string => (string) $id)->all());

        return [$contractId, $lineIds];
    }
}

if (! function_exists('evaluateQuotation')) {
    /**
     * Dispatch `EvaluateQuotation` (+ optionally `SubmitEvaluation`) for one
     * evaluator against one quotation, and return the evaluation id.
     *
     * @param  array{0: int, 1: int, 2: int, 3: int}  $scores  commercial / technical / quality / delivery
     */
    function evaluateQuotation(string $tenantId, string $facilityId, string $evaluatorId, string $quotationId, array $scores = [80, 70, 75, 65], ?string $comments = null, bool $submit = true): string
    {
        $evaluationId = (string) Str::uuid7();

        dispatchProc($tenantId, $facilityId, new EvaluateQuotation(
            evaluationId: $evaluationId, actorId: $evaluatorId, quotationId: $quotationId,
            commercialScore: $scores[0], technicalScore: $scores[1], qualityScore: $scores[2], deliveryScore: $scores[3],
            comments: $comments,
        ));

        if ($submit) {
            dispatchProc($tenantId, $facilityId, new SubmitEvaluation($evaluationId, $evaluatorId));
        }

        return $evaluationId;
    }
}

if (! function_exists('configureApprovalLimit')) {
    /**
     * Write one `pcr_approval_policies` row — the fixture-level equivalent of
     * `procurement:configure-approval-limit` (Shape B).
     *
     * @param  string|null  $departmentRef  null = the facility-wide row
     */
    function configureApprovalLimit(string $tenantId, string $facilityId, string $limit, ?string $departmentRef = null): void
    {
        TenantContextScope::run($tenantId, null, function () use ($tenantId, $facilityId, $limit, $departmentRef): void {
            DB::table('pcr_approval_policies')->insert([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $tenantId,
                'facility_id' => $facilityId,
                'department_ref' => $departmentRef,
                'self_approval_limit' => $limit,
                'currency' => 'TZS',
                'version' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }
}

<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Application\Command;

use App\Domains\Procurement\Application\ProcurementContextResolver;
use App\Domains\Procurement\Application\RequisitionDepartmentScope;
use App\Domains\Procurement\Domain\Model\PurchaseRequisition;
use App\Domains\Procurement\Domain\Model\RequisitionLine;
use App\Domains\Procurement\Domain\PurchaseRequisitionRepository;
use App\Domains\Procurement\Infrastructure\Sequence\RequisitionNumberSequence;
use App\Models\User;
use App\Shared\Authorization\ScopeChecker;
use App\Shared\Domain\NotFound;
use App\Shared\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Orchestrates `CreatePurchaseRequisition` (ADR-171 D-F).
 *
 * Flow: ADR-133 per-facility refinement (`procurement.requisitions.create` at
 * `facility_id`) → emergency composite check (`procurement.emergency.manage` —
 * F15) → resolve + snapshot every line via `InventoryApi::getItem` (STOCK) /
 * description check (SERVICE) → allocate `PR-%08d` → create DRAFT → versioned
 * save → write `pcr_requisition_lines` (one txn).
 */
final class CreatePurchaseRequisitionHandler
{
    public function __construct(
        private readonly PurchaseRequisitionRepository $requisitions,
        private readonly ProcurementContextResolver $resolver,
        private readonly RequisitionNumberSequence $requisitionNumbers,
        private readonly ScopeChecker $scope,
        private readonly RequisitionDepartmentScope $departmentScope,
        private readonly TenantContext $context,
    ) {}

    public function __invoke(CreatePurchaseRequisition $command): void
    {
        $this->resolver->assertActorScopedToFacility(
            $command->facilityId,
            'procurement.requisitions.create',
            $command->requisitionId,
            $command->requestingDepartmentRef,
        );
        $actor = Auth::user();
        if (! $actor instanceof User || ! $this->departmentScope->canCreateFor(
            $actor,
            $command->facilityId,
            $command->requestingDepartmentRef,
        )) {
            throw NotFound::aggregate(PurchaseRequisition::class, $command->requisitionId);
        }

        if ($command->emergency) {
            $this->assertEmergencyAuthorised($command->facilityId, $command->requisitionId);
        }

        $resolved = [];
        foreach ($command->lines as $index => $line) {
            $resolved[] = $this->resolver->resolveLine($line, $index + 1);
        }

        $tenantId = $this->context->tenantId();

        $requisition = PurchaseRequisition::create(
            id: $command->requisitionId,
            tenantId: $tenantId,
            facilityId: $command->facilityId,
            requisitionNumber: $this->requisitionNumbers->next($tenantId),
            priority: strtoupper(trim($command->priority)),
            createdByUserId: $command->actorId,
            requestingDepartmentRef: $command->requestingDepartmentRef,
            reason: $command->reason,
            estimatedValue: $command->estimatedValue,
            emergency: $command->emergency,
            emergencyReason: $command->emergencyReason,
            costCentreRef: $command->costCentreRef,
            budgetRef: $command->budgetRef,
        );

        $this->requisitions->save($requisition);

        foreach ($resolved as $index => $line) {
            RequisitionLine::create([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $tenantId,
                'facility_id' => $command->facilityId,
                'requisition_id' => $command->requisitionId,
                'line_no' => $index + 1,
                'line_type' => $line['lineType'],
                'item_ref' => $line['itemRef'],
                'item_code' => $line['itemCode'],
                'item_name' => $line['itemName'],
                'description' => $line['description'],
                'quantity' => $line['quantity'],
                'uom' => $line['uom'],
                'unit' => $line['unit'],
                'pack_size' => $line['packSize'],
                'required_date' => $line['requiredDate'] !== null ? CarbonImmutable::parse($line['requiredDate']) : null,
                'status' => RequisitionLine::STATUS_OPEN,
            ]);
        }
    }

    private function assertEmergencyAuthorised(string $facilityId, string $subjectId): void
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            throw new RuntimeException('CreatePurchaseRequisition dispatched without an authenticated user (IC §5).');
        }

        if (! in_array('procurement.emergency.manage', $this->scope->effectivePermissions($user, $facilityId), true)) {
            throw NotFound::aggregate(PurchaseRequisition::class, $subjectId);
        }
    }
}

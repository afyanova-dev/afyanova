<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Application\Command;

use App\Domains\Procurement\Application\ProcurementContextResolver;
use App\Domains\Procurement\Domain\Exception\ProcurementContextMismatch;
use App\Domains\Procurement\Domain\Exception\RequisitionNotApprovedForOrder;
use App\Domains\Procurement\Domain\Exception\SupplierNotOrderable;
use App\Domains\Procurement\Domain\Model\PurchaseRequisition;
use App\Domains\Procurement\Domain\Model\Rfq;
use App\Domains\Procurement\Domain\Model\RfqInvitation;
use App\Domains\Procurement\Domain\Model\RfqLine;
use App\Domains\Procurement\Domain\PurchaseRequisitionRepository;
use App\Domains\Procurement\Domain\RfqRepository;
use App\Domains\Procurement\Domain\SupplierRepository;
use App\Domains\Procurement\Infrastructure\Sequence\RfqNumberSequence;
use App\Shared\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Orchestrates `CreateRfq` (ADR-173 D-D).
 *
 * Flow: ADR-133 per-facility refinement → optional requisition guard rails
 * (APPROVED + same facility) → resolve + snapshot every line via
 * `InventoryApi::getItem` (STOCK) → each invited supplier must be ACTIVE →
 * allocate `RFQ-%08d` → create DRAFT → save → write `pcr_rfq_lines` +
 * `pcr_rfq_invitations` (one txn).
 *
 * `RfqRepository` is the first repository parameter so the bus resolves
 * `RfqPolicy`.
 */
final class CreateRfqHandler
{
    public function __construct(
        private readonly RfqRepository $rfqs,
        private readonly SupplierRepository $suppliers,
        private readonly PurchaseRequisitionRepository $requisitions,
        private readonly ProcurementContextResolver $resolver,
        private readonly RfqNumberSequence $rfqNumbers,
        private readonly TenantContext $context,
    ) {}

    public function __invoke(CreateRfq $command): void
    {
        $this->resolver->assertActorScopedToFacility(
            $command->facilityId,
            'procurement.rfqs.manage',
            $command->rfqId,
        );

        if ($command->requisitionId !== null) {
            $requisition = $this->requisitions->getById($command->requisitionId);
            if ((string) $requisition->status !== PurchaseRequisition::STATUS_APPROVED) {
                throw RequisitionNotApprovedForOrder::status($command->requisitionId, (string) $requisition->status);
            }
            if ((string) $requisition->facility_id !== $command->facilityId) {
                throw ProcurementContextMismatch::requisitionFacility(
                    $command->facilityId,
                    $command->requisitionId,
                    (string) $requisition->facility_id,
                );
            }
        }

        foreach ($command->invitations as $supplierId) {
            $supplier = $this->suppliers->getById($supplierId);
            if (! $supplier->isOrderable()) {
                throw SupplierNotOrderable::status($supplierId, (string) $supplier->status);
            }
        }

        $resolved = [];
        foreach ($command->lines as $index => $line) {
            $resolved[] = $this->resolver->resolveLine($line, $index + 1);
        }

        $tenantId = $this->context->tenantId();
        $now = CarbonImmutable::now();

        $rfq = Rfq::create(
            id: $command->rfqId,
            tenantId: $tenantId,
            facilityId: $command->facilityId,
            rfqNumber: $this->rfqNumbers->next($tenantId),
            requisitionId: $command->requisitionId,
            title: $command->title,
            createdByUserId: $command->actorId,
            reason: $command->reason,
            requiredDate: $command->requiredDate,
            closingDate: $command->closingDate,
            terms: $command->terms,
        );

        $this->rfqs->save($rfq);

        foreach ($resolved as $index => $line) {
            RfqLine::create([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $tenantId,
                'facility_id' => $command->facilityId,
                'rfq_id' => $command->rfqId,
                'line_no' => $index + 1,
                'line_type' => $line['lineType'],
                'item_ref' => $line['itemRef'],
                'item_code' => $line['itemCode'],
                'item_name' => $line['itemName'],
                'description' => $line['description'],
                'quantity' => $line['quantity'],
                'uom' => $line['uom'],
                'required_date' => $line['requiredDate'] !== null ? CarbonImmutable::parse($line['requiredDate']) : null,
            ]);
        }

        foreach ($command->invitations as $supplierId) {
            RfqInvitation::create([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $tenantId,
                'facility_id' => $command->facilityId,
                'rfq_id' => $command->rfqId,
                'supplier_id' => $supplierId,
                'invited_at' => $now,
            ]);
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Application\Command;

use App\Domains\Procurement\Application\ProcurementContextResolver;
use App\Domains\Procurement\Application\RequisitionDepartmentScope;
use App\Domains\Procurement\Domain\Model\PurchaseRequisition;
use App\Domains\Procurement\Domain\Model\RequisitionLine;
use App\Domains\Procurement\Domain\PurchaseRequisitionRepository;
use App\Domains\Procurement\Domain\Service\RequisitionLifecycle;
use App\Models\User;
use App\Shared\Domain\NotFound;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use RuntimeException;

final class UpdateDraftPurchaseRequisitionHandler
{
    public function __construct(
        private readonly PurchaseRequisitionRepository $requisitions,
        private readonly ProcurementContextResolver $resolver,
        private readonly RequisitionDepartmentScope $departmentScope,
        private readonly RequisitionLifecycle $lifecycle,
    ) {}

    public function __invoke(UpdateDraftPurchaseRequisition $command): void
    {
        $requisition = $this->requisitions->getByIdForUpdate($command->requisitionId);
        $actor = Auth::user();

        if (! $actor instanceof User || (string) $actor->id !== $command->actorId) {
            throw new RuntimeException('UpdateDraftPurchaseRequisition actor does not match the authenticated user.');
        }
        if ((string) $requisition->facility_id !== $command->facilityId
            || (string) $requisition->created_by_user_id !== $command->actorId) {
            throw NotFound::aggregate(PurchaseRequisition::class, $command->requisitionId);
        }

        $this->resolver->assertActorScopedToFacility(
            $command->facilityId,
            'procurement.requisitions.create',
            $command->requisitionId,
            $command->requestingDepartmentRef,
        );
        $this->lifecycle->assertCanEditDraft((string) $requisition->status);
        if (! $this->departmentScope->canCreateFor(
            $actor,
            $command->facilityId,
            $command->requestingDepartmentRef,
        )) {
            throw NotFound::aggregate(PurchaseRequisition::class, $command->requisitionId);
        }

        $resolved = [];
        foreach ($command->lines as $index => $line) {
            $resolved[] = $this->resolver->resolveLine($line, $index + 1);
        }

        $requisition->reviseDraft(
            $command->requestingDepartmentRef,
            $command->priority,
            $command->reason,
            $command->costCentreRef,
            $command->budgetRef,
        );
        $this->requisitions->save($requisition);

        RequisitionLine::query()->where('requisition_id', $command->requisitionId)->delete();

        foreach ($resolved as $index => $line) {
            RequisitionLine::create([
                'id' => (string) Str::uuid7(),
                'tenant_id' => (string) $requisition->tenant_id,
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
                'required_date' => $line['requiredDate'] !== null
                    ? CarbonImmutable::parse($line['requiredDate'])
                    : null,
                'status' => RequisitionLine::STATUS_OPEN,
            ]);
        }
    }
}

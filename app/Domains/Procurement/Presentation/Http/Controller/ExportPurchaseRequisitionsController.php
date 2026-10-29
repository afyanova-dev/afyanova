<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Presentation\Http\Controller;

use App\Domains\Organization\Contracts\OrganizationApi;
use App\Domains\Procurement\Application\Query\SearchPurchaseRequisitions;
use App\Domains\Procurement\Contracts\Dto\PurchaseRequisitionSummary;
use App\Domains\Procurement\Presentation\Http\Spreadsheet\ProcurementXlsxDownload;
use App\Shared\Bus\QueryBus;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ExportPurchaseRequisitionsController
{
    private const STATUSES = ['DRAFT', 'SUBMITTED', 'APPROVED', 'REJECTED', 'CANCELLED'];

    public function __construct(
        private readonly QueryBus $queries,
        private readonly OrganizationApi $organization,
        private readonly TenantContext $context,
        private readonly ProcurementXlsxDownload $download,
    ) {}

    public function __invoke(Request $request): StreamedResponse
    {
        $facilityId = $this->context->facilityId();
        abort_if($facilityId === null || $facilityId === '', 403, 'Select an active facility context.');
        $filters = $request->validate([
            'status' => ['nullable', 'string', Rule::in(self::STATUSES)],
        ]);
        $status = isset($filters['status']) && is_string($filters['status']) ? $filters['status'] : null;

        /** @var list<PurchaseRequisitionSummary> $requisitions */
        $requisitions = $this->queries->dispatch(new SearchPurchaseRequisitions($facilityId, $status));
        $structure = $this->organization->getFacilityStructure($facilityId);
        abort_if($structure === null, 404, 'The active facility structure is unavailable.');
        $departmentNames = [];
        foreach ($structure->departments as $department) {
            $departmentNames[(string) ($department['id'] ?? '')] = (string) ($department['name'] ?? '');
        }

        $requestRows = [[
            'Request number', 'Status', 'Department', 'Priority', 'Reason',
            'Emergency', 'Raised at', 'Created by user ID', 'Reviewed at', 'Reviewed by user ID',
            'Cost centre', 'Budget reference',
        ]];
        $lineRows = [[
            'Request number', 'Line', 'Line type', 'Item code', 'Item / description',
            'Quantity', 'Unit', 'Base unit', 'Pack size', 'Required date', 'Line status',
        ]];

        foreach ($requisitions as $requisition) {
            $requestRows[] = [
                $requisition->requisitionNumber,
                $requisition->status,
                $requisition->requestingDepartmentRef !== null
                    ? ($departmentNames[$requisition->requestingDepartmentRef] ?? $requisition->requestingDepartmentRef)
                    : 'Facility-wide',
                $requisition->priority,
                $requisition->reason ?? '',
                $requisition->emergency ? 'Yes' : 'No',
                $requisition->createdAt ?? '',
                $requisition->createdByUserId,
                $requisition->reviewedAt ?? '',
                $requisition->reviewedByUserId ?? '',
                $requisition->costCentreRef ?? '',
                $requisition->budgetRef ?? '',
            ];

            foreach ($requisition->lines as $line) {
                $lineRows[] = [
                    $requisition->requisitionNumber,
                    (string) $line->lineNo,
                    $line->lineType,
                    $line->itemCode ?? '',
                    $line->itemName ?? $line->description ?? '',
                    $line->quantity,
                    $line->unit,
                    $line->uom,
                    $line->packSize ?? '',
                    $line->requiredDate ?? '',
                    $line->status,
                ];
            }
        }

        $filename = 'purchase-requests-'.now()->format('Y-m-d').'.xlsx';

        return $this->download->response($filename, [
            'Purchase Requests' => $requestRows,
            'Request Lines' => $lineRows,
        ]);
    }
}

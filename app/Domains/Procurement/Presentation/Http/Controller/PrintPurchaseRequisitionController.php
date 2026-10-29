<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Presentation\Http\Controller;

use App\Domains\Organization\Contracts\OrganizationApi;
use App\Domains\Procurement\Application\Query\GetPurchaseRequisition;
use App\Domains\Procurement\Contracts\Dto\PurchaseRequisitionSummary;
use App\Shared\Bus\QueryBus;
use App\Shared\Tenancy\TenantContext;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Http\Response;

final class PrintPurchaseRequisitionController
{
    public function __construct(
        private readonly QueryBus $queries,
        private readonly OrganizationApi $organization,
        private readonly TenantContext $context,
    ) {}

    public function __invoke(string $requisitionId): Response
    {
        $facilityId = $this->context->facilityId();
        abort_if($facilityId === null || $facilityId === '', 403, 'Select an active facility context.');

        /** @var PurchaseRequisitionSummary $requisition */
        $requisition = $this->queries->dispatch(new GetPurchaseRequisition($requisitionId, $facilityId));
        $structure = $this->organization->getFacilityStructure($facilityId);
        abort_if($structure === null, 404, 'The active facility structure is unavailable.');
        $departmentName = null;

        if ($requisition->requestingDepartmentRef !== null) {
            foreach ($structure->departments as $department) {
                if (($department['id'] ?? null) === $requisition->requestingDepartmentRef) {
                    $departmentName = (string) ($department['name'] ?? '');
                    break;
                }
            }
        }

        $options = new Options;
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');

        $pdf = new Dompdf($options);
        $pdf->loadHtml(view('procurement.documents.purchase-requisition', [
            'requisition' => $requisition,
            'facilityName' => $structure->facilityName,
            'departmentName' => $departmentName,
            'generatedAt' => now()->format('d M Y H:i'),
        ])->render());
        $pdf->setPaper('A4', 'portrait');
        $pdf->render();

        return response($pdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$requisition->requisitionNumber.'.pdf"',
        ]);
    }
}

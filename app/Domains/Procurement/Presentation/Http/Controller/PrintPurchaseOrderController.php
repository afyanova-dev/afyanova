<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Presentation\Http\Controller;

use App\Domains\Organization\Contracts\OrganizationApi;
use App\Domains\Procurement\Application\Query\GetPurchaseOrder;
use App\Domains\Procurement\Application\Query\GetSupplier;
use App\Domains\Procurement\Contracts\Dto\PurchaseOrderSummary;
use App\Domains\Procurement\Contracts\Dto\SupplierSummary;
use App\Shared\Bus\QueryBus;
use App\Shared\Tenancy\TenantContext;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Http\Response;

final class PrintPurchaseOrderController
{
    public function __construct(
        private readonly QueryBus $queries,
        private readonly OrganizationApi $organization,
        private readonly TenantContext $context,
    ) {}

    public function __invoke(string $purchaseOrderId): Response
    {
        $facilityId = $this->context->facilityId();
        abort_if($facilityId === null || $facilityId === '', 403, 'Select an active facility context.');

        /** @var PurchaseOrderSummary $order */
        $order = $this->queries->dispatch(new GetPurchaseOrder($purchaseOrderId));
        abort_unless($order->facilityId === $facilityId, 404);

        /** @var SupplierSummary $supplier */
        $supplier = $this->queries->dispatch(new GetSupplier($order->supplierId));
        $structure = $this->organization->getFacilityStructure($facilityId);
        abort_if($structure === null, 404, 'The active facility structure is unavailable.');
        $departmentName = null;

        if ($order->requestingDepartmentRef !== null) {
            foreach ($structure->departments as $department) {
                if (($department['id'] ?? null) === $order->requestingDepartmentRef) {
                    $departmentName = (string) ($department['name'] ?? '');
                    break;
                }
            }
        }

        $options = new Options;
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');

        $pdf = new Dompdf($options);
        $pdf->loadHtml(view('procurement.documents.purchase-order', [
            'order' => $order,
            'supplier' => $supplier,
            'facilityName' => $structure->facilityName,
            'departmentName' => $departmentName,
            'generatedAt' => now()->format('d M Y H:i'),
        ])->render());
        $pdf->setPaper('A4', 'portrait');
        $pdf->render();

        return response($pdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$order->poNumber.'.pdf"',
        ]);
    }
}

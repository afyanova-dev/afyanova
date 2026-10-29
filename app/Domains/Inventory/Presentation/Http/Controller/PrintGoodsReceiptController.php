<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Presentation\Http\Controller;

use App\Domains\Inventory\Application\Query\GetGoodsReceipt;
use App\Domains\Inventory\Contracts\Dto\GoodsReceiptDetail;
use App\Domains\Organization\Contracts\OrganizationApi;
use App\Shared\Bus\QueryBus;
use App\Shared\Tenancy\TenantContext;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Http\Response;

/**
 * Printable goods-receipt note (Phase 1 / P1-07).
 *
 * The GRN is the operational delivery document. It prints accepted and rejected
 * quantities, batch/expiry and the destination store. Invoice fields are
 * informational only — no VAT/tax fields.
 */
final class PrintGoodsReceiptController
{
    public function __construct(
        private readonly QueryBus $queries,
        private readonly OrganizationApi $organization,
        private readonly TenantContext $context,
    ) {}

    public function __invoke(string $goodsReceiptId): Response
    {
        $facilityId = $this->context->facilityId();
        abort_if($facilityId === null || $facilityId === '', 403, 'Select an active facility context.');

        /** @var GoodsReceiptDetail $receipt */
        $receipt = $this->queries->dispatch(new GetGoodsReceipt($goodsReceiptId));
        abort_unless($receipt->facilityId === $facilityId, 404);

        $structure = $this->organization->getFacilityStructure($facilityId);
        abort_if($structure === null, 404, 'The active facility structure is unavailable.');

        $options = new Options;
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');

        $pdf = new Dompdf($options);
        $pdf->loadHtml(view('inventory.documents.goods-receipt', [
            'receipt' => $receipt,
            'facilityName' => $structure->facilityName,
            'generatedAt' => now()->format('d M Y H:i'),
        ])->render());
        $pdf->setPaper('A4', 'portrait');
        $pdf->render();

        return response($pdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$receipt->receiptNumber.'.pdf"',
        ]);
    }
}

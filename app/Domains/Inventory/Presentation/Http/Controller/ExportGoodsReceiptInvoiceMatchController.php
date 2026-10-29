<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Presentation\Http\Controller;

use App\Domains\Inventory\Application\Query\GetGoodsReceipt;
use App\Domains\Inventory\Contracts\Dto\GoodsReceiptDetail;
use App\Domains\Inventory\Contracts\Dto\GoodsReceiptLineSummary;
use App\Shared\Bus\QueryBus;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ExportGoodsReceiptInvoiceMatchController
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly QueryBus $queries,
    ) {}

    public function __invoke(Request $request, string $goodsReceiptId): StreamedResponse
    {
        $user = $request->user();
        abort_unless($user !== null && $user->can('procurement.invoices.match'), 403);
        $facilityId = $this->context->facilityId();
        if ($facilityId === null || $facilityId === '') {
            abort(403, 'Select an active facility context.');
        }

        $receipt = $this->queries->dispatch(new GetGoodsReceipt($goodsReceiptId));
        if (! $receipt instanceof GoodsReceiptDetail || $receipt->facilityId !== $facilityId) {
            abort(404);
        }

        return response()->streamDownload(function () use ($receipt): void {
            $output = fopen('php://output', 'w');
            if ($output === false) {
                throw new \RuntimeException('Unable to open the invoice match export stream.');
            }

            fputcsv($output, [
                'GRN', 'Invoice number', 'Invoice date', 'Invoice total', 'Match status',
                'Item code', 'Item', 'PO unit', 'Receipt unit', 'Accepted quantity',
                'Invoice quantity', 'Quantity variance', 'PO unit price (TZS)',
                'Invoice unit price (TZS)', 'Price variance (TZS)', 'Variance details',
            ]);

            foreach ($receipt->lines as $line) {
                /** @var GoodsReceiptLineSummary $line */
                $unitMatches = $line->poUnit !== null
                    && strtoupper($line->poUnit) === strtoupper($line->enteredUnit);
                $acceptedInEnteredUnit = bcdiv($line->quantityAccepted, $line->packSize, 4);
                $quantityVariance = $line->invoiceQuantity !== null
                    ? bcsub($line->invoiceQuantity, $acceptedInEnteredUnit, 4)
                    : '';
                $priceVariance = $line->invoiceUnitPrice !== null && $line->poUnitPrice !== null && $unitMatches
                    ? bcsub($line->invoiceUnitPrice, $line->poUnitPrice, 2)
                    : '';
                $varianceDetails = [];
                if ($line->invoiceQuantity === null) {
                    $varianceDetails[] = 'INVOICE_QUANTITY_MISSING';
                } elseif (bccomp($line->invoiceQuantity, $acceptedInEnteredUnit, 4) !== 0) {
                    $varianceDetails[] = 'QUANTITY_VARIANCE';
                }
                if (! $unitMatches) {
                    $varianceDetails[] = $line->poUnit === null ? 'PO_UNIT_SNAPSHOT_MISSING' : 'UNIT_VARIANCE';
                }
                if ($line->poUnitPrice === null) {
                    $varianceDetails[] = 'PO_PRICE_SNAPSHOT_MISSING';
                } elseif ($line->invoiceUnitPrice !== null && $unitMatches
                    && bccomp($line->invoiceUnitPrice, $line->poUnitPrice, 2) !== 0) {
                    $varianceDetails[] = 'PRICE_VARIANCE';
                }

                fputcsv($output, array_map(self::spreadsheetSafe(...), [
                    $receipt->receiptNumber,
                    (string) ($receipt->invoiceNumber ?? ''),
                    (string) ($receipt->invoiceDate ?? ''),
                    (string) ($receipt->invoiceTotal ?? ''),
                    $receipt->matchStatus,
                    $line->itemCode,
                    $line->itemName,
                    (string) ($line->poUnit ?? ''),
                    $line->enteredUnit,
                    $acceptedInEnteredUnit,
                    (string) ($line->invoiceQuantity ?? ''),
                    (string) $quantityVariance,
                    (string) ($line->poUnitPrice ?? ''),
                    (string) ($line->invoiceUnitPrice ?? ''),
                    (string) $priceVariance,
                    implode('; ', $varianceDetails),
                ]));
            }

            fclose($output);
        }, 'invoice-match-'.$receipt->receiptNumber.'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    private static function spreadsheetSafe(string $value): string
    {
        return preg_match('/^[\s\x00-\x1f]*[=+\-@]/', $value) === 1 ? "'".$value : $value;
    }
}

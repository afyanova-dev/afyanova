<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $receipt->receiptNumber }} — Goods receipt</title>
    <style>
        @page { margin: 22mm 16mm; }
        body { font-family: "DejaVu Sans", sans-serif; font-size: 10px; color: #161616; line-height: 1.4; }
        h1 { font-size: 20px; margin: 0 0 2px; }
        h2 { font-size: 12px; margin: 20px 0 6px; text-transform: uppercase; letter-spacing: .04em; }
        .muted { color: #525252; }
        .header { display: flex; justify-content: space-between; border-bottom: 2px solid #161616; padding-bottom: 10px; }
        .meta { display: grid; grid-template-columns: 1fr 1fr; gap: 4px 24px; margin-top: 12px; }
        .meta div { display: flex; gap: 6px; }
        .meta .label { color: #525252; min-width: 105px; }
        table { width: 100%; border-collapse: collapse; margin-top: 6px; }
        th { background: #e8e8e8; text-align: left; font-size: 9px; padding: 5px 4px; border: 1px solid #8d8d8d; }
        td { padding: 5px 4px; border: 1px solid #c6c6c6; vertical-align: top; }
        td.num { text-align: right; white-space: nowrap; }
        .rejected { color: #a2191f; }
        .signatures { display: flex; justify-content: space-between; margin-top: 44px; gap: 30px; }
        .signature { flex: 1; border-top: 1px solid #161616; padding-top: 4px; font-size: 9px; color: #525252; }
        .footer { margin-top: 26px; font-size: 8px; color: #6f6f6f; border-top: 1px solid #c6c6c6; padding-top: 6px; }
    </style>
</head>
<body>
    <div class="header">
        <div>
            <h1>{{ $facilityName }}</h1>
            <div class="muted">Goods received note (GRN)</div>
        </div>
        <div style="text-align: right">
            <div style="font-size: 16px; font-weight: bold">{{ $receipt->receiptNumber }}</div>
            <div class="muted">Received {{ \Carbon\CarbonImmutable::parse($receipt->receivedAt)->format('d M Y H:i') }}</div>
            <div class="muted">Printed {{ $generatedAt }}</div>
        </div>
    </div>

    <div class="meta">
        <div><span class="label">Source</span><span>{{ str_replace('_', ' ', $receipt->sourceType) }}</span></div>
        <div><span class="label">Purchase order</span><span>{{ $receipt->poRef ?? '—' }}</span></div>
        <div><span class="label">Destination</span><span>{{ $receipt->locationCode }} — {{ $receipt->locationName }}</span></div>
        <div><span class="label">Delivery note</span><span>{{ $receipt->deliveryNoteNumber ?? '—' }}</span></div>
        <div><span class="label">Supplier ref</span><span>{{ $receipt->supplierRef ?? '—' }}</span></div>
        <div><span class="label">Invoice</span><span>{{ $receipt->invoiceNumber ?? '—' }} ({{ str_replace('_', ' ', $receipt->invoiceStatus) }})</span></div>
        @if ($receipt->invoiceDate !== null)
            <div><span class="label">Invoice date</span><span>{{ \Carbon\CarbonImmutable::parse($receipt->invoiceDate)->format('d M Y') }}</span></div>
        @endif
        @if ($receipt->invoiceTotal !== null)
            <div><span class="label">Invoice total</span><span>{{ $receipt->invoiceCurrency }} {{ number_format((float) $receipt->invoiceTotal, 2) }}</span></div>
        @endif
    </div>

    <h2>Accepted and rejected lines</h2>
    <table>
        <thead>
            <tr>
                <th>#</th>
                <th>Item</th>
                <th>PO line</th>
                <th class="num">Delivered</th>
                <th class="num">Rejected</th>
                <th class="num">Accepted</th>
                <th>Batch / expiry</th>
                <th>Condition</th>
                <th>Movement</th>
            </tr>
        </thead>
        <tbody>
            @php
                $qty = static function (string $value): string {
                    $formatted = rtrim(rtrim(number_format((float) $value, 4, '.', ''), '0'), '.');

                    return $formatted === '' ? '0' : $formatted;
                };
            @endphp
            @foreach ($receipt->lines as $index => $line)
                <tr>
                    <td>{{ $index + 1 }}</td>
                    <td><strong>{{ $line->itemCode }}</strong><br>{{ $line->itemName }}</td>
                    <td>{{ $line->poLineRef ?? '—' }}</td>
                    <td class="num">{{ $qty($line->quantityDelivered) }} {{ $line->uom }}</td>
                    <td class="num {{ (float) $line->quantityRejected > 0 ? 'rejected' : '' }}">{{ $qty($line->quantityRejected) }} {{ $line->uom }}</td>
                    <td class="num"><strong>{{ $qty($line->quantityAccepted) }} {{ $line->uom }}</strong></td>
                    <td>{{ $line->batchNumber ?? '—' }}@if ($line->expiryDate !== null)<br>Exp {{ \Carbon\CarbonImmutable::parse($line->expiryDate)->format('d M Y') }}@endif</td>
                    <td>
                        {{ str_replace('_', ' ', $line->condition) }}
                        @if ($line->substitutedForItemId !== null)<br><span class="muted">Substitute for the ordered item</span>@endif
                        @if ($line->conditionNotes !== null)<br><span class="muted">{{ $line->conditionNotes }}</span>@endif
                        @if ($line->rejectionReason !== null)<br><span class="muted">{{ $line->rejectionReason }}</span>@endif
                    </td>
                    <td>{{ $line->movementNumber ?? 'Not posted' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    @if ($receipt->remarks !== null)
        <h2>Remarks</h2>
        <p>{{ $receipt->remarks }}</p>
    @endif

    <div class="signatures">
        <div class="signature">Received by: {{ $receipt->receivedByUserId }}</div>
        <div class="signature">Storekeeper / witness</div>
        <div class="signature">Supplier representative</div>
    </div>

    <div class="footer">
        One delivery = one goods receipt = one GRN. Only accepted quantity posts to stock. Rejected quantity is recorded here and does not move stock.
        Invoice figures are captured for finance reconciliation; this document is not a tax invoice.
    </div>
</body>
</html>

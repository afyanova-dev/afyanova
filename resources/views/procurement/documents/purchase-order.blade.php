<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Purchase Order {{ $order->poNumber }}</title>
    <style>
        @page { margin: 28mm 16mm 22mm; }
        body { color: #161616; font: 10pt DejaVu Sans, sans-serif; }
        h1 { font-size: 22pt; margin: 0 0 4mm; }
        h2 { font-size: 12pt; margin: 8mm 0 3mm; }
        p { margin: 1.5mm 0; }
        .muted { color: #525252; }
        .status { border: 1px solid #525252; display: inline-block; font-weight: bold; padding: 2mm 4mm; }
        .header { border-bottom: 2px solid #161616; margin-bottom: 7mm; padding-bottom: 5mm; }
        .columns { width: 100%; }
        .columns td { vertical-align: top; width: 50%; }
        table.lines { border-collapse: collapse; margin-top: 4mm; width: 100%; }
        .lines th, .lines td { border: 1px solid #a8a8a8; padding: 2.2mm; text-align: left; }
        .lines th { background: #e8e8e8; }
        .number { text-align: right !important; white-space: nowrap; }
        .total { font-size: 12pt; font-weight: bold; margin-top: 4mm; text-align: right; }
        .notes { border: 1px solid #a8a8a8; margin-top: 6mm; padding: 3mm; }
        .footer { border-top: 1px solid #a8a8a8; color: #525252; font-size: 8pt; margin-top: 12mm; padding-top: 3mm; }
    </style>
</head>
<body>
    <div class="header">
        <p class="muted">{{ $facilityName }}</p>
        <h1>Purchase Order</h1>
        <p><strong>{{ $order->poNumber }}</strong> &nbsp; <span class="status">{{ str_replace('_', ' ', $order->status) }}</span></p>
        <p class="muted">Generated {{ $generatedAt }}</p>
    </div>

    <table class="columns">
        <tr>
            <td>
                <h2>Supplier</h2>
                <p><strong>{{ $supplier->name }}</strong></p>
                <p>{{ $supplier->address }}</p>
                <p>{{ $supplier->contactName }}</p>
                <p>{{ $supplier->contactEmail }}</p>
                <p>{{ $supplier->contactPhone }}</p>
                @if ($supplier->registrationNumber)
                    <p>Registration: {{ $supplier->registrationNumber }}</p>
                @endif
            </td>
            <td>
                <h2>Order details</h2>
                <p>Priority: {{ $order->priority }}</p>
                <p>Department: {{ $departmentName ?? 'Facility-wide' }}</p>
                <p>Cost centre: {{ $order->costCentreRef ?? '—' }}</p>
                <p>Budget reference: {{ $order->budgetRef ?? '—' }}</p>
                <p>Source: {{ $order->sourcingMethod ?? 'Direct' }}</p>
                @if ($order->sourceRequisitionNumber)
                    <p>Request reference: {{ $order->sourceRequisitionNumber }}</p>
                @endif
                <p>Approved: {{ $order->approvedAt ?? '—' }}</p>
                <p>Issued: {{ $order->issuedAt ?? '—' }}</p>
                @if ($supplier->paymentTerms)
                    <p>Supplier payment terms: {{ $supplier->paymentTerms }}</p>
                @endif
            </td>
        </tr>
    </table>

    <h2>Order lines</h2>
    <table class="lines">
        <thead>
            <tr>
                <th>Item</th>
                <th>Quantity</th>
                <th class="number">Unit price</th>
                <th class="number">Line total</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($order->lines as $line)
                <tr>
                    <td>
                        {{ $line->itemName ?? $line->description ?? '—' }}
                        @if ($line->itemCode)
                            <br><span class="muted">{{ $line->itemCode }}</span>
                        @endif
                    </td>
                    <td>
                        @if ($line->packSize !== null)
                            {{ $line->orderedQty }} {{ $line->unit }} ({{ $line->orderedBaseQty }} {{ $line->uom }})
                        @else
                            {{ $line->orderedQty }} {{ $line->uom }}
                        @endif
                    </td>
                    <td class="number">{{ $order->currency }} {{ number_format((float) $line->unitPrice, 2) }} / {{ $line->unit }}</td>
                    <td class="number">{{ $order->currency }} {{ number_format((float) $line->lineTotal, 2) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
    <p class="total">Order total: {{ $order->currency }} {{ number_format((float) $order->orderTotal, 2) }}</p>

    @if ($order->notes)
        <div class="notes"><strong>Notes</strong><br>{{ $order->notes }}</div>
    @endif

    <div class="footer">
        This document reflects the purchase order currently recorded in AfyaNova. Verify goods received against this order in Inventory.
    </div>
</body>
</html>

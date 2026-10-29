<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Purchase Request {{ $requisition->requisitionNumber }}</title>
    <style>
        @page { margin: 28mm 16mm 22mm; }
        body { color: #161616; font: 10pt DejaVu Sans, sans-serif; }
        h1 { font-size: 22pt; margin: 0 0 4mm; }
        h2 { font-size: 12pt; margin: 8mm 0 3mm; }
        p { margin: 1.5mm 0; }
        .muted { color: #525252; }
        .status { border: 1px solid #525252; display: inline-block; font-weight: bold; padding: 2mm 4mm; }
        .header { border-bottom: 2px solid #161616; margin-bottom: 7mm; padding-bottom: 5mm; }
        .meta { width: 100%; }
        .meta td { padding: 1.5mm 2mm 1.5mm 0; vertical-align: top; width: 50%; }
        table.lines { border-collapse: collapse; margin-top: 4mm; width: 100%; }
        .lines th, .lines td { border: 1px solid #a8a8a8; padding: 2.2mm; text-align: left; }
        .lines th { background: #e8e8e8; }
        .footer { border-top: 1px solid #a8a8a8; color: #525252; font-size: 8pt; margin-top: 12mm; padding-top: 3mm; }
        .reason { border: 1px solid #a8a8a8; margin-top: 6mm; padding: 3mm; }
    </style>
</head>
<body>
    <div class="header">
        <p class="muted">{{ $facilityName }} · Internal document</p>
        <h1>Purchase Request</h1>
        <p><strong>{{ $requisition->requisitionNumber }}</strong> &nbsp; <span class="status">{{ str_replace('_', ' ', $requisition->status) }}</span></p>
        <p class="muted">Generated {{ $generatedAt }}</p>
    </div>

    <table class="meta">
        <tr>
            <td><strong>Department:</strong> {{ $departmentName ?? 'Facility-wide' }}</td>
            <td><strong>Priority:</strong> {{ $requisition->priority }}</td>
        </tr>
        <tr>
            <td><strong>Raised:</strong> {{ $requisition->createdAt ?? '—' }}</td>
            <td><strong>Emergency:</strong> {{ $requisition->emergency ? 'Yes' : 'No' }}</td>
        </tr>
        <tr>
            <td><strong>Cost centre:</strong> {{ $requisition->costCentreRef ?? '—' }}</td>
            <td><strong>Budget reference:</strong> {{ $requisition->budgetRef ?? '—' }}</td>
        </tr>
        @if ($requisition->reviewedAt)
            <tr>
                <td><strong>Reviewed:</strong> {{ $requisition->reviewedAt }}</td>
                <td><strong>Reviewer user ID:</strong> {{ $requisition->reviewedByUserId ?? '—' }}</td>
            </tr>
        @endif
    </table>

    @if ($requisition->reason)
        <div class="reason"><strong>Purpose</strong><br>{{ $requisition->reason }}</div>
    @endif
    @if ($requisition->rejectionReason)
        <div class="reason"><strong>Rejection reason</strong><br>{{ $requisition->rejectionReason }}</div>
    @endif
    @if ($requisition->emergencyReason)
        <div class="reason"><strong>Emergency reason</strong><br>{{ $requisition->emergencyReason }}</div>
    @endif

    <h2>Requested items</h2>
    <table class="lines">
        <thead>
            <tr>
                <th>Item / description</th>
                <th>Quantity</th>
                <th>Base unit</th>
                <th>Required date</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($requisition->lines as $line)
                <tr>
                    <td>
                        {{ $line->itemName ?? $line->description ?? '—' }}
                        @if ($line->itemCode)
                            <br><span class="muted">{{ $line->itemCode }}</span>
                        @endif
                    </td>
                    <td>
                        @if ($line->packSize !== null)
                            {{ $line->quantity }} {{ $line->unit }} ({{ number_format((float) $line->quantity * (float) $line->packSize, 4) }} {{ $line->uom }})
                        @else
                            {{ $line->quantity }} {{ $line->unit }}
                        @endif
                    </td>
                    <td>{{ $line->uom }}</td>
                    <td>{{ $line->requiredDate ?? '—' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <div class="footer">
        Internal requisition record. This is not a purchase order and does not authorize a supplier to deliver goods.
    </div>
</body>
</html>

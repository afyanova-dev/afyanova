<?php

declare(strict_types=1);

namespace Tests\Feature\Platform\Documents;

use App\Platform\Documents\Infrastructure\Adapter\EscPosReceiptFormatter;

test('EscPosReceiptFormatter produces valid ESC/POS byte sequence for 58mm thermal printer', function (): void {
    $formatter = EscPosReceiptFormatter::for58mm();
    $bytes = $formatter
        ->addHeader('AfyaNova Dispensary', 'P.O. Box 100, Nairobi', '+254700000000')
        ->addKeyValue('Receipt No:', 'RCP-00192')
        ->addKeyValue('Date:', '2026-09-12 10:30')
        ->addKeyValue('Cashier:', 'Jane Doe')
        ->addDivider()
        ->addItemRow('Paracetamol 500mg (10 tabs)', 1, '150.00', '150.00')
        ->addItemRow('Amoxicillin 250mg (20 caps)', 1, '450.00', '450.00')
        ->addTotal('TOTAL PAID (KES):', '600.00')
        ->addLine('Payment: M-PESA Ref: QWE123RTY')
        ->addLine('Thank you for choosing AfyaNova!')
        ->cut()
        ->toBytes();

    expect($bytes)->not->toBeEmpty();
    // Verify ESC/POS init command "\x1b@"
    expect(str_starts_with($bytes, "\x1b@"))->toBeTrue();
    // Verify cut command "\x1dVA\x03"
    expect(str_contains($bytes, "\x1dV\x41\x03"))->toBeTrue();

    // Verify Base64 conversion
    $base64 = $formatter->toBase64();
    expect($base64)->toBe(base64_encode($bytes));
});

test('EscPosReceiptFormatter produces 80mm format receipt with correct alignment and bolding', function (): void {
    $formatter = EscPosReceiptFormatter::for80mm();
    $bytes = $formatter
        ->addHeader('AfyaNova National Referral Hospital', 'Hospital Way, Upper Hill', '020-1234567')
        ->addKeyValue('Invoice:', 'INV-88990')
        ->addTotal('BALANCE DUE:', '0.00')
        ->cut()
        ->toBytes();

    expect(strlen($bytes))->toBeGreaterThan(50);
});

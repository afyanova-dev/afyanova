<?php

declare(strict_types=1);

namespace App\Domains\Billing\Infrastructure\Reader;

use App\Domains\Billing\Contracts\Dto\ReceiptSummary;
use App\Domains\Billing\Domain\Exception\ReceiptNotFound;
use App\Domains\Billing\Domain\Model\Receipt;
use App\Domains\Billing\Domain\Money;
use App\Domains\Billing\Domain\ReceiptReader;

/**
 * Eloquent implementation of {@see ReceiptReader} (IC §6.2; ADR-191 B5-C).
 */
final class EloquentReceiptReader implements ReceiptReader
{
    public function forPayment(string $paymentId): ReceiptSummary
    {
        $receipt = Receipt::query()->where('payment_id', $paymentId)->first()
            ?? throw ReceiptNotFound::forPayment($paymentId);

        return new ReceiptSummary(
            receiptId: (string) $receipt->id,
            receiptNumber: (string) $receipt->receipt_number,
            paymentId: (string) $receipt->payment_id,
            financialAccountId: (string) $receipt->financial_account_id,
            facilityId: (string) $receipt->facility_id,
            patientId: (string) $receipt->patient_id,
            method: (string) $receipt->method,
            amount: Money::of((string) $receipt->amount),
            currency: (string) $receipt->currency,
            status: (string) $receipt->status,
            issuedAt: $receipt->issued_at->toIso8601String(),
            issuedByUserId: (string) $receipt->issued_by_user_id,
            reversedAt: $receipt->reversed_at?->toIso8601String(),
        );
    }
}

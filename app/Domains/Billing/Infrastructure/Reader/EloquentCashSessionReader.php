<?php

declare(strict_types=1);

namespace App\Domains\Billing\Infrastructure\Reader;

use App\Domains\Billing\Contracts\Dto\CashSessionSummary;
use App\Domains\Billing\Domain\CashSessionReader;
use App\Domains\Billing\Domain\Exception\CashSessionNotFound;
use App\Domains\Billing\Domain\Model\CashSession;
use App\Domains\Billing\Domain\Model\Payment;
use App\Domains\Billing\Domain\Money;
use App\Domains\Billing\Domain\ValueObject\CashSessionStatus;
use App\Domains\Billing\Domain\ValueObject\PaymentMethod;
use App\Domains\Billing\Domain\ValueObject\PaymentStatus;

/**
 * Eloquent implementation of {@see CashSessionReader} (IC §6.2; ADR-191 C6 / G-4).
 */
final class EloquentCashSessionReader implements CashSessionReader
{
    public function summary(string $cashSessionId): CashSessionSummary
    {
        $session = CashSession::query()->whereKey($cashSessionId)->first()
            ?? throw CashSessionNotFound::id($cashSessionId);

        return $this->toSummary($session);
    }

    public function forFacility(string $facilityId, ?string $status): array
    {
        return array_values(
            CashSession::query()
                ->where('facility_id', $facilityId)
                ->when($status !== null, fn ($q) => $q->where('status', $status))
                ->orderByDesc('recorded_at')->orderByDesc('id')
                ->get()
                ->map(fn (CashSession $s): CashSessionSummary => $this->toSummary($s))
                ->all()
        );
    }

    private function toSummary(CashSession $session): CashSessionSummary
    {
        $live = in_array($session->status, CashSessionStatus::LIVE, true);

        /** @var array<string, string> $byMethod */
        $byMethod = [];
        $cashTotal = '0';
        foreach (
            Payment::query()
                ->where('cash_session_id', $session->id)
                ->where('status', PaymentStatus::RECORDED)
                ->get(['method', 'amount']) as $payment
        ) {
            $method = (string) $payment->method;
            $amount = Money::of((string) $payment->amount);
            if ($method === PaymentMethod::CASH) {
                $cashTotal = Money::add($cashTotal, $amount);
            } else {
                $byMethod[$method] = Money::add($byMethod[$method] ?? '0', $amount);
            }
        }

        $openingFloat = Money::of((string) $session->opening_float);
        $liveExpected = Money::add($openingFloat, $cashTotal);

        $expectedCash = $live ? $liveExpected : Money::of((string) $session->expected_cash);
        $variance = $live
            ? ($session->counted_cash !== null ? Money::subtract(Money::of((string) $session->counted_cash), $liveExpected) : null)
            : ($session->variance !== null ? Money::of((string) $session->variance) : null);

        return new CashSessionSummary(
            cashSessionId: (string) $session->id,
            facilityId: (string) $session->facility_id,
            cashierUserId: (string) $session->cashier_user_id,
            openingFloat: $openingFloat,
            currency: (string) $session->currency,
            status: (string) $session->status,
            cashPaymentsTotal: Money::of($cashTotal),
            expectedCash: $expectedCash,
            countedCash: $session->counted_cash !== null ? Money::of((string) $session->counted_cash) : null,
            variance: $variance,
            nonCashByMethod: $byMethod,
            openedAt: $session->opened_at->toIso8601String(),
            openedByUserId: (string) $session->opened_by_user_id,
            closedAt: $session->closed_at?->toIso8601String(),
            closedByUserId: $session->closed_by_user_id !== null ? (string) $session->closed_by_user_id : null,
            closeNote: $session->close_note,
            reconciledAt: $session->reconciled_at?->toIso8601String(),
            reconciledByUserId: $session->reconciled_by_user_id !== null ? (string) $session->reconciled_by_user_id : null,
            varianceApprovedByUserId: $session->variance_approved_by_user_id !== null ? (string) $session->variance_approved_by_user_id : null,
            varianceNote: $session->variance_note,
        );
    }
}

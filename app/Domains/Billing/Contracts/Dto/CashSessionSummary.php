<?php

declare(strict_types=1);

namespace App\Domains\Billing\Contracts\Dto;

/**
 * One cash session (20-billing-and-payments-domain.md §153–§157; ADR-191 C6 /
 * G-4). Returned by `GetCashSession` / `GetFacilityCashSessions` via
 * `CashSessionReader`. NOT `#[TypeScript]`.
 *
 * `expectedCash` = `openingFloat + Σ(CASH payments attributed to the session)` —
 * live while OPEN / ACTIVE, the frozen row value once CLOSED. `nonCashByMethod`
 * (method → total) is the per-method panel — attributed but EXCLUDED from the
 * cash count (G-4). All money fields are scale-2 numeric strings.
 */
final readonly class CashSessionSummary
{
    /**
     * @param  array<string, string>  $nonCashByMethod
     */
    public function __construct(
        public string $cashSessionId,
        public string $facilityId,
        public string $cashierUserId,
        public string $openingFloat,
        public string $currency,
        public string $status,
        public string $cashPaymentsTotal,
        public string $expectedCash,
        public ?string $countedCash,
        public ?string $variance,
        public array $nonCashByMethod,
        public string $openedAt,
        public string $openedByUserId,
        public ?string $closedAt,
        public ?string $closedByUserId,
        public ?string $closeNote,
        public ?string $reconciledAt,
        public ?string $reconciledByUserId,
        public ?string $varianceApprovedByUserId,
        public ?string $varianceNote,
    ) {}
}

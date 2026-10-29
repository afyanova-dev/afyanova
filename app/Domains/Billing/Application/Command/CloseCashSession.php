<?php

declare(strict_types=1);

namespace App\Domains\Billing\Application\Command;

use App\Domains\Billing\Domain\Money;
use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Close a cash session — the cashier counts the drawer and enters
 * `countedCash` (20-billing-and-payments-domain.md §155 / §156; ADR-191 C6-B).
 * Per-subject `manage`. Freezes `expected_cash` / `counted_cash` / `variance`
 * (`counted − expected`) on the row. Not idempotent — a second close is an
 * `IllegalCashSessionTransition`.
 */
final readonly class CloseCashSession implements Auditable, AuthorizesAgainst
{
    /** Validated + normalised to a scale-2 numeric string via {@see Money::of()}. */
    public string $countedCash;

    public function __construct(
        public string $actorId,
        public string $cashSessionId,
        string $countedCash,
        public ?string $note = null,
        public ?string $idempotencyKey = null,
    ) {
        if (! Str::isUuid($actorId) || ! Str::isUuid($cashSessionId)) {
            throw new InvalidArgumentException('CloseCashSession ids must be UUIDs (IC §3).');
        }

        if (! is_numeric($countedCash) || bccomp(Money::of($countedCash), '0', 2) < 0) {
            throw new InvalidArgumentException('CloseCashSession::$countedCash must be a non-negative money value (§155).');
        }

        $this->countedCash = Money::of($countedCash);
    }

    public function policyAbility(): string
    {
        return 'manage';
    }

    public function subjectId(): string
    {
        return $this->cashSessionId;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'billing.cash_session_closed',
            actorId: $this->actorId,
            targetType: 'cash_session',
            targetId: $this->cashSessionId,
            metadata: ['counted_cash' => $this->countedCash],
        );
    }
}

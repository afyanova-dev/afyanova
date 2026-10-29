<?php

declare(strict_types=1);

namespace App\Domains\Billing\Application\Command;

use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Reconcile a closed cash session — a supervisor reviews the variance
 * (20-billing-and-payments-domain.md §157; ADR-191 C6-C). Per-subject
 * `reconcile` (`billing.cash_sessions.reconcile` — a DISTINCT key from
 * `.manage`, §165). CLOSED → RECONCILED. A NON-ZERO variance may NOT be
 * reconciled by the session's own cashier (`SelfReconcileNotPermitted`); a
 * zero variance any `.reconcile` holder may clear. Idempotent on RECONCILED.
 */
final readonly class ReconcileCashSession implements Auditable, AuthorizesAgainst
{
    public function __construct(
        public string $actorId,
        public string $cashSessionId,
        public ?string $varianceNote = null,
        public ?string $idempotencyKey = null,
    ) {
        if (! Str::isUuid($actorId) || ! Str::isUuid($cashSessionId)) {
            throw new InvalidArgumentException('ReconcileCashSession ids must be UUIDs (IC §3).');
        }
    }

    public function policyAbility(): string
    {
        return 'reconcile';
    }

    public function subjectId(): string
    {
        return $this->cashSessionId;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'billing.cash_session_reconciled',
            actorId: $this->actorId,
            targetType: 'cash_session',
            targetId: $this->cashSessionId,
        );
    }
}

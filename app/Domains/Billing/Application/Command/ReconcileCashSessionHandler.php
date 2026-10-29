<?php

declare(strict_types=1);

namespace App\Domains\Billing\Application\Command;

use App\Domains\Billing\Domain\CashSessionRepository;
use App\Domains\Billing\Domain\Exception\SelfReconcileNotPermitted;
use App\Domains\Billing\Domain\Money;

/**
 * Orchestrates `ReconcileCashSession` (ADR-191 C6-C; §157 / §165).
 *
 * `getByIdForUpdate` → idempotent no-op on RECONCILED → CLOSED required
 * (`CashSession::reconcile` asserts) → a NON-ZERO variance may not be
 * reconciled by its own cashier (`SelfReconcileNotPermitted` — the Procurement
 * self-approval precedent); the actor is the §157 approver of a non-zero
 * variance. The variance is recorded ONLY here — NEVER on the account ledger
 * (C6-C).
 */
final class ReconcileCashSessionHandler
{
    public function __construct(private readonly CashSessionRepository $sessions) {}

    public function __invoke(ReconcileCashSession $command): void
    {
        $session = $this->sessions->getByIdForUpdate($command->cashSessionId);

        if ($session->status === $session::STATUS_RECONCILED) {
            return;
        }

        $varianceIsNonZero = $session->variance !== null && ! Money::isZero((string) $session->variance);

        if ($varianceIsNonZero && (string) $session->cashier_user_id === $command->actorId) {
            throw SelfReconcileNotPermitted::session($command->cashSessionId);
        }

        $session->reconcile(
            reconciledByUserId: $command->actorId,
            varianceApprovedByUserId: $varianceIsNonZero ? $command->actorId : null,
            varianceNote: $command->varianceNote,
        );

        $this->sessions->save($session);
    }
}

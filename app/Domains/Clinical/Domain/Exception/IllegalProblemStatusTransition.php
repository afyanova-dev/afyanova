<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * A `ResolveProblem` was dispatched against a problem it cannot legally
 * transition (12-clinical-domain.md §74; ADR-140 D-E — the ONLY lifecycle
 * transition is `ACTIVE → RESOLVED`; the `IllegalEncounterTransition` /
 * `IllegalPatientStatusTransition` precedent). Validated in the `Problem`
 * aggregate before any mutation. Maps to HTTP 409 (IC §15).
 *
 *   - `ResolveProblem` — legal only from `ACTIVE`. A `RESOLVED` problem is not
 *                        re-resolved (not idempotent) and there is no
 *                        `ReactivateProblem`; `RESOLVED` is terminal for the
 *                        lifecycle transition (but `UpdateProblem` may still
 *                        correct a resolved problem's clinical content —
 *                        ADR-140 clarification lock, Decision 1).
 */
final class IllegalProblemStatusTransition extends DomainException
{
    public static function resolve(string $current): self
    {
        return new self(sprintf(
            'ResolveProblem is not a legal transition from status %s. Resolution requires ACTIVE.',
            $current,
        ));
    }

    public function errorCode(): string
    {
        return 'illegal_problem_status_transition';
    }
}

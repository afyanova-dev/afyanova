<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Domain;

use App\Domains\Clinical\Domain\Model\PrescribingDecision;
use App\Shared\Domain\Repository;

/**
 * Write-side port for the PrescribingDecision aggregate (IC §9; ADR-143).
 * Implementation: `Infrastructure/Repository/EloquentPrescribingDecisionRepository`,
 * bound in `ClinicalServiceProvider`.
 */
interface PrescribingDecisionRepository extends Repository
{
    public function getById(string $id): PrescribingDecision;

    public function getByIdForUpdate(string $id): PrescribingDecision;

    /**
     * Persist a correction (ADR-143 D-J): the NEW (superseding) decision is
     * inserted, then the ORIGINAL row is stamped with its one-time supersession
     * metadata (a versioned UPDATE) — all in the bus transaction, with the
     * `PrescriptionIssued` event drained to the outbox.
     */
    public function saveCorrection(PrescribingDecision $original, PrescribingDecision $correction): void;
}

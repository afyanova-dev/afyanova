<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Domain;

use App\Domains\Clinical\Domain\Model\VitalSignsObservation;
use App\Shared\Domain\Repository;

/**
 * Write-side port for the VitalSignsObservation aggregate (IC §9; ADR-138).
 * Implementation: `Infrastructure/Repository/EloquentVitalSignsObservationRepository`,
 * bound in `ClinicalServiceProvider`. Its FQCN sits directly under `Domain/`
 * (not `Domain/Repository/`) so `App\Shared\Bus\Middleware\AuthorizeCommand`
 * (IC §5.1) can derive `App\Domains\Clinical\Domain\Model\VitalSignsObservation`
 * from it for the class-level `record` and per-subject `correct` policy checks.
 */
interface VitalSignsObservationRepository extends Repository
{
    public function getById(string $id): VitalSignsObservation;

    public function getByIdForUpdate(string $id): VitalSignsObservation;

    /**
     * Persist a correction (ADR-138 D7): the NEW (superseding) observation is
     * inserted, then the ORIGINAL row is stamped with its one-time supersession
     * metadata (a versioned UPDATE) — all in the bus transaction, with the
     * `VitalSignsObservationCorrected` event drained to the outbox.
     */
    public function saveCorrection(VitalSignsObservation $original, VitalSignsObservation $correction): void;
}

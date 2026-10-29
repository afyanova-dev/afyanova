<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * `CorrectVitalSignsObservation` was attempted against an observation that has
 * already been superseded by a prior correction (12-clinical-domain.md §94;
 * ADR-138 — the supersession metadata is a ONE-TIME mutation).
 *
 * Corrections chain forward: to further correct, supersede the CURRENT
 * (non-superseded) observation in the chain, not an already-superseded one.
 * Maps to HTTP 409 (IC §15).
 */
final class VitalSignsObservationAlreadySuperseded extends DomainException
{
    public static function for(string $observationId, string $supersededByObservationId): self
    {
        return new self(sprintf(
            'VitalSignsObservation [%s] was already superseded by [%s] — correct the current observation in the chain.',
            $observationId,
            $supersededByObservationId,
        ));
    }

    public function errorCode(): string
    {
        return 'vital_signs_observation_already_superseded';
    }
}

<?php

declare(strict_types=1);

namespace App\Domains\CareCoordination\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * `OpenCareEpisode` was attempted for a `(type, subtype)` the patient already
 * has an OPEN episode of, without `allowConcurrent` (Invariant 7.4; ADR-153
 * D-D/D-J — the `care_episodes_one_open_per_type` partial unique index).
 * Maps to HTTP 409.
 */
final class ConcurrentCareEpisodeExists extends DomainException
{
    public static function forType(string $patientId, string $type, ?string $subtype): self
    {
        return new self(sprintf(
            'Patient [%s] already has an OPEN care episode of type [%s%s] — pass allowConcurrent with a reason to open a second.',
            $patientId,
            $type,
            $subtype !== null ? '/'.$subtype : '',
        ));
    }

    public function errorCode(): string
    {
        return 'concurrent_care_episode_exists';
    }
}

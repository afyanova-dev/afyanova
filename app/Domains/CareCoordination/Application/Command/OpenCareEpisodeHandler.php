<?php

declare(strict_types=1);

namespace App\Domains\CareCoordination\Application\Command;

use App\Domains\CareCoordination\Domain\CareEpisodeRepository;
use App\Domains\CareCoordination\Domain\Exception\ConcurrentCareEpisodeExists;
use App\Domains\CareCoordination\Domain\Exception\PatientNotEligibleForCareEpisode;
use App\Domains\CareCoordination\Domain\Model\CareEpisode;
use App\Domains\Patient\Contracts\Dto\PatientSummary;
use App\Domains\Patient\Contracts\PatientApi;
use App\Shared\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;

/**
 * Orchestrates `OpenCareEpisode` (ADR-153 D-E/D-I/D-J).
 *
 * Flow: `PatientApi::getPatientSummary` patient-state gate (ACTIVE, incl.
 * provisional) -> `facility_id` = the caller's active facility context
 * (ADR-132 originating convention) -> `override_group` = NULL for a default
 * open, a fresh uuidv7 when `allowConcurrent` -> create OPEN + `CareEpisodeOpened`
 * -> versioned save; a collision on the §7.4 partial unique index (no override)
 * surfaces as `UniqueConstraintViolationException`, translated to
 * `ConcurrentCareEpisodeExists` (409 — the AssignBed precedent).
 */
final class OpenCareEpisodeHandler
{
    public function __construct(
        private readonly CareEpisodeRepository $episodes,
        private readonly PatientApi $patients,
        private readonly TenantContext $context,
    ) {}

    public function __invoke(OpenCareEpisode $command): CareEpisode
    {
        $this->assertPatientEligible($this->patients->getPatientSummary($command->patientId));

        $type = strtoupper(trim($command->type));
        $subtype = $command->subtype !== null ? trim($command->subtype) : null;

        $now = CarbonImmutable::now();
        $occurredAt = $command->occurredAt !== null ? CarbonImmutable::parse($command->occurredAt) : $now;

        $episode = CareEpisode::open(
            id: $command->episodeId,
            tenantId: $this->context->tenantId(),
            facilityId: $this->context->requireFacilityId(),
            patientId: $command->patientId,
            type: $type,
            subtype: $subtype,
            overrideGroup: $command->allowConcurrent ? (string) Str::uuid7() : null,
            override: $command->allowConcurrent,
            createdByUserId: $command->actorId,
            occurredAt: $occurredAt,
        );

        try {
            $this->episodes->save($episode);
        } catch (UniqueConstraintViolationException $e) {
            if (str_contains($e->getMessage(), 'care_episodes_one_open_per_type')) {
                throw ConcurrentCareEpisodeExists::forType($command->patientId, $type, $subtype);
            }

            throw $e;
        }

        return $episode;
    }

    private function assertPatientEligible(PatientSummary $patient): void
    {
        if ($patient->status === 'MERGED') {
            throw PatientNotEligibleForCareEpisode::merged($patient->patientId);
        }

        if ($patient->status === 'DECEASED') {
            throw PatientNotEligibleForCareEpisode::deceased($patient->patientId);
        }

        if ($patient->status === 'INACTIVE') {
            throw PatientNotEligibleForCareEpisode::inactive($patient->patientId);
        }
    }
}

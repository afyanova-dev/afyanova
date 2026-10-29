<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Contracts\Dto;

/**
 * Minimal, synchronous, backend read of one vitals observation
 * (12-clinical-domain.md §164; ADR-138 D15). Returned by the
 * `GetVitalSignsObservation` query via `VitalSignsObservationReader::summary()`.
 *
 * NOT an event payload (events carry no measurement values — ADR-136). NOT
 * `#[TypeScript]` — there is no PHASE 6 frontend consumer (ADR-131 — backend
 * only).
 *
 * The measurement values ARE returned to an authorized reader: the
 * `#[ProtectedQuery]` gate on `GetVitalSignsObservation` plus the ADR-133
 * per-observation-facility refinement IS the authorization (the E.12 /
 * `PatientSummary::$dateOfBirth` precedent).
 *
 * Identifiers + the observation's own stored fields ONLY: no resolved patient
 * name / demographics, no encounter detail, no other clinical entities. BMI is
 * derived, never stored, and is not returned here — a consumer computes it from
 * `weight` + `height` if needed. Fixed canonical units (°C, bpm, breaths/min,
 * mmHg, %, kg, cm, 0–10 NRS — ADR-138 D3); times are ISO-8601 strings.
 *
 * The supersession fields describe this observation's place in a correction
 * chain (ADR-138 D7): `supersedesObservationId` is set when THIS row is itself
 * a correction; `supersededAt` / `supersededByObservationId` are set once THIS
 * row has been corrected by a later observation; `correctionReason` is the
 * clinical rationale recorded on a correction row.
 */
final readonly class VitalSignsObservationSummary
{
    public function __construct(
        public string $vitalSignsObservationId,
        public string $patientId,
        public string $encounterId,
        public string $facilityId,
        public string $authorUserId,
        public ?string $authoredContext,
        public ?float $temperature,
        public ?int $heartRate,
        public ?int $respiratoryRate,
        public ?int $systolicBp,
        public ?int $diastolicBp,
        public ?int $oxygenSaturation,
        public ?float $weight,
        public ?float $height,
        public ?int $painScore,
        public string $occurredAt,
        public string $recordedAt,
        public ?string $supersedesObservationId,
        public ?string $supersededAt,
        public ?string $supersededByObservationId,
        public ?string $correctionReason,
    ) {}
}

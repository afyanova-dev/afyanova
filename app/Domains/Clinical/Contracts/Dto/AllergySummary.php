<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Contracts\Dto;

/**
 * Minimal, synchronous, backend read of one allergy assertion
 * (12-clinical-domain.md §164; ADR-141 D-N). Returned by the `GetAllergy` query
 * and, as a `list<AllergySummary>`, by `GetPatientAllergies` — both via
 * `AllergyReader`.
 *
 * NOT an event payload (events carry no substance name / code, no reaction
 * text, no severity — ADR-136 / ADR-141 D-Q). NOT `#[TypeScript]` — there is no
 * PHASE 6 frontend consumer (ADR-141 D-N); `resources/js/shared/types/generated.ts`
 * is unchanged.
 *
 * The `substanceName` and `reactionDescription` ARE returned to an authorized
 * reader: the `#[ProtectedQuery]` gate on `GetAllergy` / `GetPatientAllergies`
 * (`AllergyPolicy::view` — tenant + `clinical.allergies.read`) IS the
 * authorization (ADR-141 D-M — reads are tenant-wide; the E.12 /
 * `PatientSummary::$dateOfBirth` / `ProblemSummary` precedent).
 *
 * Identifiers + the allergy's own stored fields ONLY — no resolved patient name
 * / demographics, no encounter detail, no history rows. `occurredAt` /
 * `recordedAt` / `resolvedAt` are ISO-8601 strings; `onsetDate` is a `Y-m-d`
 * date string. `clinicalStatus` is an `AllergyStatus` value
 * (`ACTIVE` | `RESOLVED` | `INACTIVE`); `verificationStatus` is an
 * `AllergyVerificationStatus` value (`SUSPECTED` | `CONFIRMED` | `REFUTED`);
 * `REFUTED` always coincides with `clinicalStatus = INACTIVE` (ADR-141 D-D).
 */
final readonly class AllergySummary
{
    public function __construct(
        public string $allergyId,
        public string $patientId,
        public string $encounterId,
        public string $facilityId,
        public string $authorUserId,
        public ?string $authoredContext,
        public string $category,
        public string $substanceName,
        public ?string $substanceCode,
        public ?string $substanceCodeSystem,
        public ?string $reactionDescription,
        public ?string $severity,
        public string $clinicalStatus,
        public string $verificationStatus,
        public ?string $onsetDate,
        public ?string $resolvedAt,
        public string $occurredAt,
        public string $recordedAt,
    ) {}
}

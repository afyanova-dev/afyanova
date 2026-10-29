<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Contracts\Dto;

/**
 * Minimal, synchronous, backend read of one clinical note (12-clinical-domain.md
 * §164; ADR-134; C.1). Returned by the `GetClinicalNote` query via
 * `ClinicalNoteReader::summary()`.
 *
 * NOT an event payload — domain events carry only identifiers and coded values
 * (IC §7.1.1). NOT `#[TypeScript]` — there is no PHASE 6 frontend consumer
 * (ADR-131 — backend only).
 *
 * `body` (and `title`) ARE returned to an authorized reader: the
 * `#[ProtectedQuery]` gate on `GetClinicalNote` plus the ADR-133 per-encounter-
 * facility refinement IS the authorization (the `PatientSummary::$dateOfBirth`
 * precedent — the query's protection level is the field-level condition).
 *
 * Identifiers + the note's own owned fields ONLY: no resolved patient name /
 * demographics, no encounter detail, no other clinical entities (vitals,
 * diagnoses, …), no financial data. `patientId` / `encounterId` are the
 * references; resolving them is a PHASE 16 composed-read-model concern.
 *
 * `amendmentCount > 0` together with `status === 'SIGNED'` is the DERIVED
 * `AMENDED` signal (ADR-134). `signedAt` / `signedByUserId` are null until
 * `SignClinicalNote`. Times are ISO-8601 strings.
 */
final readonly class ClinicalNoteSummary
{
    public function __construct(
        public string $clinicalNoteId,
        public string $patientId,
        public string $encounterId,
        public string $facilityId,
        public string $authorUserId,
        public ?string $authoredContext,
        public string $noteType,
        public ?string $title,
        public string $body,
        public string $status,
        public string $occurredAt,
        public string $recordedAt,
        public ?string $signedAt,
        public ?string $signedByUserId,
        public int $amendmentCount,
    ) {}
}

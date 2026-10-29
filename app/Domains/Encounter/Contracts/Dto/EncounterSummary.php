<?php

declare(strict_types=1);

namespace App\Domains\Encounter\Contracts\Dto;

/**
 * Minimal, synchronous, backend read of one encounter's identity + lifecycle
 * state (11-encounter-domain.md §99; ADR-124, ADR-130; E.12). Returned by the
 * `GetEncounter` query via `EncounterReader::summary()`.
 *
 * NOT an event payload — domain events carry only identifiers and coded values
 * (IC §7.1.1); this DTO is a display-side read, never reused as an event
 * contract. NOT `#[TypeScript]` — there is no PHASE 5 frontend consumer
 * (ADR-124).
 *
 * Identifiers + coded / lifecycle values only (§98 — encounter visibility does
 * not imply access to clinical data): no patient name / demographics, no
 * clinical, procedural, diagnostic, medication or financial data, no queue /
 * bed / room / care-team information. `patientId` is the reference; resolving a
 * patient's name is a PHASE 16 composed-read-model concern, and
 * `GetEncounterHandler` makes no `PatientApi` call.
 *
 * Nullable fields are null exactly where the encounter lifecycle permits
 * (ADR-121, ADR-130): `disposition` until `ChangeEncounterDisposition` /
 * `AdmitEncounter` / `TransferEncounter`; `departmentId` / `unitId` until
 * `AssignEncounterLocation` (or a `CreateEncounter` that supplied one);
 * `arrivedAt` on a still-`PLANNED` encounter; `startedAt` until `StartEncounter`;
 * `admittedAt` until `AdmitEncounter`; `completedAt` until the first
 * `CompleteEncounter`; `createdSource` when none was supplied at creation.
 */
final readonly class EncounterSummary
{
    public function __construct(
        public string $encounterId,
        public string $encounterNumber,
        public string $patientId,
        public string $facilityId,
        public string $encounterType,
        public string $status,
        public ?string $disposition,
        public ?string $departmentId,
        public ?string $unitId,
        public ?string $arrivedAt,
        public ?string $startedAt,
        public ?string $admittedAt,
        public ?string $completedAt,
        public ?string $createdSource,
    ) {}
}

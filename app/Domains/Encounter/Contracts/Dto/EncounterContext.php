<?php

declare(strict_types=1);

namespace App\Domains\Encounter\Contracts\Dto;

/**
 * The minimal encounter context a cross-domain caller needs to bind a record to
 * an encounter and enforce the encounter-side gates (ADR-135; E.13). Returned
 * by `EncounterApi::getEncounterContext`.
 *
 * Exactly five fields — the identity link (`encounterId`, `patientId`,
 * `facilityId`) plus the two coded lifecycle values a consumer gates on
 * (`status`, `encounterType`). NOT a superset of `EncounterSummary` — it is the
 * subset ADR-135 ratified, nothing more (no numbers, timestamps, disposition or
 * location).
 *
 * NOT `#[TypeScript]` (no PHASE 5/6 frontend consumer, ADR-124). NOT an event
 * payload. `getEncounterContext` delegates to the E.12 `GetEncounter` query, so
 * this DTO is only ever produced for a caller that passed that query's
 * `#[ProtectedQuery]` gate + facility refinement — an absent / cross-tenant /
 * out-of-scope encounter surfaces as `NotFound`, never a partial context.
 */
final readonly class EncounterContext
{
    public function __construct(
        public string $encounterId,
        public string $patientId,
        public string $facilityId,
        public string $status,        // EncounterStatus value
        public string $encounterType, // EncounterType value
    ) {}
}

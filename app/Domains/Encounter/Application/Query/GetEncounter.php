<?php

declare(strict_types=1);

namespace App\Domains\Encounter\Application\Query;

use App\Domains\Encounter\Domain\Model\Encounter;
use App\Shared\Bus\ProtectedQuery;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Read one encounter's summary by id (11-encounter-domain.md §99, §102; ADR-124,
 * ADR-130 — "GetEncounter is built for the cross-domain test / EncounterApi
 * need"). Protected (IC §6.2): encounter metadata is itself sensitive (§98) and
 * facility-scoped (§114, Invariant 24/25) — not a bare existence check.
 *
 * The `#[ProtectedQuery]` gate is the COARSE tenant + `encounter.encounters.read`
 * check (`EncounterPolicy::view`, class-level — `App\Shared\Bus\Middleware\
 * AuthorizeQuery` never loads a subject). `GetEncounterHandler` applies the
 * per-encounter facility refinement (R-1): an encounter at a facility outside
 * the caller's scope resolves as `NotFound`, never an authorization disclosure.
 *
 * `$encounterId` is the whole payload — no facility / tenant input (they come
 * from `TenantContext` / the caller's scope, never the query — Invariant 12).
 * No side effects: no event, no audit (R-3), no state mutation.
 */
#[ProtectedQuery(ability: 'view', subjectType: Encounter::class)]
final readonly class GetEncounter
{
    public function __construct(public string $encounterId)
    {
        if (! Str::isUuid($encounterId)) {
            throw new InvalidArgumentException('GetEncounter::$encounterId must be a UUID (IC §3).');
        }
    }
}

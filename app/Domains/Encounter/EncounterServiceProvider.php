<?php

declare(strict_types=1);

namespace App\Domains\Encounter;

use App\Domains\Encounter\Application\Command\AbandonEncounter;
use App\Domains\Encounter\Application\Command\AbandonEncounterHandler;
use App\Domains\Encounter\Application\Command\AdmitEncounter;
use App\Domains\Encounter\Application\Command\AdmitEncounterHandler;
use App\Domains\Encounter\Application\Command\AssignEncounterLocation;
use App\Domains\Encounter\Application\Command\AssignEncounterLocationHandler;
use App\Domains\Encounter\Application\Command\CancelEncounter;
use App\Domains\Encounter\Application\Command\CancelEncounterHandler;
use App\Domains\Encounter\Application\Command\ChangeEncounterDisposition;
use App\Domains\Encounter\Application\Command\ChangeEncounterDispositionHandler;
use App\Domains\Encounter\Application\Command\CompleteEncounter;
use App\Domains\Encounter\Application\Command\CompleteEncounterHandler;
use App\Domains\Encounter\Application\Command\CreateEncounter;
use App\Domains\Encounter\Application\Command\CreateEncounterHandler;
use App\Domains\Encounter\Application\Command\RecordArrival;
use App\Domains\Encounter\Application\Command\RecordArrivalHandler;
use App\Domains\Encounter\Application\Command\RecordEncounterRelationship;
use App\Domains\Encounter\Application\Command\RecordEncounterRelationshipHandler;
use App\Domains\Encounter\Application\Command\ReopenEncounter;
use App\Domains\Encounter\Application\Command\ReopenEncounterHandler;
use App\Domains\Encounter\Application\Command\StartEncounter;
use App\Domains\Encounter\Application\Command\StartEncounterHandler;
use App\Domains\Encounter\Application\Command\TransferEncounter;
use App\Domains\Encounter\Application\Command\TransferEncounterHandler;
use App\Domains\Encounter\Application\EncounterApiService;
use App\Domains\Encounter\Application\Query\CountFacilityEncounters;
use App\Domains\Encounter\Application\Query\CountFacilityEncountersHandler;
use App\Domains\Encounter\Application\Query\GetEncounter;
use App\Domains\Encounter\Application\Query\GetEncounterHandler;
use App\Domains\Encounter\Application\Query\GetPatientEncounters;
use App\Domains\Encounter\Application\Query\GetPatientEncountersHandler;
use App\Domains\Encounter\Application\Query\ListFacilityEncounters;
use App\Domains\Encounter\Application\Query\ListFacilityEncountersHandler;
use App\Domains\Encounter\Authorization\EncounterPolicy;
use App\Domains\Encounter\Contracts\EncounterApi;
use App\Domains\Encounter\Domain\EncounterReader;
use App\Domains\Encounter\Domain\EncounterRepository;
use App\Domains\Encounter\Domain\Model\Encounter;
use App\Domains\Encounter\Infrastructure\Reader\EloquentEncounterReader;
use App\Domains\Encounter\Infrastructure\Repository\EloquentEncounterRepository;
use App\Shared\Authorization\PermissionRegistry;
use App\Shared\Bus\CommandBus;
use App\Shared\Bus\QueryBus;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Encounter module (docs/01-domains/11-encounter-domain.md; ADR-101, ADR-104,
 * ADR-108, ADR-115, ADR-120, ADR-121, ADR-124, ADR-130), prefix `enc_`.
 *
 * PHASE 5 is backend / domain contract only (ADR-124): no Presentation/Http, no
 * routes.php, no encounter board, no composed read model — the Reception
 * workspace is PHASE 16.
 *
 * Increment E.1: the `Encounter` aggregate + `CreateEncounter` end-to-end (the
 * authoritative check-in — ADR-104), `enc_encounters` +
 * `enc_encounter_number_counters`, `EncounterType` / `EncounterStatus` /
 * `EncounterDisposition` enums, `EncounterLifecycle` +
 * `EncounterNumberSequence`, and the `EncounterApi` port (`encounterExists`).
 * Increment E.2: `RecordArrival` (`PLANNED → ARRIVED`; ADR-121) — the
 * `EncounterLifecycle` transition guard, `EncounterArrived` event,
 * `encounter.encounters.update` key.
 * Increment E.3: `StartEncounter` (`ARRIVED → ACTIVE`; ADR-121) —
 * `EncounterLifecycle::assertCanStart`, `EncounterStarted` event; reuses the
 * `encounter.encounters.update` key.
 * Increment E.4: `AdmitEncounter` (in-place `OUTPATIENT`/`EMERGENCY → INPATIENT`,
 * status stays `ACTIVE`; ADR-108/120/121/130) — `EncounterLifecycle::assertCanAdmit`,
 * `INPATIENT_CARE` capability gate, `EncounterAdmitted` event,
 * `encounter.encounters.admit` key.
 * Increment E.5: `ChangeEncounterDisposition` (`ACTIVE → ACTIVE`, disposition
 * set / revised; ADR-121/130 A) — `EncounterLifecycle::assertCanChangeDisposition`
 * (settable values `DISCHARGED`/`REFERRED`/`LEFT`/`FOLLOW_UP`/`DECEASED`/`OTHER`;
 * `ADMITTED`/`TRANSFERRED` rejected), `EncounterDispositionChanged` event,
 * `encounter.encounters.manage_disposition` key.
 * Increment E.6: `AssignEncounterLocation` (`ARRIVED`/`ACTIVE`, coarse
 * care-context `department_id` / `unit_id`, status unchanged; ADR-121/130 B) —
 * `EncounterLifecycle::assertCanAssignLocation`, patch semantics + resolved-no-op
 * guard, `EncounterLocationChanged` event, `encounter.encounters.assign_location`
 * key. NO bed / room / movement history (Bed & Ward / Queueing own those).
 * Increment E.7: `CompleteEncounter` (`ACTIVE & disposition set → COMPLETED`;
 * ADR-121/130) — `EncounterLifecycle::assertCanComplete` (any non-null
 * disposition, incl. `ADMITTED`), `EncounterCompleted` event (+ coded
 * disposition), `encounter.encounters.complete` key. First command to EXIT the
 * active-attendance partial-index predicate → no uniqueness handling. NO
 * downstream-workflow completion (Invariant 18).
 * Increment E.8: `ReopenEncounter` (`COMPLETED → ACTIVE`; ADR-121, ADR-130 C,
 * Rulings E8-R1..E8-R8) — `EncounterLifecycle::assertCanReopen`, privileged
 * `encounter.encounters.reopen` key, `EncounterReopened` event. `completed_at`,
 * `disposition`, and `encounter_type` / `admitted_at` are preserved; re-enters
 * `enc_one_active_per_patient_facility` partial index catching collisions as
 * `ActiveEncounterExists`.
 * Increment E.9: `CancelEncounter` (`{PLANNED, ARRIVED} → CANCELLED`; ADR-121,
 * ADR-130 A/D) — `EncounterLifecycle::assertCanCancel`, dedicated
 * `encounter.encounters.cancel` key, `EncounterCancelled` event. Terminal state,
 * non-idempotent, no disposition required or set; ARRIVED cancellation exits
 * the active-attendance partial index predicate.
 * Increment E.10: `AbandonEncounter` (`ARRIVED → LEFT_WITHOUT_BEING_SEEN |
 * ACTIVE → ABANDONED`; ADR-121, ADR-130 A/D) — `EncounterLifecycle::assertCanAbandon`,
 * dedicated `encounter.encounters.abandon` key, `EncounterAbandoned` event (+ status).
 * Terminal states, non-idempotent, no disposition required or set; both transitions
 * exit the active-attendance partial index predicate.
 * Increment E.11: `TransferEncounter` (`ACTIVE → TRANSFERRED`, disposition set to
 * `TRANSFERRED`; ADR-121, ADR-130 A/C/E) — `EncounterLifecycle::assertCanTransfer`,
 * dedicated `encounter.encounters.transfer` key, `EncounterTransferred` event
 * (8-field PHI-free envelope). Terminal state, non-idempotent; the patient is
 * leaving for another facility, which opens its OWN encounter (§8, §91) — E.11
 * creates neither the receiving encounter nor a `TRANSFERRED_FROM` link
 * (ADR-130 Deferred). `ACTIVE → TRANSFERRED` exits the active-attendance partial
 * index predicate → no uniqueness handling. This completes the ADR-121 lifecycle
 * command set.
 * Increment E.12: `GetEncounter` (`#[ProtectedQuery(ability: 'view', subjectType:
 * Encounter::class)]`; ADR-124, ADR-130 — "GetEncounter is built for the
 * cross-domain test / EncounterApi need") — the first Encounter query, on the
 * `QueryBus`. `EncounterReader::summary()` → `EncounterSummary` DTO (§99 fields,
 * ids + coded values only, not `#[TypeScript]`); `EncounterPolicy::view` (coarse
 * tenant + `encounter.encounters.read` key — the 10th ADR-130 key);
 * `GetEncounterHandler` adds the per-encounter facility refinement (R-1 —
 * out-of-scope → `NotFound`, §114 / Invariant 24/25). No audit / event / state
 * change. Backs the `blueprint.md §23` Patient → Encounter composition proof.
 */
final class EncounterServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(EncounterRepository::class, EloquentEncounterRepository::class);
        $this->app->bind(EncounterReader::class, EloquentEncounterReader::class);
        $this->app->bind(EncounterApi::class, EncounterApiService::class);
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/routes.php');
        $this->loadMigrationsFrom(__DIR__.'/Database/Migrations');

        PermissionRegistry::add(require __DIR__.'/Authorization/permissions.php');

        CommandBus::map([
            CreateEncounter::class => CreateEncounterHandler::class,
            RecordArrival::class => RecordArrivalHandler::class,
            StartEncounter::class => StartEncounterHandler::class,
            AdmitEncounter::class => AdmitEncounterHandler::class,
            ChangeEncounterDisposition::class => ChangeEncounterDispositionHandler::class,
            AssignEncounterLocation::class => AssignEncounterLocationHandler::class,
            CompleteEncounter::class => CompleteEncounterHandler::class,
            ReopenEncounter::class => ReopenEncounterHandler::class,
            CancelEncounter::class => CancelEncounterHandler::class,
            AbandonEncounter::class => AbandonEncounterHandler::class,
            TransferEncounter::class => TransferEncounterHandler::class,
            RecordEncounterRelationship::class => RecordEncounterRelationshipHandler::class,
        ]);

        QueryBus::map([
            GetEncounter::class => GetEncounterHandler::class,
            CountFacilityEncounters::class => CountFacilityEncountersHandler::class,
            ListFacilityEncounters::class => ListFacilityEncountersHandler::class,
            GetPatientEncounters::class => GetPatientEncountersHandler::class,
        ]);

        Gate::policy(Encounter::class, EncounterPolicy::class);

    }
}

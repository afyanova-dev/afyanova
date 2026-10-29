<?php

declare(strict_types=1);

use App\Domains\Clinical\Contracts\Event\ProcedureAborted;
use App\Domains\Clinical\Contracts\Event\ProcedureAuthorized;
use App\Domains\Clinical\Contracts\Event\ProcedureCancelled;
use App\Domains\Clinical\Contracts\Event\ProcedureCompleted;
use App\Domains\Clinical\Contracts\Event\ProcedureDeclined;
use App\Domains\Clinical\Contracts\Event\ProcedureMaterialsConsumed;
use App\Domains\Clinical\Contracts\Event\ProcedureReady;
use App\Domains\Clinical\Contracts\Event\ProcedureRequested;
use App\Domains\Clinical\Contracts\Event\ProcedureStarted;
use App\Domains\Clinical\Domain\Enum\ClinicalProcedureStatus;
use App\Domains\Clinical\Domain\Model\ClinicalProcedure;
use App\Domains\Clinical\Domain\Model\ProcedureAmendment;
use App\Domains\Clinical\Domain\Model\ProcedureMaterial;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/*
 | PHASE 6 (CP.1) unit gate — the ClinicalProcedure aggregate (ADR-137,
 | ADR-146). In-memory only — no DB.
 */

function makeProcedure(array $o = []): ClinicalProcedure
{
    return ClinicalProcedure::request(
        id: $o['id'] ?? (string) Str::uuid7(),
        tenantId: (string) Str::uuid7(),
        facilityId: (string) Str::uuid7(),
        patientId: (string) Str::uuid7(),
        encounterId: (string) Str::uuid7(),
        authorUserId: (string) Str::uuid7(),
        authoredContext: $o['authoredContext'] ?? ClinicalProcedure::AUTHORED_CONTEXT_CLINICIAN,
        procedureNumber: $o['procedureNumber'] ?? 'PROC-00000001',
        definitionId: (string) Str::uuid7(),
        definitionVersion: 1,
        procedureCategory: $o['procedureCategory'] ?? 'DRESSING_WOUND_CARE',
        indication: $o['indication'] ?? null,
        sourceDiagnosisId: $o['sourceDiagnosisId'] ?? null,
        sourceClinicalPlanId: $o['sourceClinicalPlanId'] ?? null,
        financialClearanceRef: $o['financialClearanceRef'] ?? null,
        financialPolicyVersion: $o['financialPolicyVersion'] ?? null,
        occurredAt: CarbonImmutable::now(),
        recordedAt: CarbonImmutable::now(),
    );
}

// ── request() ──────────────────────────────────────────────────────

test('request() produces a REQUESTED procedure at version 0, one ProcedureRequested', function () {
    $p = makeProcedure(['procedureCategory' => 'INJECTION']);

    expect($p->status)->toBe(ClinicalProcedureStatus::Requested)
        ->and((int) $p->version)->toBe(0)
        ->and($p->requested_by_user_id)->toBe((string) $p->author_user_id)
        ->and($p->authorized_by_user_id)->toBeNull()
        ->and($p->performed_by_user_id)->toBeNull()
        ->and($p->clinical_note_id)->toBeNull();

    $events = $p->releaseEvents();
    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(ProcedureRequested::class)
        ->and($events[0]->procedureId)->toBe((string) $p->id)
        ->and($events[0]->procedureCategory)->toBe('INJECTION')
        ->and($events[0]->status)->toBe('REQUESTED')
        // PHI-free: no procedure_number / site / indication on the event (ADR-146 D-R)
        ->and(property_exists($events[0], 'procedureNumber'))->toBeFalse()
        ->and(property_exists($events[0], 'indication'))->toBeFalse();
});

test('request() accepts CLINICIAN and NURSING and rejects anything else (the ADR-144 mechanism)', function () {
    expect(makeProcedure(['authoredContext' => 'CLINICIAN'])->authored_context)->toBe('CLINICIAN')
        ->and(makeProcedure(['authoredContext' => 'NURSING'])->authored_context)->toBe('NURSING');

    expect(fn () => makeProcedure(['authoredContext' => 'PROCEDURE']))
        ->toThrow(InvalidArgumentException::class, 'authored_context');
});

// ── the happy-path lifecycle ───────────────────────────────────────

test('authorize() -> AUTHORIZED, sets authorized_by_user_id once, records ProcedureAuthorized', function () {
    $p = makeProcedure();
    $p->releaseEvents();
    $authorizerId = (string) Str::uuid7();

    $p->authorize($authorizerId);

    expect($p->status)->toBe(ClinicalProcedureStatus::Authorized)
        ->and($p->authorized_by_user_id)->toBe($authorizerId)
        ->and($p->authorized_at)->not->toBeNull();

    $events = $p->releaseEvents();
    expect($events)->toHaveCount(1)->and($events[0])->toBeInstanceOf(ProcedureAuthorized::class);
});

test('markReady() -> READY, records ProcedureReady', function () {
    $p = makeProcedure();
    $p->releaseEvents();
    $p->authorize((string) Str::uuid7());
    $p->releaseEvents();

    $p->markReady();

    expect($p->status)->toBe(ClinicalProcedureStatus::Ready)->and($p->ready_at)->not->toBeNull();
    expect($p->releaseEvents()[0])->toBeInstanceOf(ProcedureReady::class);
});

test('start() -> IN_PROGRESS, sets performed_by_user_id once, validates site/laterality against the definition flags, records ProcedureStarted', function () {
    $p = makeProcedure();
    $p->releaseEvents();
    $p->authorize((string) Str::uuid7());
    $p->releaseEvents();
    $performerId = (string) Str::uuid7();

    $p->start(performedByUserId: $performerId, requiresSite: true, requiresLaterality: true, site: '  left forearm  ', laterality: 'left');

    expect($p->status)->toBe(ClinicalProcedureStatus::InProgress)
        ->and($p->performed_by_user_id)->toBe($performerId)
        ->and($p->site)->toBe('left forearm')          // trimmed
        ->and($p->laterality)->toBe('LEFT')             // normalized
        ->and($p->started_at)->not->toBeNull();

    expect($p->releaseEvents()[0])->toBeInstanceOf(ProcedureStarted::class);
});

test('start() rejects a missing site/laterality when the definition requires them, and rejects an out-of-set laterality', function () {
    $p1 = makeProcedure();
    $p1->authorize((string) Str::uuid7());
    expect(fn () => $p1->start(performedByUserId: (string) Str::uuid7(), requiresSite: true, requiresLaterality: false, site: null, laterality: null))
        ->toThrow(InvalidArgumentException::class, 'site');

    $p2 = makeProcedure();
    $p2->authorize((string) Str::uuid7());
    expect(fn () => $p2->start(performedByUserId: (string) Str::uuid7(), requiresSite: false, requiresLaterality: true, site: null, laterality: null))
        ->toThrow(InvalidArgumentException::class, 'laterality');

    $p3 = makeProcedure();
    $p3->authorize((string) Str::uuid7());
    expect(fn () => $p3->start(performedByUserId: (string) Str::uuid7(), requiresSite: false, requiresLaterality: false, site: null, laterality: 'BOTH'))
        ->toThrow(InvalidArgumentException::class);
});

test('complete() -> COMPLETED, optionally sets outcome/outcome_detail/complications, records ProcedureCompleted carrying the coded outcome', function () {
    $p = makeProcedure();
    $p->authorize((string) Str::uuid7());
    $p->start(performedByUserId: (string) Str::uuid7(), requiresSite: false, requiresLaterality: false, site: null, laterality: null);
    $p->releaseEvents();

    $p->complete('SUCCESSFUL', 'Wound clean, dressed', null);

    expect($p->status)->toBe(ClinicalProcedureStatus::Completed)
        ->and($p->outcome)->toBe('SUCCESSFUL')
        ->and($p->outcome_detail)->toBe('Wound clean, dressed')
        ->and($p->completed_at)->not->toBeNull();

    $events = $p->releaseEvents();
    expect($events[0])->toBeInstanceOf(ProcedureCompleted::class)
        ->and($events[0]->outcome)->toBe('SUCCESSFUL')
        // PHI-free: no outcome_detail / complications on the event (R-3)
        ->and(property_exists($events[0], 'outcomeDetail'))->toBeFalse()
        ->and(property_exists($events[0], 'laterality'))->toBeFalse();
});

// ── terminal branches ──────────────────────────────────────────────

test('cancel() -> CANCELLED with an optional reason, records ProcedureCancelled', function () {
    $p = makeProcedure();
    $p->releaseEvents();

    $p->cancel('equipment unavailable');

    expect($p->status)->toBe(ClinicalProcedureStatus::Cancelled)->and($p->status_reason)->toBe('equipment unavailable');
    expect($p->releaseEvents()[0])->toBeInstanceOf(ProcedureCancelled::class);
});

test('abort() -> ABORTED, requires a non-blank reason, records ProcedureAborted', function () {
    $p = makeProcedure();
    $p->authorize((string) Str::uuid7());
    $p->start(performedByUserId: (string) Str::uuid7(), requiresSite: false, requiresLaterality: false, site: null, laterality: null);
    $p->releaseEvents();

    expect(fn () => $p->abort('   '))->toThrow(InvalidArgumentException::class, 'reason is required');

    $p->abort('patient became unstable');
    expect($p->status)->toBe(ClinicalProcedureStatus::Aborted)->and($p->status_reason)->toBe('patient became unstable');
    expect($p->releaseEvents()[0])->toBeInstanceOf(ProcedureAborted::class);
});

test('decline() -> DECLINED with an optional reason, records ProcedureDeclined', function () {
    $p = makeProcedure();
    $p->authorize((string) Str::uuid7());
    $p->releaseEvents();

    $p->decline('patient refused');

    expect($p->status)->toBe(ClinicalProcedureStatus::Declined)->and($p->status_reason)->toBe('patient refused');
    expect($p->releaseEvents()[0])->toBeInstanceOf(ProcedureDeclined::class);
});

// ── outcome / note link / materials / amendment ───────────────────

test('recordOutcome() sets outcome/outcome_detail with NO domain event', function () {
    $p = makeProcedure();
    $p->authorize((string) Str::uuid7());
    $p->start(performedByUserId: (string) Str::uuid7(), requiresSite: false, requiresLaterality: false, site: null, laterality: null);
    $p->releaseEvents();

    $p->recordOutcome('PARTIALLY_SUCCESSFUL', 'partial removal');

    expect($p->outcome)->toBe('PARTIALLY_SUCCESSFUL')
        ->and($p->outcome_detail)->toBe('partial removal')
        ->and($p->releaseEvents())->toBe([]);
});

test('linkClinicalNote() sets clinical_note_id with NO domain event (ADR-146 D-L)', function () {
    $p = makeProcedure();
    $p->releaseEvents();
    $noteId = (string) Str::uuid7();

    $p->linkClinicalNote($noteId);

    expect($p->clinical_note_id)->toBe($noteId)->and($p->releaseEvents())->toBe([]);
});

test('recordMaterialsConsumed() appends one ProcedureMaterial per line and records ONE ProcedureMaterialsConsumed event carrying every line', function () {
    $p = makeProcedure();
    $p->releaseEvents();

    $p->recordMaterialsConsumed([
        ['materialRef' => 'GAUZE', 'materialCodeSystem' => null, 'quantity' => 2.0, 'unit' => 'pack', 'batchRef' => null],
        ['materialRef' => 'BANDAGE', 'materialCodeSystem' => null, 'quantity' => 1.0, 'unit' => null, 'batchRef' => 'B123'],
    ], (string) Str::uuid7(), CarbonImmutable::now());

    $materials = $p->releasePendingMaterials();
    expect($materials)->toHaveCount(2)
        ->and($materials[0])->toBeInstanceOf(ProcedureMaterial::class)
        ->and((string) $materials[0]->material_ref)->toBe('GAUZE');

    $events = $p->releaseEvents();
    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(ProcedureMaterialsConsumed::class)
        ->and($events[0]->materials)->toHaveCount(2)
        ->and($events[0]->materials[1]['batchRef'])->toBe('B123');
});

test('amend() applies only the supplied fields onto the row, appends one ProcedureAmendment capturing which fields changed, NO domain event', function () {
    $p = makeProcedure();
    $p->authorize((string) Str::uuid7());
    $p->start(performedByUserId: (string) Str::uuid7(), requiresSite: false, requiresLaterality: false, site: null, laterality: null);
    $p->complete('UNSUCCESSFUL', null, null);
    $p->releaseEvents();

    $p->amend(
        amendmentType: 'CORRECTION',
        body: 'Outcome was mis-recorded; procedure succeeded.',
        reason: 'documentation error',
        fields: ['outcome' => 'SUCCESSFUL', 'complications' => null],
        authorUserId: (string) Str::uuid7(),
        occurredAt: CarbonImmutable::now(),
        recordedAt: CarbonImmutable::now(),
    );

    expect($p->outcome)->toBe('SUCCESSFUL')
        ->and($p->site)->toBeNull() // untouched — not supplied
        ->and($p->releaseEvents())->toBe([]); // no PHASE-6 consumer needs an amendment event

    $amendments = $p->releasePendingAmendments();
    expect($amendments)->toHaveCount(1)
        ->and($amendments[0])->toBeInstanceOf(ProcedureAmendment::class)
        ->and($amendments[0]->reason)->toBe('documentation error')
        ->and($amendments[0]->changed_fields)->toEqualCanonicalizing(['outcome', 'complications']);
});

test('the aggregate is final', function () {
    expect((new ReflectionClass(ClinicalProcedure::class))->isFinal())->toBeTrue();
});

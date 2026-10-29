<?php

declare(strict_types=1);

use App\Domains\Theatre\Application\Command\AbortProcedure;
use App\Domains\Theatre\Application\Command\AcknowledgeRecoveryHandoff;
use App\Domains\Theatre\Application\Command\CompleteProcedure;
use App\Domains\Theatre\Application\Command\InitiateRecoveryHandoff;
use App\Domains\Theatre\Application\Command\RecordCaseEvent;
use App\Domains\Theatre\Application\Command\RecordPlannedProcedure;
use App\Domains\Theatre\Application\Command\StartProcedure;
use App\Domains\Theatre\Application\Query\GetCaseEvents;
use App\Domains\Theatre\Application\Query\GetProceduresForCase;
use App\Domains\Theatre\Application\Query\GetRecoveryHandoffForCase;
use App\Domains\Theatre\Contracts\Event\CaseEventRecorded;
use App\Domains\Theatre\Contracts\Event\RecoveryHandoffAcknowledged;
use App\Domains\Theatre\Contracts\Event\SurgicalProcedureCompleted;
use App\Domains\Theatre\Domain\Exception\RecoveryHandoffAlreadyExistsForCase;
use App\Domains\Theatre\Domain\Exception\SurgicalCaseNotEligibleForCaseEvent;
use App\Domains\Theatre\Domain\Model\RecoveryHandoff;
use App\Domains\Theatre\Domain\Model\SurgicalCase;
use App\Domains\Theatre\Domain\Model\SurgicalProcedure;
use App\Shared\Bus\CommandBus;
use App\Shared\Bus\QueryBus;
use App\Shared\Tenancy\Tenancy;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * @return array{t: string, f: string, a: string, caseId: string}
 */
function seedT3Case(?array $permissions = null, string $status = SurgicalCase::STATUS_IN_PROGRESS): array
{
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = theatreFixture($permissions);
    $case = createSurgicalCaseFixture(
        (string) $tenant->id, (string) $facility->id, (string) Str::uuid7(), (string) Str::uuid7(),
        ['status' => $status],
    );

    return ['t' => (string) $tenant->id, 'f' => (string) $facility->id, 'a' => (string) $user->id, 'caseId' => (string) $case->id];
}

function dispatchT3(string $t, string $f, object $command): mixed
{
    return TenantContextScope::run($t, $f, fn () => app(CommandBus::class)->dispatch($command));
}

test('T.3a — multi-procedure per case: sequences 1,2; PLANNED -> IN_PROGRESS -> COMPLETED; abort path; PHI-free events', function () {
    ['t' => $t, 'f' => $f, 'a' => $a, 'caseId' => $caseId] = seedT3Case();

    $p1 = (string) Str::uuid7();
    $p2 = (string) Str::uuid7();
    dispatchT3($t, $f, new RecordPlannedProcedure(procedureId: $p1, caseId: $caseId, actorId: $a, plannedDescription: 'Diagnostic laparoscopy'));
    dispatchT3($t, $f, new RecordPlannedProcedure(procedureId: $p2, caseId: $caseId, actorId: $a, plannedDescription: 'Appendectomy'));

    [$proc1, $proc2] = TenantContextScope::run($t, $f, fn () => [
        SurgicalProcedure::query()->find($p1), SurgicalProcedure::query()->find($p2),
    ]);
    expect($proc1->sequence)->toBe(1)->and($proc2->sequence)->toBe(2);

    dispatchT3($t, $f, new StartProcedure(procedureId: $p2, actorId: $a, actualDescription: 'Open conversion'));
    dispatchT3($t, $f, new CompleteProcedure(
        procedureId: $p2, actorId: $a, outcome: 'SUCCESSFUL',
        findings: 'Perforated appendix', complications: 'None',
    ));
    $proc2 = TenantContextScope::run($t, $f, fn () => SurgicalProcedure::query()->find($p2));
    expect($proc2->status)->toBe(SurgicalProcedure::STATUS_COMPLETED)
        ->and($proc2->outcome)->toBe('SUCCESSFUL')
        ->and($proc2->findings)->toBe('Perforated appendix');

    dispatchT3($t, $f, new AbortProcedure(procedureId: $p1, actorId: $a, reason: 'Not required after conversion.'));
    $proc1 = TenantContextScope::run($t, $f, fn () => SurgicalProcedure::query()->find($p1));
    expect($proc1->status)->toBe(SurgicalProcedure::STATUS_ABORTED);

    $completed = Tenancy::run($t, fn () => DB::table('shared_outbox_events')
        ->where('event_class', SurgicalProcedureCompleted::class)->first());
    expect($completed->payload)->not->toContain('Perforated')
        ->and($completed->payload)->not->toContain('SUCCESSFUL'); // outcome is coded but still not on this minimal event

    $list = TenantContextScope::run($t, $f, fn () => app(QueryBus::class)->dispatch(new GetProceduresForCase($caseId)));
    expect($list)->toHaveCount(2)->and($list[0]->sequence)->toBe(1);
});

test('T.3a — recording a procedure requires theatre.procedures.record', function () {
    ['t' => $t, 'f' => $f, 'a' => $a, 'caseId' => $caseId] = seedT3Case(permissions: ['theatre.access']);
    expect(fn () => dispatchT3($t, $f, new RecordPlannedProcedure(procedureId: (string) Str::uuid7(), caseId: $caseId, actorId: $a)))
        ->toThrow(AuthorizationException::class);
});

test('T.3b — recovery handoff: INITIATED -> ACKNOWLEDGED, one per case, PHI-free events, condition row-only', function () {
    ['t' => $t, 'f' => $f, 'a' => $a, 'caseId' => $caseId] = seedT3Case();

    $handoffId = (string) Str::uuid7();
    dispatchT3($t, $f, new InitiateRecoveryHandoff(
        handoffId: $handoffId, caseId: $caseId, actorId: $a, destinationType: 'icu',
        patientCondition: 'Ventilated, sedated', pendingActions: 'Chase the 6h gas',
    ));

    $handoff = TenantContextScope::run($t, $f, fn () => RecoveryHandoff::query()->find($handoffId));
    expect($handoff->status)->toBe(RecoveryHandoff::STATUS_INITIATED)
        ->and($handoff->destination_type)->toBe('ICU')
        ->and($handoff->patient_condition)->toBe('Ventilated, sedated');

    expect(fn () => dispatchT3($t, $f, new InitiateRecoveryHandoff(
        handoffId: (string) Str::uuid7(), caseId: $caseId, actorId: $a, destinationType: 'WARD',
    )))->toThrow(RecoveryHandoffAlreadyExistsForCase::class);

    dispatchT3($t, $f, new AcknowledgeRecoveryHandoff(handoffId: $handoffId, actorId: $a));
    $handoff = TenantContextScope::run($t, $f, fn () => RecoveryHandoff::query()->find($handoffId));
    expect($handoff->status)->toBe(RecoveryHandoff::STATUS_ACKNOWLEDGED)
        ->and($handoff->acknowledged_by_user_id)->toBe($a);

    $ackEvent = Tenancy::run($t, fn () => DB::table('shared_outbox_events')
        ->where('event_class', RecoveryHandoffAcknowledged::class)->first());
    expect($ackEvent->payload)->not->toContain('Ventilated');

    $summary = TenantContextScope::run($t, $f, fn () => app(QueryBus::class)->dispatch(new GetRecoveryHandoffForCase($caseId)));
    expect($summary->recoveryHandoffId)->toBe($handoffId);
});

test('T.3b — acknowledge requires theatre.recovery.acknowledge (distinct from the initiator key)', function () {
    ['t' => $t, 'f' => $f, 'a' => $a, 'caseId' => $caseId] = seedT3Case(permissions: [
        'theatre.access', 'theatre.recovery.handoff',
    ]);
    $handoffId = (string) Str::uuid7();
    dispatchT3($t, $f, new InitiateRecoveryHandoff(handoffId: $handoffId, caseId: $caseId, actorId: $a, destinationType: 'WARD'));

    expect(fn () => dispatchT3($t, $f, new AcknowledgeRecoveryHandoff(handoffId: $handoffId, actorId: $a)))
        ->toThrow(AuthorizationException::class);
});

test('T.3c — case events form an append-only timeline in event_at order; note row-only; case gate', function () {
    ['t' => $t, 'f' => $f, 'a' => $a, 'caseId' => $caseId] = seedT3Case();

    dispatchT3($t, $f, new RecordCaseEvent(
        caseEventId: (string) Str::uuid7(), caseId: $caseId, actorId: $a,
        eventType: 'incision', eventAt: now()->subMinutes(30)->toIso8601String(), note: 'Kocher incision',
    ));
    dispatchT3($t, $f, new RecordCaseEvent(
        caseEventId: (string) Str::uuid7(), caseId: $caseId, actorId: $a,
        eventType: 'patient_in_or', eventAt: now()->subMinutes(50)->toIso8601String(),
    ));

    $events = TenantContextScope::run($t, $f, fn () => app(QueryBus::class)->dispatch(new GetCaseEvents($caseId)));
    expect($events)->toHaveCount(2)
        ->and($events[0]->eventType)->toBe('PATIENT_IN_OR')  // earlier event_at first
        ->and($events[1]->eventType)->toBe('INCISION');

    $outbox = Tenancy::run($t, fn () => DB::table('shared_outbox_events')
        ->where('event_class', CaseEventRecorded::class)->get());
    expect($outbox)->toHaveCount(2);
    foreach ($outbox as $e) {
        expect($e->payload)->not->toContain('Kocher');
    }

    // a case still REQUESTED has no timeline
    ['t' => $t2, 'f' => $f2, 'a' => $a2, 'caseId' => $reqCase] = seedT3Case(status: SurgicalCase::STATUS_REQUESTED);
    expect(fn () => dispatchT3($t2, $f2, new RecordCaseEvent(
        caseEventId: (string) Str::uuid7(), caseId: $reqCase, actorId: $a2, eventType: 'INCISION', eventAt: now()->toIso8601String(),
    )))->toThrow(SurgicalCaseNotEligibleForCaseEvent::class);
});

<?php

declare(strict_types=1);

use App\Domains\Clinical\Application\Command\AbortProcedure;
use App\Domains\Clinical\Application\Command\AuthorizeProcedure;
use App\Domains\Clinical\Application\Command\CancelProcedure;
use App\Domains\Clinical\Application\Command\CompleteProcedure;
use App\Domains\Clinical\Application\Command\DeclineProcedure;
use App\Domains\Clinical\Application\Command\MarkProcedureReady;
use App\Domains\Clinical\Application\Command\StartProcedure;
use App\Domains\Clinical\Contracts\Event\ProcedureAuthorized;
use App\Domains\Clinical\Contracts\Event\ProcedureCompleted;
use App\Domains\Clinical\Domain\ClinicalProcedureRepository;
use App\Domains\Clinical\Domain\Exception\IllegalProcedureTransition;
use App\Domains\Clinical\Domain\Model\ClinicalProcedure;
use App\Shared\Bus\CommandBus;
use App\Shared\Concurrency\ConcurrencyConflict;
use App\Shared\Tenancy\Tenancy;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/*
 | PHASE 6 (CP.1) gate — the full ClinicalProcedure lifecycle through the real
 | CommandBus: REQUESTED -> AUTHORIZED -> [READY] -> IN_PROGRESS -> COMPLETED,
 | and the terminal branches CANCELLED / ABORTED / DECLINED (ADR-137, ADR-146
 | D-F).
 */

uses(RefreshDatabase::class);

function dispatchProcedureCommand(string $tenantId, string $facilityId, object $command): mixed
{
    return TenantContextScope::run($tenantId, $facilityId, fn () => app(CommandBus::class)->dispatch($command));
}

test('the fast path: AUTHORIZED -> IN_PROGRESS directly when the definition does NOT require a readiness check', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = clinicalFixture();
    [$patientId, $encounterId] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);
    $id = clinicalRequestProcedure((string) $tenant->id, (string) $facility->id, $patientId, $encounterId, (string) $user->id);

    dispatchProcedureCommand((string) $tenant->id, (string) $facility->id, new AuthorizeProcedure($id, (string) $user->id));
    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('clin_procedures')->where('id', $id)->value('status')))->toBe('AUTHORIZED');

    dispatchProcedureCommand((string) $tenant->id, (string) $facility->id, new StartProcedure($id, (string) $user->id));
    $row = Tenancy::run((string) $tenant->id, fn () => DB::table('clin_procedures')->where('id', $id)->first());
    expect($row->status)->toBe('IN_PROGRESS')->and($row->performed_by_user_id)->toBe((string) $user->id)->and($row->ready_at)->toBeNull();

    dispatchProcedureCommand((string) $tenant->id, (string) $facility->id, new CompleteProcedure($id, (string) $user->id, 'SUCCESSFUL'));
    $completed = Tenancy::run((string) $tenant->id, fn () => DB::table('clin_procedures')->where('id', $id)->first());
    expect($completed->status)->toBe('COMPLETED')->and($completed->outcome)->toBe('SUCCESSFUL')->and($completed->completed_at)->not->toBeNull();

    $outbox = Tenancy::run((string) $tenant->id, fn () => DB::table('shared_outbox_events')->where('event_class', ProcedureCompleted::class)->first());
    expect(json_decode((string) $outbox->payload, true)['outcome'])->toBe('SUCCESSFUL');

    foreach (['procedures.authorized', 'procedures.started', 'procedures.completed'] as $action) {
        expect(Tenancy::run((string) $tenant->id, fn () => DB::table('shared_audit_log')->where('action', $action)->where('target_id', $id)->exists()))->toBeTrue();
    }
});

test('MarkProcedureReady is required first when the definition requires a readiness check; StartProcedure directly is rejected', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = clinicalFixture();
    [$patientId, $encounterId] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);
    $id = clinicalRequestProcedure((string) $tenant->id, (string) $facility->id, $patientId, $encounterId, (string) $user->id, [
        'definition' => ['requiresReadinessCheck' => true],
    ]);
    dispatchProcedureCommand((string) $tenant->id, (string) $facility->id, new AuthorizeProcedure($id, (string) $user->id));

    expect(fn () => dispatchProcedureCommand((string) $tenant->id, (string) $facility->id, new StartProcedure($id, (string) $user->id)))
        ->toThrow(IllegalProcedureTransition::class);

    dispatchProcedureCommand((string) $tenant->id, (string) $facility->id, new MarkProcedureReady($id, (string) $user->id));
    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('clin_procedures')->where('id', $id)->value('status')))->toBe('READY');

    // marking ready again (from AUTHORIZED it now isn't) is illegal — attempting from READY is illegal too
    expect(fn () => dispatchProcedureCommand((string) $tenant->id, (string) $facility->id, new MarkProcedureReady($id, (string) $user->id)))
        ->toThrow(IllegalProcedureTransition::class);

    dispatchProcedureCommand((string) $tenant->id, (string) $facility->id, new StartProcedure($id, (string) $user->id));
    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('clin_procedures')->where('id', $id)->value('status')))->toBe('IN_PROGRESS');
});

test('MarkProcedureReady is illegal when the definition does NOT require a readiness check', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = clinicalFixture();
    [$patientId, $encounterId] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);
    $id = clinicalRequestProcedure((string) $tenant->id, (string) $facility->id, $patientId, $encounterId, (string) $user->id);
    dispatchProcedureCommand((string) $tenant->id, (string) $facility->id, new AuthorizeProcedure($id, (string) $user->id));

    expect(fn () => dispatchProcedureCommand((string) $tenant->id, (string) $facility->id, new MarkProcedureReady($id, (string) $user->id)))
        ->toThrow(IllegalProcedureTransition::class, 'does not require a readiness check');
});

test('site/laterality are validated at StartProcedure against the definition flags', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = clinicalFixture();
    [$patientId, $encounterId] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);
    $id = clinicalRequestProcedure((string) $tenant->id, (string) $facility->id, $patientId, $encounterId, (string) $user->id, [
        'definition' => ['requiresSite' => true, 'requiresLaterality' => true],
    ]);
    dispatchProcedureCommand((string) $tenant->id, (string) $facility->id, new AuthorizeProcedure($id, (string) $user->id));

    expect(fn () => dispatchProcedureCommand((string) $tenant->id, (string) $facility->id, new StartProcedure($id, (string) $user->id)))
        ->toThrow(InvalidArgumentException::class);

    dispatchProcedureCommand((string) $tenant->id, (string) $facility->id, new StartProcedure($id, (string) $user->id, site: 'left forearm', laterality: 'LEFT'));
    $row = Tenancy::run((string) $tenant->id, fn () => DB::table('clin_procedures')->where('id', $id)->first());
    expect($row->site)->toBe('left forearm')->and($row->laterality)->toBe('LEFT');
});

test('CancelProcedure is legal only before execution starts', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = clinicalFixture();
    [$patientId, $encounterId] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);
    $id = clinicalRequestProcedure((string) $tenant->id, (string) $facility->id, $patientId, $encounterId, (string) $user->id);

    dispatchProcedureCommand((string) $tenant->id, (string) $facility->id, new CancelProcedure($id, (string) $user->id, 'no longer needed'));
    $row = Tenancy::run((string) $tenant->id, fn () => DB::table('clin_procedures')->where('id', $id)->first());
    expect($row->status)->toBe('CANCELLED')->and($row->status_reason)->toBe('no longer needed');

    expect(fn () => dispatchProcedureCommand((string) $tenant->id, (string) $facility->id, new AuthorizeProcedure($id, (string) $user->id)))
        ->toThrow(IllegalProcedureTransition::class);
});

test('AbortProcedure requires a reason and is legal only from IN_PROGRESS', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = clinicalFixture();
    [$patientId, $encounterId] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);
    $id = clinicalRequestProcedure((string) $tenant->id, (string) $facility->id, $patientId, $encounterId, (string) $user->id);

    expect(fn () => new AbortProcedure($id, (string) $user->id, ''))->toThrow(InvalidArgumentException::class);

    dispatchProcedureCommand((string) $tenant->id, (string) $facility->id, new AuthorizeProcedure($id, (string) $user->id));
    dispatchProcedureCommand((string) $tenant->id, (string) $facility->id, new StartProcedure($id, (string) $user->id));
    dispatchProcedureCommand((string) $tenant->id, (string) $facility->id, new AbortProcedure($id, (string) $user->id, 'patient became unstable'));

    $row = Tenancy::run((string) $tenant->id, fn () => DB::table('clin_procedures')->where('id', $id)->first());
    expect($row->status)->toBe('ABORTED')->and($row->status_reason)->toBe('patient became unstable');

    $audit = Tenancy::run((string) $tenant->id, fn () => DB::table('shared_audit_log')->where('action', 'procedures.aborted')->where('target_id', $id)->first());
    expect($audit->reason)->toBeNull(); // the mandatory rationale lives on the row, not the audit (ADR-146 D-J)
});

test('DeclineProcedure is legal from AUTHORIZED (fast path) and from READY (readiness-required path)', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = clinicalFixture();
    [$patientId, $encounterId] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);

    $id1 = clinicalRequestProcedure((string) $tenant->id, (string) $facility->id, $patientId, $encounterId, (string) $user->id);
    dispatchProcedureCommand((string) $tenant->id, (string) $facility->id, new AuthorizeProcedure($id1, (string) $user->id));
    dispatchProcedureCommand((string) $tenant->id, (string) $facility->id, new DeclineProcedure($id1, (string) $user->id, 'patient refused'));
    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('clin_procedures')->where('id', $id1)->value('status')))->toBe('DECLINED');

    $id2 = clinicalRequestProcedure((string) $tenant->id, (string) $facility->id, $patientId, $encounterId, (string) $user->id, [
        'definition' => ['requiresReadinessCheck' => true],
    ]);
    dispatchProcedureCommand((string) $tenant->id, (string) $facility->id, new AuthorizeProcedure($id2, (string) $user->id));
    dispatchProcedureCommand((string) $tenant->id, (string) $facility->id, new MarkProcedureReady($id2, (string) $user->id));
    dispatchProcedureCommand((string) $tenant->id, (string) $facility->id, new DeclineProcedure($id2, (string) $user->id));
    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('clin_procedures')->where('id', $id2)->value('status')))->toBe('DECLINED');
});

test('a stale in-memory authorize() save throws ConcurrencyConflict — no event', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = clinicalFixture();
    [$patientId, $encounterId] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);
    $id = clinicalRequestProcedure((string) $tenant->id, (string) $facility->id, $patientId, $encounterId, (string) $user->id);

    try {
        TenantContextScope::run((string) $tenant->id, (string) $facility->id, function () use ($id, $user): void {
            $stale = ClinicalProcedure::query()->findOrFail($id);
            ClinicalProcedure::query()->whereKey($id)->update(['version' => 5]);
            $stale->authorize((string) $user->id);
            app(ClinicalProcedureRepository::class)->save($stale);
        });
        test()->fail('expected ConcurrencyConflict');
    } catch (ConcurrencyConflict) {
        // expected
    }

    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('shared_outbox_events')->where('event_class', ProcedureAuthorized::class)->count()))->toBe(0);
});

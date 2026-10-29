<?php

declare(strict_types=1);

use App\Domains\Clinical\Application\Command\AuthorizeProcedure;
use App\Domains\Clinical\Application\Command\CompleteProcedure;
use App\Domains\Clinical\Application\Command\RecordProcedureOutcome;
use App\Domains\Clinical\Application\Command\StartProcedure;
use App\Domains\Clinical\Domain\Exception\IllegalProcedureTransition;
use App\Shared\Bus\CommandBus;
use App\Shared\Tenancy\Tenancy;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/*
 | PHASE 6 (CP.1) gate — RecordProcedureOutcome (ADR-146 D-I / S3 / O-2): legal
 | ONLY while IN_PROGRESS; post-completion use AmendProcedure instead.
 */

uses(RefreshDatabase::class);

function dispatchOutcomeCommand(string $tenantId, string $facilityId, object $command): mixed
{
    return TenantContextScope::run($tenantId, $facilityId, fn () => app(CommandBus::class)->dispatch($command));
}

test('sets outcome/outcome_detail while IN_PROGRESS, no domain event, audited', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = clinicalFixture();
    [$patientId, $encounterId] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);
    $id = clinicalRequestProcedure((string) $tenant->id, (string) $facility->id, $patientId, $encounterId, (string) $user->id);
    dispatchOutcomeCommand((string) $tenant->id, (string) $facility->id, new AuthorizeProcedure($id, (string) $user->id));
    dispatchOutcomeCommand((string) $tenant->id, (string) $facility->id, new StartProcedure($id, (string) $user->id));

    dispatchOutcomeCommand((string) $tenant->id, (string) $facility->id, new RecordProcedureOutcome($id, (string) $user->id, 'PARTIALLY_SUCCESSFUL', 'partial removal'));

    $row = Tenancy::run((string) $tenant->id, fn () => DB::table('clin_procedures')->where('id', $id)->first());
    expect($row->outcome)->toBe('PARTIALLY_SUCCESSFUL')->and($row->outcome_detail)->toBe('partial removal')->and($row->status)->toBe('IN_PROGRESS');

    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('shared_audit_log')->where('action', 'procedures.outcome_recorded')->where('target_id', $id)->exists()))->toBeTrue();
});

test('rejected once the procedure is COMPLETED — use AmendProcedure instead', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = clinicalFixture();
    [$patientId, $encounterId] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);
    $id = clinicalRequestProcedure((string) $tenant->id, (string) $facility->id, $patientId, $encounterId, (string) $user->id);
    dispatchOutcomeCommand((string) $tenant->id, (string) $facility->id, new AuthorizeProcedure($id, (string) $user->id));
    dispatchOutcomeCommand((string) $tenant->id, (string) $facility->id, new StartProcedure($id, (string) $user->id));
    dispatchOutcomeCommand((string) $tenant->id, (string) $facility->id, new CompleteProcedure($id, (string) $user->id, 'SUCCESSFUL'));

    expect(fn () => dispatchOutcomeCommand((string) $tenant->id, (string) $facility->id, new RecordProcedureOutcome($id, (string) $user->id, 'UNSUCCESSFUL')))
        ->toThrow(IllegalProcedureTransition::class);
});

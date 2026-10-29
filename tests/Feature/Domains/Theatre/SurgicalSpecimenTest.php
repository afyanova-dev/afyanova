<?php

declare(strict_types=1);

use App\Domains\Theatre\Application\Command\HandOffSurgicalSpecimen;
use App\Domains\Theatre\Application\Command\LabelSurgicalSpecimen;
use App\Domains\Theatre\Application\Command\MarkSurgicalSpecimenReceived;
use App\Domains\Theatre\Application\Command\RecordSurgicalSpecimen;
use App\Domains\Theatre\Application\Query\GetCaseSpecimens;
use App\Domains\Theatre\Application\Query\GetSurgicalSpecimen;
use App\Domains\Theatre\Contracts\Event\SurgicalSpecimenCollected;
use App\Domains\Theatre\Contracts\Event\SurgicalSpecimenHandedOff;
use App\Domains\Theatre\Domain\Exception\IllegalSurgicalSpecimenTransition;
use App\Domains\Theatre\Domain\Exception\SurgicalCaseNotEligibleForSpecimen;
use App\Domains\Theatre\Domain\Model\SurgicalCase;
use App\Domains\Theatre\Domain\Model\SurgicalSpecimen;
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
function seedSpecimenCase(?array $permissions = null, string $status = SurgicalCase::STATUS_IN_PROGRESS): array
{
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = theatreFixture($permissions);
    $case = createSurgicalCaseFixture(
        (string) $tenant->id, (string) $facility->id, (string) Str::uuid7(), (string) Str::uuid7(),
        ['status' => $status],
    );

    return ['t' => (string) $tenant->id, 'f' => (string) $facility->id, 'a' => (string) $user->id, 'caseId' => (string) $case->id];
}

function dispatchSpec(string $t, string $f, object $command): mixed
{
    return TenantContextScope::run($t, $f, fn () => app(CommandBus::class)->dispatch($command));
}

test('RecordSurgicalSpecimen creates a COLLECTED specimen with a per-case sequence, snapshots the case patient, emits a PHI-free event, audits', function () {
    ['t' => $t, 'f' => $f, 'a' => $a, 'caseId' => $caseId] = seedSpecimenCase();
    $caseRow = TenantContextScope::run($t, $f, fn () => SurgicalCase::query()->find($caseId));

    $s1 = (string) Str::uuid7();
    $s2 = (string) Str::uuid7();
    dispatchSpec($t, $f, new RecordSurgicalSpecimen(
        specimenId: $s1, caseId: $caseId, actorId: $a, specimenType: 'tissue', bodySite: 'appendix',
        description: 'Inflamed appendix on the patient Jane Doe.',
    ));
    dispatchSpec($t, $f, new RecordSurgicalSpecimen(
        specimenId: $s2, caseId: $caseId, actorId: $a, specimenType: 'fluid', laterality: 'left',
    ));

    [$spec1, $spec2] = TenantContextScope::run($t, $f, fn () => [
        SurgicalSpecimen::query()->find($s1), SurgicalSpecimen::query()->find($s2),
    ]);
    expect($spec1->sequence)->toBe(1)->and($spec2->sequence)->toBe(2)
        ->and($spec1->status)->toBe(SurgicalSpecimen::STATUS_COLLECTED)
        ->and($spec1->specimen_type)->toBe('TISSUE')
        ->and($spec1->body_site)->toBe('APPENDIX')
        ->and((string) $spec1->patient_id)->toBe((string) $caseRow->patient_id)
        ->and($spec2->laterality)->toBe('LEFT')
        ->and((int) $spec1->version)->toBe(0);

    $outbox = Tenancy::run($t, fn () => DB::table('shared_outbox_events')->where('event_class', SurgicalSpecimenCollected::class)->first());
    expect($outbox)->not->toBeNull()->and($outbox->payload)->not->toContain('Jane Doe');

    $audit = Tenancy::run($t, fn () => DB::table('shared_audit_log')->where('target_id', $s1)->where('action', 'theatre.surgical_specimen_collected')->first());
    expect($audit)->not->toBeNull();
});

test('a specimen runs COLLECTED -> LABELED -> HANDED_OVER -> RECEIVED, capturing the opaque lab accession', function () {
    ['t' => $t, 'f' => $f, 'a' => $a, 'caseId' => $caseId] = seedSpecimenCase();

    $sid = (string) Str::uuid7();
    dispatchSpec($t, $f, new RecordSurgicalSpecimen(specimenId: $sid, caseId: $caseId, actorId: $a, specimenType: 'TISSUE'));
    dispatchSpec($t, $f, new LabelSurgicalSpecimen(specimenId: $sid, actorId: $a));
    dispatchSpec($t, $f, new HandOffSurgicalSpecimen(specimenId: $sid, actorId: $a, destinationLaboratory: 'histopathology'));
    dispatchSpec($t, $f, new MarkSurgicalSpecimenReceived(specimenId: $sid, actorId: $a, labAccessionRef: 'LABSP-00000042'));

    $spec = TenantContextScope::run($t, $f, fn () => SurgicalSpecimen::query()->find($sid));
    expect($spec->status)->toBe(SurgicalSpecimen::STATUS_RECEIVED)
        ->and($spec->destination_laboratory)->toBe('HISTOPATHOLOGY')
        ->and($spec->handed_over_by_user_id)->toBe($a)
        ->and($spec->handed_over_at)->not->toBeNull()
        ->and($spec->lab_accession_ref)->toBe('LABSP-00000042')
        ->and($spec->received_recorded_at)->not->toBeNull();

    expect(Tenancy::run($t, fn () => DB::table('shared_outbox_events')->where('event_class', SurgicalSpecimenHandedOff::class)->count()))->toBe(1);
});

test('a duplicate hand-off is rejected (chain-of-custody, §318)', function () {
    ['t' => $t, 'f' => $f, 'a' => $a, 'caseId' => $caseId] = seedSpecimenCase();

    $sid = (string) Str::uuid7();
    dispatchSpec($t, $f, new RecordSurgicalSpecimen(specimenId: $sid, caseId: $caseId, actorId: $a, specimenType: 'TISSUE'));
    dispatchSpec($t, $f, new HandOffSurgicalSpecimen(specimenId: $sid, actorId: $a, destinationLaboratory: 'HISTO'));

    expect(fn () => dispatchSpec($t, $f, new HandOffSurgicalSpecimen(specimenId: $sid, actorId: $a, destinationLaboratory: 'HISTO')))
        ->toThrow(IllegalSurgicalSpecimenTransition::class);
});

test('a specimen cannot be recorded for a case that has not started', function () {
    ['t' => $t, 'f' => $f, 'a' => $a, 'caseId' => $caseId] = seedSpecimenCase(status: SurgicalCase::STATUS_SCHEDULED);

    expect(fn () => dispatchSpec($t, $f, new RecordSurgicalSpecimen(
        specimenId: (string) Str::uuid7(), caseId: $caseId, actorId: $a, specimenType: 'TISSUE',
    )))->toThrow(SurgicalCaseNotEligibleForSpecimen::class);
});

test('recording a specimen requires theatre.specimens.record; hand-off requires theatre.specimens.handoff', function () {
    ['t' => $t, 'f' => $f, 'a' => $a, 'caseId' => $caseId] = seedSpecimenCase(permissions: [
        'theatre.access', 'theatre.cases.create', 'theatre.cases.execute', 'theatre.specimens.record',
        'encounter.encounters.read', 'encounter.encounters.create', 'encounter.encounters.update', 'patient.patients.read',
    ]);

    $sid = (string) Str::uuid7();
    dispatchSpec($t, $f, new RecordSurgicalSpecimen(specimenId: $sid, caseId: $caseId, actorId: $a, specimenType: 'TISSUE'));

    expect(fn () => dispatchSpec($t, $f, new HandOffSurgicalSpecimen(specimenId: $sid, actorId: $a, destinationLaboratory: 'HISTO')))
        ->toThrow(AuthorizationException::class);
});

test('GetSurgicalSpecimen and GetCaseSpecimens return the case specimens', function () {
    ['t' => $t, 'f' => $f, 'a' => $a, 'caseId' => $caseId] = seedSpecimenCase();

    $sid = (string) Str::uuid7();
    dispatchSpec($t, $f, new RecordSurgicalSpecimen(specimenId: $sid, caseId: $caseId, actorId: $a, specimenType: 'BONE'));

    $one = TenantContextScope::run($t, $f, fn () => app(QueryBus::class)->dispatch(new GetSurgicalSpecimen(surgicalSpecimenId: $sid)));
    expect($one->specimenType)->toBe('BONE');

    $all = TenantContextScope::run($t, $f, fn () => app(QueryBus::class)->dispatch(new GetCaseSpecimens(caseId: $caseId)));
    expect($all)->toHaveCount(1)->and($all[0]->surgicalSpecimenId)->toBe($sid);
});

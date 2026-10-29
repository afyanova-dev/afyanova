<?php

declare(strict_types=1);

use App\Domains\Theatre\Application\Command\AmendAnaesthesiaRecord;
use App\Domains\Theatre\Application\Command\RecordAnaesthesia;
use App\Domains\Theatre\Application\Command\SignAnaesthesiaRecord;
use App\Domains\Theatre\Application\Command\UpdateAnaesthesiaContent;
use App\Domains\Theatre\Application\Query\GetAnaesthesiaRecord;
use App\Domains\Theatre\Application\Query\GetAnaesthesiaRecordForCase;
use App\Domains\Theatre\Contracts\Dto\AnaesthesiaRecordSummary;
use App\Domains\Theatre\Contracts\Event\AnaesthesiaRecordAmended;
use App\Domains\Theatre\Contracts\Event\AnaesthesiaRecordCreated;
use App\Domains\Theatre\Domain\Exception\AnaesthesiaRecordAlreadyExistsForCase;
use App\Domains\Theatre\Domain\Exception\IllegalAnaesthesiaRecordTransition;
use App\Domains\Theatre\Domain\Exception\SurgicalCaseNotEligibleForAnaesthesiaRecord;
use App\Domains\Theatre\Domain\Model\AnaesthesiaRecord;
use App\Domains\Theatre\Domain\Model\SurgicalCase;
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
 * @return array{tenantId: string, facilityId: string, actorId: string, caseId: string}
 */
function seedCaseForAnaesthesia(?array $permissions = null, string $status = SurgicalCase::STATUS_IN_PROGRESS): array
{
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = theatreFixture($permissions);
    $case = createSurgicalCaseFixture(
        (string) $tenant->id,
        (string) $facility->id,
        (string) Str::uuid7(),
        (string) Str::uuid7(),
        ['status' => $status],
    );

    return [
        'tenantId' => (string) $tenant->id,
        'facilityId' => (string) $facility->id,
        'actorId' => (string) $user->id,
        'caseId' => (string) $case->id,
    ];
}

function dispatchAnaes(string $tenantId, string $facilityId, object $command): mixed
{
    return TenantContextScope::run($tenantId, $facilityId, fn () => app(CommandBus::class)->dispatch($command));
}

test('RecordAnaesthesia originates a DRAFT, normalises the type, keeps content row-only, emits a PHI-free event, audits', function () {
    ['tenantId' => $t, 'facilityId' => $f, 'actorId' => $a, 'caseId' => $caseId] = seedCaseForAnaesthesia();

    $recordId = (string) Str::uuid7();
    dispatchAnaes($t, $f, new RecordAnaesthesia(
        recordId: $recordId,
        caseId: $caseId,
        actorId: $a,
        anaesthesiaType: 'general',
        content: ['technique' => 'RSI with cricoid pressure', 'airway' => 'Grade 1 view'],
    ));

    $record = TenantContextScope::run($t, $f, fn () => AnaesthesiaRecord::query()->find($recordId));
    expect($record)->not->toBeNull()
        ->and($record->status)->toBe(AnaesthesiaRecord::STATUS_DRAFT)
        ->and($record->anaesthesia_type)->toBe('GENERAL')
        ->and($record->content)->toEqual(['technique' => 'RSI with cricoid pressure', 'airway' => 'Grade 1 view'])
        ->and((int) $record->version)->toBe(0);

    $outbox = Tenancy::run($t, fn () => DB::table('shared_outbox_events')
        ->where('event_class', AnaesthesiaRecordCreated::class)->first());
    expect($outbox)->not->toBeNull()
        ->and($outbox->payload)->toContain('GENERAL')
        ->and($outbox->payload)->not->toContain('cricoid')
        ->and($outbox->payload)->not->toContain('Grade 1');

    $audit = Tenancy::run($t, fn () => DB::table('shared_audit_log')
        ->where('target_id', $recordId)->where('action', 'theatre.anaesthesia_record_created')->first());
    expect($audit)->not->toBeNull()
        ->and($audit->metadata)->not->toContain('cricoid');
});

test('RecordAnaesthesia is rejected when the case has not reached surgery, and once per case', function () {
    ['tenantId' => $t, 'facilityId' => $f, 'actorId' => $a, 'caseId' => $sched] =
        seedCaseForAnaesthesia(status: SurgicalCase::STATUS_SCHEDULED);
    expect(fn () => dispatchAnaes($t, $f, new RecordAnaesthesia(
        recordId: (string) Str::uuid7(), caseId: $sched, actorId: $a, anaesthesiaType: 'SPINAL',
    )))->toThrow(SurgicalCaseNotEligibleForAnaesthesiaRecord::class);

    ['tenantId' => $t2, 'facilityId' => $f2, 'actorId' => $a2, 'caseId' => $caseId] = seedCaseForAnaesthesia();
    dispatchAnaes($t2, $f2, new RecordAnaesthesia(recordId: (string) Str::uuid7(), caseId: $caseId, actorId: $a2, anaesthesiaType: 'GENERAL'));
    expect(fn () => dispatchAnaes($t2, $f2, new RecordAnaesthesia(
        recordId: (string) Str::uuid7(), caseId: $caseId, actorId: $a2, anaesthesiaType: 'GENERAL',
    )))->toThrow(AnaesthesiaRecordAlreadyExistsForCase::class);
});

test('full lifecycle: DRAFT -> content/timing edit -> SIGNED -> AMENDED; content frozen after SIGNED', function () {
    ['tenantId' => $t, 'facilityId' => $f, 'actorId' => $a, 'caseId' => $caseId] = seedCaseForAnaesthesia();
    $recordId = (string) Str::uuid7();

    dispatchAnaes($t, $f, new RecordAnaesthesia(recordId: $recordId, caseId: $caseId, actorId: $a, anaesthesiaType: 'GENERAL', content: ['v' => 1]));
    dispatchAnaes($t, $f, new UpdateAnaesthesiaContent(
        recordId: $recordId, actorId: $a, content: ['v' => 2],
        startedAt: now()->subHours(2)->toIso8601String(), endedAt: now()->subHour()->toIso8601String(), outcome: 'UNEVENTFUL',
    ));

    $record = TenantContextScope::run($t, $f, fn () => AnaesthesiaRecord::query()->find($recordId));
    expect($record->content)->toEqual(['v' => 2])
        ->and($record->outcome)->toBe('UNEVENTFUL')
        ->and($record->started_at)->not->toBeNull();

    dispatchAnaes($t, $f, new SignAnaesthesiaRecord(recordId: $recordId, actorId: $a));
    $record = TenantContextScope::run($t, $f, fn () => AnaesthesiaRecord::query()->find($recordId));
    expect($record->status)->toBe(AnaesthesiaRecord::STATUS_SIGNED)
        ->and($record->signed_by_user_id)->toBe($a);

    expect(fn () => dispatchAnaes($t, $f, new UpdateAnaesthesiaContent(recordId: $recordId, actorId: $a, content: ['late' => true])))
        ->toThrow(IllegalAnaesthesiaRecordTransition::class);

    dispatchAnaes($t, $f, new AmendAnaesthesiaRecord(
        amendmentId: (string) Str::uuid7(), recordId: $recordId, actorId: $a,
        amendmentText: 'Corrected the recorded fentanyl total.', reason: 'Arithmetic error.',
    ));
    $record = TenantContextScope::run($t, $f, fn () => AnaesthesiaRecord::query()->find($recordId));
    expect($record->status)->toBe(AnaesthesiaRecord::STATUS_AMENDED);

    $amended = Tenancy::run($t, fn () => DB::table('shared_outbox_events')
        ->where('event_class', AnaesthesiaRecordAmended::class)->first());
    expect($amended->payload)->not->toContain('fentanyl')
        ->and($amended->payload)->not->toContain('Arithmetic');
});

test('signing requires theatre.anaesthesia.sign — the record key alone is not enough', function () {
    ['tenantId' => $t, 'facilityId' => $f, 'actorId' => $a, 'caseId' => $caseId] = seedCaseForAnaesthesia(permissions: [
        'theatre.access', 'theatre.anaesthesia.record',
    ]);
    $recordId = (string) Str::uuid7();
    dispatchAnaes($t, $f, new RecordAnaesthesia(recordId: $recordId, caseId: $caseId, actorId: $a, anaesthesiaType: 'GENERAL'));

    expect(fn () => dispatchAnaes($t, $f, new SignAnaesthesiaRecord(recordId: $recordId, actorId: $a)))
        ->toThrow(AuthorizationException::class);
});

test('GetAnaesthesiaRecord / GetAnaesthesiaRecordForCase return the summary + amendment history, or null', function () {
    ['tenantId' => $t, 'facilityId' => $f, 'actorId' => $a, 'caseId' => $caseId] = seedCaseForAnaesthesia();

    $none = TenantContextScope::run($t, $f, fn () => app(QueryBus::class)->dispatch(new GetAnaesthesiaRecordForCase($caseId)));
    expect($none)->toBeNull();

    $recordId = (string) Str::uuid7();
    dispatchAnaes($t, $f, new RecordAnaesthesia(recordId: $recordId, caseId: $caseId, actorId: $a, anaesthesiaType: 'EPIDURAL', content: ['k' => 'v']));
    dispatchAnaes($t, $f, new SignAnaesthesiaRecord(recordId: $recordId, actorId: $a));
    dispatchAnaes($t, $f, new AmendAnaesthesiaRecord(
        amendmentId: (string) Str::uuid7(), recordId: $recordId, actorId: $a, amendmentText: 'Addendum.', reason: 'more detail',
    ));

    $summary = TenantContextScope::run($t, $f, fn () => app(QueryBus::class)->dispatch(new GetAnaesthesiaRecord($recordId)));
    expect($summary)->toBeInstanceOf(AnaesthesiaRecordSummary::class)
        ->and($summary->anaesthesiaType)->toBe('EPIDURAL')
        ->and($summary->status)->toBe(AnaesthesiaRecord::STATUS_AMENDED)
        ->and($summary->content)->toEqual(['k' => 'v'])
        ->and($summary->amendments)->toHaveCount(1)
        ->and($summary->amendments[0]->sequence)->toBe(1);

    $forCase = TenantContextScope::run($t, $f, fn () => app(QueryBus::class)->dispatch(new GetAnaesthesiaRecordForCase($caseId)));
    expect($forCase->anaesthesiaRecordId)->toBe($recordId);
});

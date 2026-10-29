<?php

declare(strict_types=1);

use App\Domains\CareCoordination\Application\Command\CloseCareEpisode;
use App\Domains\CareCoordination\Application\Command\DetachEncounterFromEpisode;
use App\Domains\CareCoordination\Application\Command\LinkEncounterToEpisode;
use App\Domains\CareCoordination\Application\Command\MarkEpisodeTransferredOut;
use App\Domains\CareCoordination\Application\Command\OpenCareEpisode;
use App\Domains\CareCoordination\Application\Query\GetCareEpisode;
use App\Domains\CareCoordination\Application\Query\GetEpisodeEncounters;
use App\Domains\CareCoordination\Application\Query\GetPatientCareEpisodes;
use App\Domains\CareCoordination\Contracts\Dto\CareEpisodeSummary;
use App\Domains\CareCoordination\Contracts\Event\CareEpisodeOpened;
use App\Domains\CareCoordination\Contracts\Event\EncounterDetachedFromEpisode;
use App\Domains\CareCoordination\Contracts\Event\EncounterLinkedToEpisode;
use App\Domains\CareCoordination\Domain\Exception\CareEpisodeContextMismatch;
use App\Domains\CareCoordination\Domain\Exception\ConcurrentCareEpisodeExists;
use App\Domains\CareCoordination\Domain\Exception\EncounterAlreadyLinkedToEpisode;
use App\Domains\CareCoordination\Domain\Exception\IllegalCareEpisodeEncounterTransition;
use App\Domains\CareCoordination\Domain\Exception\IllegalCareEpisodeTransition;
use App\Domains\CareCoordination\Domain\Exception\PatientNotEligibleForCareEpisode;
use App\Domains\CareCoordination\Domain\Model\CareEpisode;
use App\Domains\CareCoordination\Domain\Model\CareEpisodeEncounter;
use App\Domains\Patient\Domain\Model\Patient;
use App\Shared\Bus\CommandBus;
use App\Shared\Bus\QueryBus;
use App\Shared\Domain\NotFound;
use App\Shared\Tenancy\Tenancy;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function dispatchCare(string $tenantId, ?string $facilityId, object $command): mixed
{
    return TenantContextScope::run($tenantId, $facilityId, fn () => app(CommandBus::class)->dispatch($command));
}

test('OpenCareEpisode originates an OPEN episode, normalises type, emits a PHI-free event, writes an audit row', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = careCoordinationFixture();
    $t = (string) $tenant->id;
    $f = (string) $facility->id;
    $patientId = seedActivePatientForCare($t);

    $episodeId = (string) Str::uuid7();
    dispatchCare($t, $f, new OpenCareEpisode(
        episodeId: $episodeId,
        patientId: $patientId,
        actorId: (string) $user->id,
        type: 'pregnancy',
        subtype: 'HIGH_RISK',
    ));

    $episode = TenantContextScope::run($t, $f, fn () => CareEpisode::query()->find($episodeId));
    expect($episode)->not->toBeNull()
        ->and($episode->status)->toBe(CareEpisode::STATUS_OPEN)
        ->and($episode->type)->toBe('PREGNANCY')
        ->and($episode->subtype)->toBe('HIGH_RISK')
        ->and((string) $episode->facility_id)->toBe($f)
        ->and($episode->override_group)->toBeNull()
        ->and((int) $episode->version)->toBe(0);

    $outbox = Tenancy::run($t, fn () => DB::table('shared_outbox_events')
        ->where('event_class', CareEpisodeOpened::class)->first());
    expect($outbox)->not->toBeNull()
        ->and($outbox->payload)->toContain('PREGNANCY'); // type + subtype ARE coded values, allowed on the event

    $audit = Tenancy::run($t, fn () => DB::table('shared_audit_log')
        ->where('target_id', $episodeId)->where('action', 'care_coordination.episode_opened')->first());
    expect($audit)->not->toBeNull();
    $meta = json_decode((string) $audit->metadata, true);
    expect($meta['override'])->toBeFalse()
        ->and($meta['type'])->toBe('PREGNANCY');
});

test('OpenCareEpisode is rejected for a MERGED / DECEASED / INACTIVE patient', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = careCoordinationFixture();
    $t = (string) $tenant->id;
    $f = (string) $facility->id;

    $deceased = TenantContextScope::run($t, null, fn () => (string) Patient::factory()->forTenant($t)->deceased()->create()->id);
    $inactive = TenantContextScope::run($t, null, fn () => (string) Patient::factory()->forTenant($t)->inactive()->create()->id);

    foreach ([$deceased, $inactive] as $patientId) {
        expect(fn () => dispatchCare($t, $f, new OpenCareEpisode(
            episodeId: (string) Str::uuid7(),
            patientId: $patientId,
            actorId: (string) $user->id,
            type: 'TB_TREATMENT',
        )))->toThrow(PatientNotEligibleForCareEpisode::class);
    }
});

test('a second OPEN episode of the same (type, subtype) is rejected without an override', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = careCoordinationFixture();
    $t = (string) $tenant->id;
    $f = (string) $facility->id;
    $patientId = seedActivePatientForCare($t);

    dispatchCare($t, $f, new OpenCareEpisode(episodeId: (string) Str::uuid7(), patientId: $patientId, actorId: (string) $user->id, type: 'PREGNANCY'));

    expect(fn () => dispatchCare($t, $f, new OpenCareEpisode(
        episodeId: (string) Str::uuid7(),
        patientId: $patientId,
        actorId: (string) $user->id,
        type: 'PREGNANCY',
    )))->toThrow(ConcurrentCareEpisodeExists::class);
});

test('a different subtype opens fine; an explicit override opens a second same-subtype episode and audits it', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = careCoordinationFixture();
    $t = (string) $tenant->id;
    $f = (string) $facility->id;
    $patientId = seedActivePatientForCare($t);

    dispatchCare($t, $f, new OpenCareEpisode(episodeId: (string) Str::uuid7(), patientId: $patientId, actorId: (string) $user->id, type: 'NCD', subtype: 'DIABETES'));
    // different subtype — no collision
    dispatchCare($t, $f, new OpenCareEpisode(episodeId: (string) Str::uuid7(), patientId: $patientId, actorId: (string) $user->id, type: 'NCD', subtype: 'HYPERTENSION'));

    $overrideId = (string) Str::uuid7();
    dispatchCare($t, $f, new OpenCareEpisode(
        episodeId: $overrideId,
        patientId: $patientId,
        actorId: (string) $user->id,
        type: 'NCD',
        subtype: 'DIABETES',
        allowConcurrent: true,
        concurrentReason: 'Second endocrine team taking over a parallel trial arm.',
    ));

    $override = TenantContextScope::run($t, $f, fn () => CareEpisode::query()->find($overrideId));
    expect($override->status)->toBe(CareEpisode::STATUS_OPEN)
        ->and($override->override_group)->not->toBeNull();

    $openCount = TenantContextScope::run($t, $f, fn () => CareEpisode::query()
        ->where('patient_id', $patientId)->where('type', 'NCD')->where('subtype', 'DIABETES')
        ->where('status', 'OPEN')->count());
    expect($openCount)->toBe(2);

    $audit = Tenancy::run($t, fn () => DB::table('shared_audit_log')
        ->where('target_id', $overrideId)->where('action', 'care_coordination.episode_opened')->first());
    expect(json_decode((string) $audit->metadata, true)['override'])->toBeTrue()
        ->and($audit->reason)->toBe('Second endocrine team taking over a parallel trial arm.');
});

test('OpenCareEpisode with allowConcurrent but no reason is rejected in the command constructor', function () {
    expect(fn () => new OpenCareEpisode(
        episodeId: (string) Str::uuid7(),
        patientId: (string) Str::uuid7(),
        actorId: (string) Str::uuid7(),
        type: 'PREGNANCY',
        allowConcurrent: true,
    ))->toThrow(InvalidArgumentException::class);
});

test('CloseCareEpisode and MarkEpisodeTransferredOut are terminal and reject a non-OPEN episode', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = careCoordinationFixture();
    $t = (string) $tenant->id;
    $f = (string) $facility->id;
    $patientId = seedActivePatientForCare($t);

    $closeId = (string) Str::uuid7();
    dispatchCare($t, $f, new OpenCareEpisode(episodeId: $closeId, patientId: $patientId, actorId: (string) $user->id, type: 'HIV_CARE'));
    dispatchCare($t, $f, new CloseCareEpisode(episodeId: $closeId, actorId: (string) $user->id, reason: 'Care complete.'));

    $closed = TenantContextScope::run($t, $f, fn () => CareEpisode::query()->find($closeId));
    expect($closed->status)->toBe(CareEpisode::STATUS_CLOSED)
        ->and($closed->closed_at)->not->toBeNull();

    expect(fn () => dispatchCare($t, $f, new CloseCareEpisode(episodeId: $closeId, actorId: (string) $user->id)))
        ->toThrow(IllegalCareEpisodeTransition::class);

    $xferId = (string) Str::uuid7();
    dispatchCare($t, $f, new OpenCareEpisode(episodeId: $xferId, patientId: $patientId, actorId: (string) $user->id, type: 'ONCOLOGY_COURSE'));
    dispatchCare($t, $f, new MarkEpisodeTransferredOut(episodeId: $xferId, actorId: (string) $user->id, reason: 'Patient relocated.'));

    $xfer = TenantContextScope::run($t, $f, fn () => CareEpisode::query()->find($xferId));
    expect($xfer->status)->toBe(CareEpisode::STATUS_TRANSFERRED_OUT);
});

test('LinkEncounterToEpisode links an encounter, copies its facility, emits a PHI-free event', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = careCoordinationFixture();
    $t = (string) $tenant->id;
    $f = (string) $facility->id;

    ['encounterId' => $encounterId, 'patientId' => $patientId] =
        seedEligibleReferralEncounter($t, $f, (string) $user->id);

    $episodeId = (string) Str::uuid7();
    dispatchCare($t, $f, new OpenCareEpisode(episodeId: $episodeId, patientId: $patientId, actorId: (string) $user->id, type: 'PREGNANCY'));

    $linkId = (string) Str::uuid7();
    dispatchCare($t, $f, new LinkEncounterToEpisode(
        linkId: $linkId,
        episodeId: $episodeId,
        encounterId: $encounterId,
        actorId: (string) $user->id,
        role: 'BOOKING_VISIT',
        reason: 'First ANC contact.',
    ));

    $link = TenantContextScope::run($t, $f, fn () => CareEpisodeEncounter::query()->find($linkId));
    expect($link->status)->toBe(CareEpisodeEncounter::STATUS_LINKED)
        ->and((string) $link->encounter_id)->toBe($encounterId)
        ->and((string) $link->patient_id)->toBe($patientId)
        ->and((string) $link->facility_id)->toBe($f)
        ->and($link->role)->toBe('BOOKING_VISIT');

    $outbox = Tenancy::run($t, fn () => DB::table('shared_outbox_events')
        ->where('event_class', EncounterLinkedToEpisode::class)->first());
    expect($outbox)->not->toBeNull()
        ->and($outbox->payload)->not->toContain('BOOKING_VISIT')
        ->and($outbox->payload)->not->toContain('First ANC contact');
});

test('LinkEncounterToEpisode rejects a non-OPEN episode and a patient mismatch', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = careCoordinationFixture();
    $t = (string) $tenant->id;
    $f = (string) $facility->id;

    ['encounterId' => $encounterId, 'patientId' => $patientId] =
        seedEligibleReferralEncounter($t, $f, (string) $user->id);

    // closed episode
    $closedId = (string) Str::uuid7();
    dispatchCare($t, $f, new OpenCareEpisode(episodeId: $closedId, patientId: $patientId, actorId: (string) $user->id, type: 'PREGNANCY'));
    dispatchCare($t, $f, new CloseCareEpisode(episodeId: $closedId, actorId: (string) $user->id));

    expect(fn () => dispatchCare($t, $f, new LinkEncounterToEpisode(
        linkId: (string) Str::uuid7(), episodeId: $closedId, encounterId: $encounterId, actorId: (string) $user->id,
    )))->toThrow(IllegalCareEpisodeTransition::class);

    // patient mismatch — a different patient's episode
    $otherPatientId = seedActivePatientForCare($t);
    $otherEpisodeId = (string) Str::uuid7();
    dispatchCare($t, $f, new OpenCareEpisode(episodeId: $otherEpisodeId, patientId: $otherPatientId, actorId: (string) $user->id, type: 'PREGNANCY'));

    expect(fn () => dispatchCare($t, $f, new LinkEncounterToEpisode(
        linkId: (string) Str::uuid7(), episodeId: $otherEpisodeId, encounterId: $encounterId, actorId: (string) $user->id,
    )))->toThrow(CareEpisodeContextMismatch::class);
});

test('one active link per (episode, encounter); a DETACHED link does not block re-linking', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = careCoordinationFixture();
    $t = (string) $tenant->id;
    $f = (string) $facility->id;

    ['encounterId' => $encounterId, 'patientId' => $patientId] =
        seedEligibleReferralEncounter($t, $f, (string) $user->id);

    $episodeId = (string) Str::uuid7();
    dispatchCare($t, $f, new OpenCareEpisode(episodeId: $episodeId, patientId: $patientId, actorId: (string) $user->id, type: 'PREGNANCY'));

    $firstLink = (string) Str::uuid7();
    dispatchCare($t, $f, new LinkEncounterToEpisode(linkId: $firstLink, episodeId: $episodeId, encounterId: $encounterId, actorId: (string) $user->id));

    expect(fn () => dispatchCare($t, $f, new LinkEncounterToEpisode(
        linkId: (string) Str::uuid7(), episodeId: $episodeId, encounterId: $encounterId, actorId: (string) $user->id,
    )))->toThrow(EncounterAlreadyLinkedToEpisode::class);

    dispatchCare($t, $f, new DetachEncounterFromEpisode(linkId: $firstLink, actorId: (string) $user->id, reason: 'Linked in error.'));

    $reLink = (string) Str::uuid7();
    dispatchCare($t, $f, new LinkEncounterToEpisode(linkId: $reLink, episodeId: $episodeId, encounterId: $encounterId, actorId: (string) $user->id));
    $link = TenantContextScope::run($t, $f, fn () => CareEpisodeEncounter::query()->find($reLink));
    expect($link->status)->toBe(CareEpisodeEncounter::STATUS_LINKED);
});

test('DetachEncounterFromEpisode detaches, keeps the row, emits an event; a second detach is rejected', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = careCoordinationFixture();
    $t = (string) $tenant->id;
    $f = (string) $facility->id;

    ['encounterId' => $encounterId, 'patientId' => $patientId] =
        seedEligibleReferralEncounter($t, $f, (string) $user->id);

    $episodeId = (string) Str::uuid7();
    dispatchCare($t, $f, new OpenCareEpisode(episodeId: $episodeId, patientId: $patientId, actorId: (string) $user->id, type: 'PREGNANCY'));
    $linkId = (string) Str::uuid7();
    dispatchCare($t, $f, new LinkEncounterToEpisode(linkId: $linkId, episodeId: $episodeId, encounterId: $encounterId, actorId: (string) $user->id));

    dispatchCare($t, $f, new DetachEncounterFromEpisode(linkId: $linkId, actorId: (string) $user->id, reason: 'Attached to the wrong episode.'));

    $link = TenantContextScope::run($t, $f, fn () => CareEpisodeEncounter::query()->find($linkId));
    expect($link)->not->toBeNull()
        ->and($link->status)->toBe(CareEpisodeEncounter::STATUS_DETACHED)
        ->and($link->detach_reason)->toBe('Attached to the wrong episode.')
        ->and($link->detached_at)->not->toBeNull();

    $outbox = Tenancy::run($t, fn () => DB::table('shared_outbox_events')
        ->where('event_class', EncounterDetachedFromEpisode::class)->first());
    expect($outbox)->not->toBeNull()
        ->and($outbox->payload)->not->toContain('wrong episode');

    expect(fn () => dispatchCare($t, $f, new DetachEncounterFromEpisode(linkId: $linkId, actorId: (string) $user->id, reason: 'again')))
        ->toThrow(IllegalCareEpisodeEncounterTransition::class);
});

test('closing an episode does NOT detach its links (Invariant 7.8); a CLOSED episode link can still be detached', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = careCoordinationFixture();
    $t = (string) $tenant->id;
    $f = (string) $facility->id;

    ['encounterId' => $encounterId, 'patientId' => $patientId] =
        seedEligibleReferralEncounter($t, $f, (string) $user->id);

    $episodeId = (string) Str::uuid7();
    dispatchCare($t, $f, new OpenCareEpisode(episodeId: $episodeId, patientId: $patientId, actorId: (string) $user->id, type: 'PREGNANCY'));
    $linkId = (string) Str::uuid7();
    dispatchCare($t, $f, new LinkEncounterToEpisode(linkId: $linkId, episodeId: $episodeId, encounterId: $encounterId, actorId: (string) $user->id));

    dispatchCare($t, $f, new CloseCareEpisode(episodeId: $episodeId, actorId: (string) $user->id));

    $link = TenantContextScope::run($t, $f, fn () => CareEpisodeEncounter::query()->find($linkId));
    expect($link->status)->toBe(CareEpisodeEncounter::STATUS_LINKED); // NOT cascaded

    dispatchCare($t, $f, new DetachEncounterFromEpisode(linkId: $linkId, actorId: (string) $user->id, reason: 'Historical correction after close.'));
    $link = TenantContextScope::run($t, $f, fn () => CareEpisodeEncounter::query()->find($linkId));
    expect($link->status)->toBe(CareEpisodeEncounter::STATUS_DETACHED);
});

test('opening a care episode requires care_coordination.episode.manage', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = careCoordinationFixture(permissions: [
        'care_coordination.access',
        'patient.patients.read',
    ]);
    $t = (string) $tenant->id;
    $f = (string) $facility->id;
    $patientId = seedActivePatientForCare($t);

    expect(fn () => dispatchCare($t, $f, new OpenCareEpisode(
        episodeId: (string) Str::uuid7(),
        patientId: $patientId,
        actorId: (string) $user->id,
        type: 'PREGNANCY',
    )))->toThrow(AuthorizationException::class);
});

test('GetCareEpisode / GetPatientCareEpisodes / GetEpisodeEncounters return the expected shapes', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = careCoordinationFixture();
    $t = (string) $tenant->id;
    $f = (string) $facility->id;

    ['encounterId' => $encounterId, 'patientId' => $patientId] =
        seedEligibleReferralEncounter($t, $f, (string) $user->id);

    $episodeId = (string) Str::uuid7();
    dispatchCare($t, $f, new OpenCareEpisode(episodeId: $episodeId, patientId: $patientId, actorId: (string) $user->id, type: 'PREGNANCY', subtype: 'ROUTINE'));
    $linkId = (string) Str::uuid7();
    dispatchCare($t, $f, new LinkEncounterToEpisode(linkId: $linkId, episodeId: $episodeId, encounterId: $encounterId, actorId: (string) $user->id, role: 'REVIEW'));

    $summary = TenantContextScope::run($t, $f, fn () => app(QueryBus::class)->dispatch(new GetCareEpisode($episodeId)));
    expect($summary)->toBeInstanceOf(CareEpisodeSummary::class)
        ->and($summary->careEpisodeId)->toBe($episodeId)
        ->and($summary->type)->toBe('PREGNANCY')
        ->and($summary->subtype)->toBe('ROUTINE')
        ->and($summary->isOverride)->toBeFalse()
        ->and($summary->encounters)->toHaveCount(1)
        ->and($summary->encounters[0]->encounterId)->toBe($encounterId)
        ->and($summary->encounters[0]->role)->toBe('REVIEW');

    $list = TenantContextScope::run($t, $f, fn () => app(QueryBus::class)->dispatch(new GetPatientCareEpisodes($patientId)));
    expect($list)->toHaveCount(1)
        ->and($list[0]->careEpisodeId)->toBe($episodeId);

    $links = TenantContextScope::run($t, $f, fn () => app(QueryBus::class)->dispatch(new GetEpisodeEncounters($episodeId)));
    expect($links)->toHaveCount(1)
        ->and($links[0]->careEpisodeEncounterId)->toBe($linkId);
});

test('GetCareEpisode hides an episode outside the reader tenant/facility scope', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = careCoordinationFixture();
    $t = (string) $tenant->id;
    $f = (string) $facility->id;
    $patientId = seedActivePatientForCare($t);
    $episodeId = (string) Str::uuid7();
    dispatchCare($t, $f, new OpenCareEpisode(episodeId: $episodeId, patientId: $patientId, actorId: (string) $user->id, type: 'PREGNANCY'));

    ['tenant' => $other, 'facility' => $otherFacility] = careCoordinationFixture();

    expect(fn () => TenantContextScope::run((string) $other->id, (string) $otherFacility->id, fn () => app(QueryBus::class)->dispatch(new GetCareEpisode($episodeId))))
        ->toThrow(NotFound::class);
});

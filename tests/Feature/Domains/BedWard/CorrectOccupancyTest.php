<?php

declare(strict_types=1);

use App\Domains\BedWard\Application\Command\AssignBed;
use App\Domains\BedWard\Application\Command\CorrectOccupancy;
use App\Domains\BedWard\Application\Command\CorrectOccupancyResult;
use App\Domains\BedWard\Contracts\Event\OccupancyCorrected;
use App\Domains\BedWard\Domain\Model\BedOccupancy;
use App\Domains\BedWard\Domain\ValueObject\BedEndReason;
use App\Shared\Bus\CommandBus;
use App\Shared\Tenancy\Tenancy;
use App\Shared\Tenancy\TenantContextScope;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

test('it corrects occupancy details: bounded mutation, diff computed, event on outbox, audit written', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = bedWardFixture();

    $ward = createWardFixture((string) $tenant->id, (string) $facility->id);
    $bed = createBedFixture((string) $tenant->id, (string) $facility->id, (string) $ward->id);

    ['encounterId' => $encounterId] =
        seedActiveInpatientEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);

    $occupancyId = (string) Str::uuid7();
    TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => app(CommandBus::class)->dispatch(new AssignBed(
        occupancyId: $occupancyId,
        bedId: (string) $bed->id,
        encounterId: $encounterId,
        actorId: (string) $user->id,
    )));

    $newOccurredAt = CarbonImmutable::parse('2026-09-01 10:00:00+00');
    $correctCmd = new CorrectOccupancy(
        occupancyId: $occupancyId,
        actorId: (string) $user->id,
        changes: [
            'occurred_at' => $newOccurredAt->toIso8601String(),
        ],
        reason: 'Typo in check-in time recorded on paper chart',
    );

    /** @var CorrectOccupancyResult $result */
    $result = TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => app(CommandBus::class)->dispatch($correctCmd));

    expect($result->occupancyId)->toBe($occupancyId)
        ->and($result->changedFields)->toContain('occurred_at');

    // Occupancy updated
    $occ = TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => BedOccupancy::query()->find($occupancyId));
    expect($occ)->not->toBeNull()
        ->and((int) $occ->version)->toBe(1);

    // Outbox event
    $outbox = Tenancy::run((string) $tenant->id, fn () => DB::table('shared_outbox_events')
        ->where('event_class', OccupancyCorrected::class)
        ->first());

    expect($outbox)->not->toBeNull()
        ->and($outbox->event_name)->toBe('bed_ward.OccupancyCorrected');

    $payload = json_decode((string) $outbox->payload, true);
    expect($payload['occupancyId'])->toBe($occupancyId)
        ->and($payload['changedFields'])->toContain('occurred_at');

    // Audit log contains the diff
    $audit = Tenancy::run((string) $tenant->id, fn () => DB::table('shared_audit_log')
        ->where('target_id', $occupancyId)
        ->where('action', 'bed_ward.occupancy_corrected')
        ->first());

    expect($audit)->not->toBeNull()
        ->and($audit->reason)->toBe('Typo in check-in time recorded on paper chart');

    $auditMeta = json_decode((string) $audit->metadata, true);
    expect($auditMeta)->toBeArray()
        ->and($auditMeta['changed_fields'])->toContain('occurred_at');
});

test('it rejects attempt to modify immutable fields in CorrectOccupancy', function () {
    ['user' => $user] = bedWardFixture();

    $occupancyId = (string) Str::uuid7();

    expect(fn () => new CorrectOccupancy(
        occupancyId: $occupancyId,
        actorId: (string) $user->id,
        changes: [
            'patient_id' => (string) Str::uuid7(),
        ],
        reason: 'Illegal modification',
    ))->toThrow(InvalidArgumentException::class, 'CorrectOccupancy: unpermitted fields [patient_id]');
});

test('CorrectOccupancy requires bed_ward.occupancy.correct permission', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = bedWardFixture(permissions: ['bed_ward.access']);

    $occupancy = TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => BedOccupancy::factory()
        ->forTenant((string) $tenant->id)
        ->atFacility((string) $facility->id)
        ->create());

    $cmd = new CorrectOccupancy(
        occupancyId: (string) $occupancy->id,
        actorId: (string) $user->id,
        changes: [
            'end_reason' => BedEndReason::DISCHARGE,
        ],
        reason: 'Attempt without permission',
    );

    expect(fn () => TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => app(CommandBus::class)->dispatch($cmd)))
        ->toThrow(AuthorizationException::class);
});

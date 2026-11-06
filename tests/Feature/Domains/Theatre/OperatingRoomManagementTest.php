<?php

declare(strict_types=1);

use App\Domains\Organization\Domain\Model\Facility;
use App\Domains\Theatre\Application\Command\AssignOperatingRoom;
use App\Domains\Theatre\Application\Command\CancelCase;
use App\Domains\Theatre\Application\Command\CreateOperatingRoom;
use App\Domains\Theatre\Application\Command\ScheduleSurgicalCase;
use App\Domains\Theatre\Contracts\Event\SurgicalCaseCancelled;
use App\Domains\Theatre\Contracts\Event\SurgicalCaseScheduled;
use App\Domains\Theatre\Domain\Exception\OperatingRoomReferencesUnknownRoom;
use App\Domains\Theatre\Domain\Model\OperatingRoom;
use App\Domains\Theatre\Domain\Model\SurgicalCase;
use App\Shared\Bus\CommandBus;
use App\Shared\Tenancy\Tenancy;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

test('it registers an operating room for an organization room and audits the setup', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = theatreFixture();
    $organizationRoom = createOrgRoomFixture((string) $tenant->id, (string) $facility->id);
    $operatingRoomId = (string) Str::uuid7();

    TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => app(CommandBus::class)->dispatch(new CreateOperatingRoom(
        operatingRoomId: $operatingRoomId,
        facilityId: (string) $facility->id,
        orgRoomId: (string) $organizationRoom->id,
        actorId: (string) $user->id,
        label: 'Theatre A',
    )));

    $room = TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => OperatingRoom::query()->find($operatingRoomId));
    expect($room)->not->toBeNull()
        ->and((string) $room?->facility_id)->toBe((string) $facility->id)
        ->and((string) $room?->org_room_id)->toBe((string) $organizationRoom->id)
        ->and($room?->label)->toBe('Theatre A')
        ->and($room?->status)->toBe(OperatingRoom::STATUS_AVAILABLE);

    $audit = Tenancy::run((string) $tenant->id, fn () => DB::table('shared_audit_log')
        ->where('target_id', $operatingRoomId)
        ->where('action', 'theatre.operating_room_created')
        ->first());
    expect($audit)->not->toBeNull();
});

test('it rejects operating room setup without room management permission', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = theatreFixture(permissions: ['theatre.access']);
    $organizationRoom = createOrgRoomFixture((string) $tenant->id, (string) $facility->id);
    $operatingRoomId = (string) Str::uuid7();

    expect(fn () => TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => app(CommandBus::class)->dispatch(new CreateOperatingRoom(
        operatingRoomId: $operatingRoomId,
        facilityId: (string) $facility->id,
        orgRoomId: (string) $organizationRoom->id,
        actorId: (string) $user->id,
        label: 'Unauthorized Theatre',
    ))))->toThrow(AuthorizationException::class);

    $room = TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => OperatingRoom::query()->find($operatingRoomId));
    expect($room)->toBeNull();

    $auditCount = Tenancy::run((string) $tenant->id, fn () => DB::table('shared_audit_log')
        ->where('target_id', $operatingRoomId)
        ->where('action', 'theatre.operating_room_created')
        ->count());
    expect($auditCount)->toBe(0);
});

test('it rejects registering an operating room against another facility room', function () {
    ['tenant' => $tenant, 'organization' => $organization, 'facility' => $facility, 'user' => $user] = theatreFixture();
    $otherFacility = Facility::factory()->forTenant((string) $tenant->id, (string) $organization->id)->create();
    $organizationRoom = createOrgRoomFixture((string) $tenant->id, (string) $otherFacility->id);
    $operatingRoomId = (string) Str::uuid7();

    expect(fn () => TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => app(CommandBus::class)->dispatch(new CreateOperatingRoom(
        operatingRoomId: $operatingRoomId,
        facilityId: (string) $facility->id,
        orgRoomId: (string) $organizationRoom->id,
        actorId: (string) $user->id,
        label: 'Cross-facility Theatre',
    ))))->toThrow(OperatingRoomReferencesUnknownRoom::class);

    $room = TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => OperatingRoom::query()->find($operatingRoomId));
    expect($room)->toBeNull();
});

test('it releases the reserved room when a scheduled case is cancelled', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = theatreFixture();
    $tenantId = (string) $tenant->id;
    $facilityId = (string) $facility->id;
    $actorId = (string) $user->id;
    $room = createOperatingRoomFixture($tenantId, $facilityId);
    $case = createSurgicalCaseFixture(
        $tenantId,
        $facilityId,
        (string) Str::uuid7(),
        (string) Str::uuid7(),
    );
    $scheduledFor = '2026-11-02T12:00:00+03:00';

    TenantContextScope::run($tenantId, $facilityId, function () use ($room, $case, $actorId, $scheduledFor): void {
        app(CommandBus::class)->dispatch(new AssignOperatingRoom(
            operatingRoomId: (string) $room->id,
            caseId: (string) $case->id,
            actorId: $actorId,
        ));
        app(CommandBus::class)->dispatch(new ScheduleSurgicalCase(
            caseId: (string) $case->id,
            actorId: $actorId,
            scheduledFor: $scheduledFor,
        ));
        app(CommandBus::class)->dispatch(new CancelCase(
            caseId: (string) $case->id,
            actorId: $actorId,
            reason: 'Scheduling change',
        ));
    });

    $freshCase = TenantContextScope::run($tenantId, $facilityId, fn () => SurgicalCase::query()->find($case->id));
    $freshRoom = TenantContextScope::run($tenantId, $facilityId, fn () => OperatingRoom::query()->find($room->id));
    expect($freshCase?->status)->toBe(SurgicalCase::STATUS_CANCELLED)
        ->and($freshRoom?->status)->toBe(OperatingRoom::STATUS_AVAILABLE);

    $scheduledEvent = Tenancy::run($tenantId, fn () => DB::table('shared_outbox_events')
        ->where('event_class', SurgicalCaseScheduled::class)
        ->first());
    expect($scheduledEvent)->not->toBeNull();

    $cancelledEvent = Tenancy::run($tenantId, fn () => DB::table('shared_outbox_events')
        ->where('event_class', SurgicalCaseCancelled::class)
        ->first());
    expect($cancelledEvent)->not->toBeNull();

    $audit = Tenancy::run($tenantId, fn () => DB::table('shared_audit_log')
        ->where('target_id', (string) $case->id)
        ->where('action', 'theatre.case_cancelled')
        ->first());
    expect($audit)->not->toBeNull()
        ->and($audit?->reason)->toBe('Scheduling change');
});

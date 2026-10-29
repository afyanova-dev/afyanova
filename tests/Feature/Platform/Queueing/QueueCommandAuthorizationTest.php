<?php

declare(strict_types=1);

namespace Tests\Feature\Platform\Queueing;

use App\Domains\Identity\Domain\Model\AccessAssignment;
use App\Domains\Identity\Domain\Model\Permission;
use App\Domains\Identity\Domain\Model\Role;
use App\Models\User;
use App\Platform\Queueing\Application\Command\CallNextTicketCommand;
use App\Platform\Queueing\Application\Command\CallTicketCommand;
use App\Platform\Queueing\Application\Command\CancelEncounterQueueEntriesCommand;
use App\Platform\Queueing\Application\Command\CloseQueueCommand;
use App\Platform\Queueing\Application\Command\CompleteTicketServiceCommand;
use App\Platform\Queueing\Application\Command\PauseQueueCommand;
use App\Platform\Queueing\Application\Command\PlaceEncounterInQueueCommand;
use App\Platform\Queueing\Application\Command\PlaceQueueEntryOnHoldCommand;
use App\Platform\Queueing\Application\Command\RecallTicketCommand;
use App\Platform\Queueing\Application\Command\RecordLeftWithoutBeingSeenCommand;
use App\Platform\Queueing\Application\Command\RecordPatientMovementCommand;
use App\Platform\Queueing\Application\Command\RecordTicketNoResponseCommand;
use App\Platform\Queueing\Application\Command\RegisterQueueCommand;
use App\Platform\Queueing\Application\Command\ReleasePatientFinancialHoldCommand;
use App\Platform\Queueing\Application\Command\ReleaseQueueEntryHoldCommand;
use App\Platform\Queueing\Application\Command\ResumeQueueCommand;
use App\Platform\Queueing\Application\Command\SkipTicketCommand;
use App\Platform\Queueing\Application\Command\StartTicketServiceCommand;
use App\Platform\Queueing\Application\Command\TransferQueueEntryCommand;
use App\Platform\Queueing\Application\Command\UpdateTicketPriorityCommand;
use App\Platform\Queueing\Contracts\DTO\TransferQueueDto;
use App\Platform\Queueing\Contracts\QueueingApi;
use App\Platform\Queueing\Domain\Exception\QueueNotFoundException;
use App\Platform\Queueing\Domain\Model\QueueEntry;
use App\Shared\Bus\AuthorizesAgainst;
use App\Shared\Bus\CommandBus;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function restrictedQueueUser(array $fixture): User
{
    $tenantId = (string) $fixture['tenant']->id;
    $facilityId = (string) $fixture['facility']->id;

    return TenantContextScope::run($tenantId, null, function () use ($tenantId, $facilityId): User {
        $user = User::factory()->create([
            'tenant_id' => $tenantId,
            'status' => 'ACTIVE',
        ]);
        $role = Role::query()->firstOrCreate(
            ['tenant_id' => $tenantId, 'key' => 'queue_viewer'],
            ['name' => 'Queue Viewer', 'status' => 'ACTIVE', 'scope_type' => 'FACILITY'],
        );
        foreach (['queue.access', 'queue.ticket.view'] as $key) {
            $perm = Permission::query()->firstOrCreate(['key' => $key]);
            $role->permissions()->syncWithoutDetaching([
                $perm->id => ['id' => (string) Str::uuid7(), 'tenant_id' => $tenantId],
            ]);
        }
        AccessAssignment::query()->create([
            'tenant_id' => $tenantId,
            'user_id' => $user->id,
            'role_id' => $role->id,
            'facility_id' => $facilityId,
            'status' => 'ACTIVE',
            'is_primary' => true,
        ]);

        return $user;
    });
}

test('user-initiated queue commands carry bus authorization', function (): void {
    $entryId = (string) Str::uuid7();
    $queueId = (string) Str::uuid7();

    expect(new PlaceEncounterInQueueCommand(encounterId: $entryId, patientId: $entryId, queueId: $queueId))
        ->toBeInstanceOf(AuthorizesAgainst::class);
    expect(new CallTicketCommand(entryId: $entryId, roomId: $queueId))
        ->toBeInstanceOf(AuthorizesAgainst::class);
    expect(new RecallTicketCommand(entryId: $entryId))->toBeInstanceOf(AuthorizesAgainst::class);
    expect(new StartTicketServiceCommand($entryId))->toBeInstanceOf(AuthorizesAgainst::class);
    expect(new CompleteTicketServiceCommand($entryId))->toBeInstanceOf(AuthorizesAgainst::class);
    expect(new PlaceQueueEntryOnHoldCommand($entryId, 'CLINICAL_HOLD', 'note'))
        ->toBeInstanceOf(AuthorizesAgainst::class);
    expect(new ReleaseQueueEntryHoldCommand($entryId, 'clearance'))
        ->toBeInstanceOf(AuthorizesAgainst::class);
    expect(new RecordTicketNoResponseCommand($entryId))->toBeInstanceOf(AuthorizesAgainst::class);
    expect(new RecordLeftWithoutBeingSeenCommand($entryId))->toBeInstanceOf(AuthorizesAgainst::class);
    expect(new SkipTicketCommand($entryId))->toBeInstanceOf(AuthorizesAgainst::class);
    expect(new TransferQueueEntryCommand($entryId, $queueId))->toBeInstanceOf(AuthorizesAgainst::class);
    expect(new PauseQueueCommand($queueId, 'reason'))->toBeInstanceOf(AuthorizesAgainst::class);
    expect(new ResumeQueueCommand($queueId))->toBeInstanceOf(AuthorizesAgainst::class);
    expect(new CloseQueueCommand($queueId))->toBeInstanceOf(AuthorizesAgainst::class);
    expect(new RegisterQueueCommand('f', 'C', 'N'))->toBeInstanceOf(AuthorizesAgainst::class);
});

test('system and internal commands stay outside bus authorization', function (): void {
    $entryId = (string) Str::uuid7();

    // Dispatched by event listeners or internal flows with no user principal;
    // authorizing them at the bus would break system reactions.
    expect(new UpdateTicketPriorityCommand($entryId, 'ROUTINE', 'provenance'))
        ->not->toBeInstanceOf(AuthorizesAgainst::class);
    expect(new CancelEncounterQueueEntriesCommand($entryId, 'reason'))
        ->not->toBeInstanceOf(AuthorizesAgainst::class);
    expect(new CallNextTicketCommand($entryId, $entryId))
        ->not->toBeInstanceOf(AuthorizesAgainst::class);
    expect(new RecordPatientMovementCommand(
        encounterId: $entryId,
        patientId: $entryId,
        toArea: 'room',
    ))->not->toBeInstanceOf(AuthorizesAgainst::class);
    expect(new ReleasePatientFinancialHoldCommand(
        patientId: $entryId,
        paymentId: $entryId,
    ))->not->toBeInstanceOf(AuthorizesAgainst::class);
});

test('a viewer cannot mutate tickets through the bus', function (): void {
    $fixture = queueingFixture();
    $tenantId = (string) $fixture['tenant']->id;
    $facilityId = (string) $fixture['facility']->id;
    $queue = queueTestQueue($tenantId, $facilityId, 'VWR');
    $entry = queueTestEntry($tenantId, $facilityId, (string) $queue->id);
    $viewer = restrictedQueueUser($fixture);

    $this->actingAs($viewer);

    $denied = [
        new CallTicketCommand($entry->id, (string) Str::uuid7()),
        new RecallTicketCommand($entry->id),
        new StartTicketServiceCommand($entry->id),
        new CompleteTicketServiceCommand($entry->id),
        new PlaceQueueEntryOnHoldCommand($entry->id, 'CLINICAL_HOLD', 'note'),
        new ReleaseQueueEntryHoldCommand($entry->id, 'clearance'),
        new RecordTicketNoResponseCommand($entry->id),
        new RecordLeftWithoutBeingSeenCommand($entry->id),
        new SkipTicketCommand($entry->id),
        new TransferQueueEntryCommand($entry->id, (string) $queue->id),
        new PauseQueueCommand((string) $queue->id, 'reason'),
        new ResumeQueueCommand((string) $queue->id),
        new CloseQueueCommand((string) $queue->id),
    ];

    foreach ($denied as $command) {
        expect(fn () => TenantContextScope::run(
            $tenantId,
            $facilityId,
            fn () => app(CommandBus::class)->dispatch($command),
        ))->toThrow(AuthorizationException::class);
    }

    expect(TenantContextScope::run(
        $tenantId,
        $facilityId,
        fn (): string => QueueEntry::query()->where('id', $entry->id)->value('status')->value,
    ))->toBe('WAITING');
});

test('calling an entry from another facility is denied at the bus', function (): void {
    $fixture = queueingFixture();
    $tenantId = (string) $fixture['tenant']->id;
    $facilityAId = (string) $fixture['facility']->id;
    $facilityB = queueTestSecondFacility($tenantId, (string) $fixture['organization']->id, (string) $fixture['user']->id, (string) $fixture['role_id']);
    $queueB = queueTestQueue($tenantId, (string) $facilityB->id, 'VXF');
    $entryB = queueTestEntry($tenantId, (string) $facilityB->id, (string) $queueB->id);

    expect(fn () => TenantContextScope::run(
        $tenantId,
        $facilityAId,
        fn () => app(CommandBus::class)->dispatch(new CallTicketCommand($entryB->id, (string) Str::uuid7())),
    ))->toThrow(AuthorizationException::class);
});

test('transferring into a foreign queue reads as not found', function (): void {
    $fixture = queueingFixture();
    $tenantId = (string) $fixture['tenant']->id;
    $facilityAId = (string) $fixture['facility']->id;
    $queueA = queueTestQueue($tenantId, $facilityAId, 'TSA');
    $entry = queueTestEntry($tenantId, $facilityAId, (string) $queueA->id);
    $facilityB = queueTestSecondFacility($tenantId, (string) $fixture['organization']->id, (string) $fixture['user']->id, (string) $fixture['role_id']);
    $queueB = queueTestQueue($tenantId, (string) $facilityB->id, 'TSB');

    expect(fn () => TenantContextScope::run(
        $tenantId,
        $facilityAId,
        fn () => app(QueueingApi::class)->transfer(new TransferQueueDto(
            entryId: $entry->id,
            destinationQueueId: (string) $queueB->id,
        )),
    ))->toThrow(QueueNotFoundException::class);
});

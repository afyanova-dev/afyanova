<?php

declare(strict_types=1);

namespace Tests\Feature\Platform\Queueing;

use App\Domains\Organization\Domain\Model\Facility;
use App\Platform\Queueing\Application\Command\PlaceEncounterInQueueCommand;
use App\Platform\Queueing\Domain\Enum\QueueOperatingStatus;
use App\Platform\Queueing\Domain\Exception\QueueNotFoundException;
use App\Platform\Queueing\Domain\Exception\QueueNotOperatingException;
use App\Platform\Queueing\Domain\Model\Queue;
use App\Platform\Queueing\Domain\Model\QueueEntry;
use App\Shared\Bus\CommandBus;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function queueScopeFixture(array $permissions = ['queue.access', 'queue.ticket.view', 'queue.ticket.place']): array
{
    $fixture = queueingFixture($permissions);

    return $fixture;
}

function makeQueue(string $tenantId, string $facilityId, bool $active = true, QueueOperatingStatus $status = QueueOperatingStatus::ACTIVE): Queue
{
    return TenantContextScope::run($tenantId, $facilityId, function () use ($tenantId, $facilityId, $active, $status): Queue {
        $queue = new Queue([
            'tenant_id' => $tenantId,
            'facility_id' => $facilityId,
            'code' => 'SCOPE-'.strtoupper(Str::random(6)),
            'name' => 'Scope Test Queue',
            'ticket_prefix' => 'SQ',
            'is_active' => $active,
            'operating_status' => $status,
        ]);
        $queue->save();

        return $queue;
    });
}

function makeSecondFacility(string $tenantId, string $orgId, string $userId, string $roleId): Facility
{
    return TenantContextScope::run($tenantId, null, function () use ($tenantId, $orgId, $userId, $roleId): Facility {
        $facility = Facility::factory()->forTenant($tenantId, $orgId)->create();

        DB::table('idn_access_assignments')->insert([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'role_id' => $roleId,
            'facility_id' => $facility->id,
            'status' => 'ACTIVE',
            'is_primary' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $facility;
    });
}

test('a facility-A context cannot place an encounter in a facility-B queue by forging its id', function (): void {
    $fixture = queueScopeFixture();
    $tenantId = (string) $fixture['tenant']->id;
    $facilityAId = (string) $fixture['facility']->id;
    $facilityB = makeSecondFacility($tenantId, (string) $fixture['organization']->id, (string) $fixture['user']->id, (string) $fixture['role_id']);
    $facilityBId = (string) $facilityB->id;

    $queueB = makeQueue($tenantId, $facilityBId);

    // Forged placement from facility A into B's queue must fail without disclosing B's queue.
    try {
        TenantContextScope::run($tenantId, $facilityAId, fn () => app(CommandBus::class)->dispatch(
            new PlaceEncounterInQueueCommand(
                encounterId: (string) Str::uuid7(),
                patientId: (string) Str::uuid7(),
                queueId: (string) $queueB->id,
            ),
        ));
        expect(false)->toBeTrue('cross-facility placement should have been rejected');
    } catch (AuthorizationException|QueueNotFoundException $e) {
        expect($e->getMessage())->not->toContain('Scope Test Queue');
    }

    expect(TenantContextScope::run(
        $tenantId,
        $facilityBId,
        fn (): int => QueueEntry::query()->where('queue_id', $queueB->id)->count(),
    ))->toBe(0);
});

test('an inactive queue cannot be placed into by id', function (): void {
    $fixture = queueScopeFixture();
    $tenantId = (string) $fixture['tenant']->id;
    $facilityId = (string) $fixture['facility']->id;

    $queue = makeQueue($tenantId, $facilityId, active: false);

    expect(fn () => TenantContextScope::run($tenantId, $facilityId, fn () => app(CommandBus::class)->dispatch(
        new PlaceEncounterInQueueCommand(
            encounterId: (string) Str::uuid7(),
            patientId: (string) Str::uuid7(),
            queueId: (string) $queue->id,
        ),
    )))->toThrow(QueueNotOperatingException::class);

    expect(TenantContextScope::run(
        $tenantId,
        $facilityId,
        fn (): int => QueueEntry::query()->where('queue_id', $queue->id)->count(),
    ))->toBe(0);
});

test('same-facility active placement still creates a ticket and a movement', function (): void {
    $fixture = queueScopeFixture();
    $tenantId = (string) $fixture['tenant']->id;
    $facilityId = (string) $fixture['facility']->id;

    $queue = makeQueue($tenantId, $facilityId);
    $encounterId = (string) Str::uuid7();
    $patientId = (string) Str::uuid7();

    $dto = TenantContextScope::run($tenantId, $facilityId, fn () => app(CommandBus::class)->dispatch(
        new PlaceEncounterInQueueCommand(
            encounterId: $encounterId,
            patientId: $patientId,
            queueId: (string) $queue->id,
        ),
    ));

    expect($dto->ticketNumber)->not->toBe('')
        ->and($dto->queueId)->toBe((string) $queue->id);

    TenantContextScope::run($tenantId, $facilityId, function () use ($queue, $encounterId): void {
        expect(QueueEntry::query()->where('queue_id', $queue->id)->where('encounter_id', $encounterId)->count())->toBe(1);
        expect(DB::table('qms_patient_movements')->where('queue_entry_id', QueueEntry::query()->where('encounter_id', $encounterId)->value('id'))->count())->toBe(1);
    });
});

test('a closed queue preserves existing closed handling', function (): void {
    $fixture = queueScopeFixture();
    $tenantId = (string) $fixture['tenant']->id;
    $facilityId = (string) $fixture['facility']->id;

    $queue = makeQueue($tenantId, $facilityId, status: QueueOperatingStatus::CLOSED);

    expect(fn () => TenantContextScope::run($tenantId, $facilityId, fn () => app(CommandBus::class)->dispatch(
        new PlaceEncounterInQueueCommand(
            encounterId: (string) Str::uuid7(),
            patientId: (string) Str::uuid7(),
            queueId: (string) $queue->id,
        ),
    )))->toThrow(QueueNotOperatingException::class);
});

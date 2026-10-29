<?php

declare(strict_types=1);

namespace Tests\Feature\Platform\Queueing;

use App\Domains\Organization\Domain\Model\Facility;
use App\Platform\Queueing\Application\Command\PlaceEncounterInQueueCommand;
use App\Platform\Queueing\Domain\Enum\QueueOperatingStatus;
use App\Platform\Queueing\Domain\Model\Queue;
use App\Platform\Queueing\Domain\Model\QueueEntry;
use App\Shared\Bus\CommandBus;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function queueForPlacementAuthorization(string $tenantId, string $facilityId): Queue
{
    return TenantContextScope::run($tenantId, $facilityId, function () use ($tenantId, $facilityId): Queue {
        $queue = new Queue([
            'tenant_id' => $tenantId,
            'facility_id' => $facilityId,
            'code' => 'AUTH-'.strtoupper(Str::random(6)),
            'name' => 'Authorization Test Queue',
            'ticket_prefix' => 'AQ',
            'is_active' => true,
            'operating_status' => QueueOperatingStatus::ACTIVE,
        ]);
        $queue->save();

        return $queue;
    });
}

test('a direct queue-placement command requires queue.ticket.place', function (): void {
    $fixture = queueingFixture(['queue.access', 'queue.ticket.view']);
    $tenantId = (string) $fixture['tenant']->id;
    $facilityId = (string) $fixture['facility']->id;
    $queue = queueForPlacementAuthorization($tenantId, $facilityId);

    expect(fn () => TenantContextScope::run($tenantId, $facilityId, fn () => app(CommandBus::class)->dispatch(
        new PlaceEncounterInQueueCommand(
            encounterId: (string) Str::uuid7(),
            patientId: (string) Str::uuid7(),
            queueId: (string) $queue->id,
        ),
    )))->toThrow(AuthorizationException::class);

    expect(TenantContextScope::run(
        $tenantId,
        $facilityId,
        fn (): int => QueueEntry::query()->where('queue_id', $queue->id)->count(),
    ))->toBe(0);
});

test('queue placement is limited to the active facility even when the user can work at both facilities', function (): void {
    $fixture = queueingFixture(['queue.access', 'queue.ticket.view', 'queue.ticket.place']);
    $tenantId = (string) $fixture['tenant']->id;
    $facilityAId = (string) $fixture['facility']->id;
    $queue = queueForPlacementAuthorization($tenantId, $facilityAId);

    $facilityB = TenantContextScope::run($tenantId, null, function () use ($fixture, $tenantId): Facility {
        $facility = Facility::factory()
            ->forTenant($tenantId, (string) $fixture['organization']->id)
            ->create();

        DB::table('idn_access_assignments')->insert([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantId,
            'user_id' => $fixture['user']->id,
            'role_id' => $fixture['role_id'],
            'facility_id' => $facility->id,
            'status' => 'ACTIVE',
            'is_primary' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $facility;
    });

    expect(fn () => TenantContextScope::run($tenantId, (string) $facilityB->id, fn () => app(CommandBus::class)->dispatch(
        new PlaceEncounterInQueueCommand(
            encounterId: (string) Str::uuid7(),
            patientId: (string) Str::uuid7(),
            queueId: (string) $queue->id,
        ),
    )))->toThrow(AuthorizationException::class);

    expect(TenantContextScope::run(
        $tenantId,
        $facilityAId,
        fn (): int => QueueEntry::query()->where('queue_id', $queue->id)->count(),
    ))->toBe(0);
});

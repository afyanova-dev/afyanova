<?php

declare(strict_types=1);

use App\Platform\Queueing\Contracts\DTO\CallNextDto;
use App\Platform\Queueing\Contracts\DTO\PlaceInQueueDto;
use App\Platform\Queueing\Contracts\QueueingApi;
use App\Platform\Queueing\Domain\Enum\QueueStatus;
use App\Platform\Queueing\Domain\Model\Queue;
use App\Platform\Queueing\Domain\Repository\QueueRepository;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Support\Str;

require_once __DIR__.'/../../../../tests/Support/queueing.php';

it('records patient left without being seen (LWBS) and renders ticket terminal and uncallable', function (): void {
    $fx = queueingFixture();
    $tenantId = (string) $fx['tenant']->id;
    $facilityId = (string) $fx['facility']->id;

    $api = app(QueueingApi::class);
    $queueRepo = app(QueueRepository::class);

    $queue = new Queue([
        'tenant_id' => $tenantId,
        'facility_id' => $facilityId,
        'code' => 'OPD_LWBS',
        'name' => 'OPD LWBS Test',
        'ticket_prefix' => 'L',
        'is_active' => true,
    ]);
    TenantContextScope::run($tenantId, $facilityId, fn () => $queueRepo->save($queue));

    $encounter1Id = (string) Str::uuid();
    $patient1Id = (string) Str::uuid();
    $encounter2Id = (string) Str::uuid();
    $patient2Id = (string) Str::uuid();

    // 1. Place two tickets
    $ticket1 = TenantContextScope::run($tenantId, $facilityId, fn () => $api->place(new PlaceInQueueDto(
        encounterId: $encounter1Id,
        patientId: $patient1Id,
        queueId: $queue->id,
    )));

    $ticket2 = TenantContextScope::run($tenantId, $facilityId, fn () => $api->place(new PlaceInQueueDto(
        encounterId: $encounter2Id,
        patientId: $patient2Id,
        queueId: $queue->id,
    )));

    // 2. Ticket 1 leaves without being seen
    $lwbs = TenantContextScope::run($tenantId, $facilityId, fn () => $api->recordLeftWithoutBeingSeen(
        entryId: $ticket1->id,
        reason: 'Patient had to leave for urgent personal matter',
    ));

    expect($lwbs->status)->toBe(QueueStatus::LEFT_WITHOUT_BEING_SEEN->value)
        ->and($lwbs->completedAt)->not->toBeNull();

    // 3. Active check for encounter1 is now null
    $active1 = TenantContextScope::run($tenantId, $facilityId, fn () => $api->getActiveForEncounter($encounter1Id));
    expect($active1)->toBeNull();

    // 4. Calling next ticket must skip ticket 1 and immediately call ticket 2
    $called = TenantContextScope::run($tenantId, $facilityId, fn () => $api->callNext(new CallNextDto(
        queueId: $queue->id,
        roomId: (string) Str::uuid(),
    )));

    expect($called)->not->toBeNull()
        ->and($called->id)->toBe($ticket2->id);

    // 5. Calling again returns null
    $calledAgain = TenantContextScope::run($tenantId, $facilityId, fn () => $api->callNext(new CallNextDto(
        queueId: $queue->id,
        roomId: (string) Str::uuid(),
    )));
    expect($calledAgain)->toBeNull();

    // 6. Wait metrics reflect 1 LWBS ticket
    $metrics = TenantContextScope::run($tenantId, $facilityId, fn () => $api->getQueueWaitMetrics($queue->id));
    expect($metrics->lwbsCount)->toBe(1)
        ->and($metrics->activeTicketsCount)->toBe(1);
});

<?php

declare(strict_types=1);

use App\Platform\Queueing\Contracts\DTO\CallNextDto;
use App\Platform\Queueing\Contracts\DTO\PlaceInQueueDto;
use App\Platform\Queueing\Contracts\DTO\RecallTicketDto;
use App\Platform\Queueing\Contracts\QueueingApi;
use App\Platform\Queueing\Domain\Enum\QueueStatus;
use App\Platform\Queueing\Domain\Model\Queue;
use App\Platform\Queueing\Domain\Repository\QueueRepository;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Support\Str;

require_once __DIR__.'/../../../../tests/Support/queueing.php';

it('allows skipping a called ticket and recalling it when patient returns', function (): void {
    $fx = queueingFixture();
    $tenantId = (string) $fx['tenant']->id;
    $facilityId = (string) $fx['facility']->id;

    $api = app(QueueingApi::class);
    $queueRepo = app(QueueRepository::class);

    $queue = new Queue([
        'tenant_id' => $tenantId,
        'facility_id' => $facilityId,
        'code' => 'OPD_SKIP',
        'name' => 'OPD Skip Test',
        'ticket_prefix' => 'S',
        'is_active' => true,
    ]);
    TenantContextScope::run($tenantId, $facilityId, fn () => $queueRepo->save($queue));

    $encounter1Id = (string) Str::uuid();
    $patient1Id = (string) Str::uuid();
    $encounter2Id = (string) Str::uuid();
    $patient2Id = (string) Str::uuid();
    $room1Id = (string) Str::uuid();

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

    // 2. Call first ticket
    $called1 = TenantContextScope::run($tenantId, $facilityId, fn () => $api->callNext(new CallNextDto(
        queueId: $queue->id,
        roomId: $room1Id,
    )));
    expect($called1->id)->toBe($ticket1->id);

    // 3. Patient stepped away; clinician skips ticket
    $skipped = TenantContextScope::run($tenantId, $facilityId, fn () => $api->skip(
        entryId: $ticket1->id,
        reason: 'Patient briefly stepped away',
    ));
    expect($skipped->status)->toBe(QueueStatus::SKIPPED->value);

    // 4. Calling next immediately yields ticket 2 without waiting
    $called2 = TenantContextScope::run($tenantId, $facilityId, fn () => $api->callNext(new CallNextDto(
        queueId: $queue->id,
        roomId: $room1Id,
    )));
    expect($called2)->not->toBeNull()
        ->and($called2->id)->toBe($ticket2->id);

    // 5. Patient 1 returns; clinician recalls ticket 1
    $recalled = TenantContextScope::run($tenantId, $facilityId, fn () => $api->recall(new RecallTicketDto(
        entryId: $ticket1->id,
        roomId: $room1Id,
    )));

    expect($recalled->status)->toBe(QueueStatus::CALLED->value)
        ->and($recalled->callAttempts)->toBe(2);

    // 6. Clinician can start service for the recalled ticket
    $started = TenantContextScope::run($tenantId, $facilityId, fn () => $api->startService($recalled->id));
    expect($started->status)->toBe(QueueStatus::IN_SERVICE->value);
});

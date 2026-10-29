<?php

declare(strict_types=1);

use App\Platform\Queueing\Contracts\DTO\CallNextDto;
use App\Platform\Queueing\Contracts\DTO\PlaceInQueueDto;
use App\Platform\Queueing\Contracts\QueueingApi;
use App\Platform\Queueing\Domain\Model\Queue;
use App\Platform\Queueing\Domain\Repository\QueueRepository;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Support\Str;

require_once __DIR__.'/../../../../tests/Support/queueing.php';

it('dynamically computes queue position considering priority weighting and wait metrics', function (): void {
    $fx = queueingFixture();
    $tenantId = (string) $fx['tenant']->id;
    $facilityId = (string) $fx['facility']->id;

    $api = app(QueueingApi::class);
    $queueRepo = app(QueueRepository::class);

    $queue = new Queue([
        'tenant_id' => $tenantId,
        'facility_id' => $facilityId,
        'code' => 'OPD_POS',
        'name' => 'OPD Position Test',
        'ticket_prefix' => 'P',
        'is_active' => true,
    ]);
    TenantContextScope::run($tenantId, $facilityId, fn () => $queueRepo->save($queue));

    // 1. Routine ticket 1
    $t1 = TenantContextScope::run($tenantId, $facilityId, fn () => $api->place(new PlaceInQueueDto(
        encounterId: (string) Str::uuid(),
        patientId: (string) Str::uuid(),
        queueId: $queue->id,
        priority: 'ROUTINE',
    )));

    $pos1 = TenantContextScope::run($tenantId, $facilityId, fn () => $api->getQueuePosition($t1->id));
    expect($pos1)->toBe(1);

    // 2. Routine ticket 2
    $t2 = TenantContextScope::run($tenantId, $facilityId, fn () => $api->place(new PlaceInQueueDto(
        encounterId: (string) Str::uuid(),
        patientId: (string) Str::uuid(),
        queueId: $queue->id,
        priority: 'ROUTINE',
    )));

    $pos1AfterT2 = TenantContextScope::run($tenantId, $facilityId, fn () => $api->getQueuePosition($t1->id));
    $pos2 = TenantContextScope::run($tenantId, $facilityId, fn () => $api->getQueuePosition($t2->id));
    expect($pos1AfterT2)->toBe(1)
        ->and($pos2)->toBe(2);

    // 3. Emergency ticket arrives
    $tEmergency = TenantContextScope::run($tenantId, $facilityId, fn () => $api->place(new PlaceInQueueDto(
        encounterId: (string) Str::uuid(),
        patientId: (string) Str::uuid(),
        queueId: $queue->id,
        priority: 'EMERGENCY',
    )));

    // Emergency jumps to position 1!
    $posEmergency = TenantContextScope::run($tenantId, $facilityId, fn () => $api->getQueuePosition($tEmergency->id));
    $pos1AfterEmg = TenantContextScope::run($tenantId, $facilityId, fn () => $api->getQueuePosition($t1->id));
    $pos2AfterEmg = TenantContextScope::run($tenantId, $facilityId, fn () => $api->getQueuePosition($t2->id));

    expect($posEmergency)->toBe(1)
        ->and($pos1AfterEmg)->toBe(2)
        ->and($pos2AfterEmg)->toBe(3);

    // 4. Call emergency ticket
    $called = TenantContextScope::run($tenantId, $facilityId, fn () => $api->callNext(new CallNextDto(
        queueId: $queue->id,
        roomId: (string) Str::uuid(),
    )));
    expect($called->id)->toBe($tEmergency->id);

    // Called ticket has position 0 (already called)
    $posCalled = TenantContextScope::run($tenantId, $facilityId, fn () => $api->getQueuePosition($tEmergency->id));
    $pos1AfterCall = TenantContextScope::run($tenantId, $facilityId, fn () => $api->getQueuePosition($t1->id));
    $pos2AfterCall = TenantContextScope::run($tenantId, $facilityId, fn () => $api->getQueuePosition($t2->id));

    expect($posCalled)->toBe(0)
        ->and($pos1AfterCall)->toBe(1)
        ->and($pos2AfterCall)->toBe(2);

    // 5. Check queue wait metrics
    $metrics = TenantContextScope::run($tenantId, $facilityId, fn () => $api->getQueueWaitMetrics($queue->id));
    expect($metrics->queueId)->toBe($queue->id)
        ->and($metrics->activeTicketsCount)->toBe(3)
        ->and($metrics->completedTodayCount)->toBe(0)
        ->and($metrics->lwbsCount)->toBe(0);
});

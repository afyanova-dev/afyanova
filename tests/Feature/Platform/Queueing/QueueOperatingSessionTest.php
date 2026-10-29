<?php

declare(strict_types=1);

use App\Platform\Queueing\Contracts\DTO\CallNextDto;
use App\Platform\Queueing\Contracts\DTO\PlaceInQueueDto;
use App\Platform\Queueing\Contracts\QueueingApi;
use App\Platform\Queueing\Domain\Enum\QueueOperatingStatus;
use App\Platform\Queueing\Domain\Exception\QueueNotOperatingException;
use App\Platform\Queueing\Domain\Model\Queue;
use App\Platform\Queueing\Domain\Repository\QueueRepository;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Support\Str;

require_once __DIR__.'/../../../../tests/Support/queueing.php';

it('handles queue operating sessions: pause, resume, close, and rejects operations when not operating', function (): void {
    $fx = queueingFixture();
    $tenantId = (string) $fx['tenant']->id;
    $facilityId = (string) $fx['facility']->id;

    $api = app(QueueingApi::class);
    $queueRepo = app(QueueRepository::class);

    $queue = new Queue([
        'tenant_id' => $tenantId,
        'facility_id' => $facilityId,
        'code' => 'OPD_SESSION',
        'name' => 'OPD Session Test',
        'ticket_prefix' => 'P',
        'is_active' => true,
    ]);
    TenantContextScope::run($tenantId, $facilityId, fn () => $queueRepo->save($queue));

    // 1. Place a ticket while ACTIVE
    $placed = TenantContextScope::run($tenantId, $facilityId, fn () => $api->place(new PlaceInQueueDto(
        encounterId: (string) Str::uuid(),
        patientId: (string) Str::uuid(),
        queueId: $queue->id,
    )));
    expect($placed)->not->toBeNull();

    // 2. Pause the queue for staff lunch
    $paused = TenantContextScope::run($tenantId, $facilityId, fn () => $api->pauseQueue(
        queueId: $queue->id,
        reason: 'Staff lunch break until 14:00',
    ));
    expect($paused->operatingStatus)->toBe(QueueOperatingStatus::PAUSED->value)
        ->and($paused->pauseReason)->toBe('Staff lunch break until 14:00');

    // 3. Calling board reflects paused status
    $board = TenantContextScope::run($tenantId, $facilityId, fn () => $api->getCallingBoard($facilityId, $queue->id));
    expect($board->isPaused)->toBeTrue()
        ->and($board->operatingStatus)->toBe(QueueOperatingStatus::PAUSED->value)
        ->and($board->pauseReason)->toBe('Staff lunch break until 14:00');

    // 4. Calling next ticket when PAUSED throws QueueNotOperatingException
    TenantContextScope::run($tenantId, $facilityId, function () use ($api, $queue): void {
        expect(fn () => $api->callNext(new CallNextDto(
            queueId: $queue->id,
            roomId: (string) Str::uuid(),
        )))->toThrow(QueueNotOperatingException::class);
    });

    // 5. Resume the queue
    $resumed = TenantContextScope::run($tenantId, $facilityId, fn () => $api->resumeQueue($queue->id));
    expect($resumed->operatingStatus)->toBe(QueueOperatingStatus::ACTIVE->value)
        ->and($resumed->pauseReason)->toBeNull();

    // 6. Calling next now succeeds
    $called = TenantContextScope::run($tenantId, $facilityId, fn () => $api->callNext(new CallNextDto(
        queueId: $queue->id,
        roomId: (string) Str::uuid(),
    )));
    expect($called)->not->toBeNull()
        ->and($called->id)->toBe($placed->id);

    // 7. Close the queue at end of day
    $closed = TenantContextScope::run($tenantId, $facilityId, fn () => $api->closeQueue(
        queueId: $queue->id,
        reason: 'Shift concluded',
    ));
    expect($closed->operatingStatus)->toBe(QueueOperatingStatus::CLOSED->value);

    // 8. Placing in a closed queue throws QueueNotOperatingException
    TenantContextScope::run($tenantId, $facilityId, function () use ($api, $queue): void {
        expect(fn () => $api->place(new PlaceInQueueDto(
            encounterId: (string) Str::uuid(),
            patientId: (string) Str::uuid(),
            queueId: $queue->id,
        )))->toThrow(QueueNotOperatingException::class);
    });
});

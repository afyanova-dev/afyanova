<?php

declare(strict_types=1);

use App\Platform\Queueing\Contracts\DTO\CallNextDto;
use App\Platform\Queueing\Contracts\DTO\PlaceInQueueDto;
use App\Platform\Queueing\Contracts\DTO\RecallTicketDto;
use App\Platform\Queueing\Contracts\QueueingApi;
use App\Platform\Queueing\Domain\Enum\QueuePriority;
use App\Platform\Queueing\Domain\Enum\QueueStatus;
use App\Platform\Queueing\Domain\Model\Queue;
use App\Platform\Queueing\Domain\Repository\QueueRepository;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Support\Str;

require_once __DIR__.'/../../../../tests/Support/queueing.php';

it('manages complete queue entry lifecycle: place -> call -> start -> complete', function (): void {
    $fx = queueingFixture();
    $tenantId = (string) $fx['tenant']->id;
    $facilityId = (string) $fx['facility']->id;

    $api = app(QueueingApi::class);
    $queueRepo = app(QueueRepository::class);

    // 1. Create a queue
    $queue = new Queue([
        'tenant_id' => $tenantId,
        'facility_id' => $facilityId,
        'code' => 'OPD_GP',
        'name' => 'General OPD Consultation',
        'ticket_prefix' => 'A',
        'is_active' => true,
    ]);
    TenantContextScope::run($tenantId, $facilityId, fn () => $queueRepo->save($queue));

    $encounterId = (string) Str::uuid();
    $patientId = (string) Str::uuid();
    $roomId = (string) Str::uuid();
    $staffId = (string) Str::uuid();

    // 2. Place in queue
    $placed = TenantContextScope::run($tenantId, $facilityId, function () use ($api, $encounterId, $patientId, $queue): mixed {
        return $api->place(new PlaceInQueueDto(
            encounterId: $encounterId,
            patientId: $patientId,
            queueId: $queue->id,
            priority: 'ROUTINE',
        ));
    });

    expect($placed)->not->toBeNull()
        ->and($placed->status)->toBe(QueueStatus::WAITING->value)
        ->and($placed->ticketNumber)->toBe('A-001')
        ->and($placed->priority)->toBe(QueuePriority::ROUTINE->value);

    // 3. Call next ticket
    $called = TenantContextScope::run($tenantId, $facilityId, function () use ($api, $queue, $roomId, $staffId): mixed {
        return $api->callNext(new CallNextDto(
            queueId: $queue->id,
            roomId: $roomId,
            staffId: $staffId,
        ));
    });

    expect($called)->not->toBeNull()
        ->and($called->id)->toBe($placed->id)
        ->and($called->status)->toBe(QueueStatus::CALLED->value)
        ->and($called->calledRoomId)->toBe($roomId)
        ->and($called->calledStaffId)->toBe($staffId)
        ->and($called->callAttempts)->toBe(1);

    // 4. Start service
    $started = TenantContextScope::run($tenantId, $facilityId, fn () => $api->startService($called->id));

    expect($started->status)->toBe(QueueStatus::IN_SERVICE->value)
        ->and($started->serviceStartedAt)->not->toBeNull();

    // 5. Complete service
    $completed = TenantContextScope::run($tenantId, $facilityId, fn () => $api->completeService($started->id));

    expect($completed->status)->toBe(QueueStatus::COMPLETED->value)
        ->and($completed->completedAt)->not->toBeNull();

    // 6. Active check for encounter returns null after completion
    $active = TenantContextScope::run($tenantId, $facilityId, fn () => $api->getActiveForEncounter($encounterId));
    expect($active)->toBeNull();
});

it('prioritizes URGENT and EMERGENCY tickets over older ROUTINE tickets', function (): void {
    $fx = queueingFixture();
    $tenantId = (string) $fx['tenant']->id;
    $facilityId = (string) $fx['facility']->id;

    $api = app(QueueingApi::class);
    $queueRepo = app(QueueRepository::class);

    $queue = new Queue([
        'tenant_id' => $tenantId,
        'facility_id' => $facilityId,
        'code' => 'TRIAGE',
        'name' => 'Nurse Triage',
        'ticket_prefix' => 'T',
        'is_active' => true,
    ]);
    TenantContextScope::run($tenantId, $facilityId, fn () => $queueRepo->save($queue));

    // Place routine ticket first
    $routine = TenantContextScope::run($tenantId, $facilityId, fn () => $api->place(new PlaceInQueueDto(
        encounterId: (string) Str::uuid(),
        patientId: (string) Str::uuid(),
        queueId: $queue->id,
        priority: 'ROUTINE',
    )));

    // Place urgent ticket second
    $urgent = TenantContextScope::run($tenantId, $facilityId, fn () => $api->place(new PlaceInQueueDto(
        encounterId: (string) Str::uuid(),
        patientId: (string) Str::uuid(),
        queueId: $queue->id,
        priority: 'URGENT',
    )));

    // Call next: urgent must be called first even though routine arrived earlier
    $calledFirst = TenantContextScope::run($tenantId, $facilityId, fn () => $api->callNext(new CallNextDto(
        queueId: $queue->id,
        roomId: (string) Str::uuid(),
    )));

    expect($calledFirst)->not->toBeNull()
        ->and($calledFirst->id)->toBe($urgent->id)
        ->and($calledFirst->ticketNumber)->toBe('T-002')
        ->and($calledFirst->priority)->toBe('URGENT');

    // Next call gets the routine ticket
    $calledSecond = TenantContextScope::run($tenantId, $facilityId, fn () => $api->callNext(new CallNextDto(
        queueId: $queue->id,
        roomId: (string) Str::uuid(),
    )));

    expect($calledSecond)->not->toBeNull()
        ->and($calledSecond->id)->toBe($routine->id)
        ->and($calledSecond->ticketNumber)->toBe('T-001')
        ->and($calledSecond->priority)->toBe('ROUTINE');
});

it('recalls a ticket and increments call attempts', function (): void {
    $fx = queueingFixture();
    $tenantId = (string) $fx['tenant']->id;
    $facilityId = (string) $fx['facility']->id;

    $api = app(QueueingApi::class);
    $queueRepo = app(QueueRepository::class);

    $queue = new Queue([
        'tenant_id' => $tenantId,
        'facility_id' => $facilityId,
        'code' => 'OPD',
        'name' => 'OPD Clinic',
        'ticket_prefix' => 'A',
    ]);
    TenantContextScope::run($tenantId, $facilityId, fn () => $queueRepo->save($queue));

    $placed = TenantContextScope::run($tenantId, $facilityId, fn () => $api->place(new PlaceInQueueDto(
        encounterId: (string) Str::uuid(),
        patientId: (string) Str::uuid(),
        queueId: $queue->id,
    )));

    $called = TenantContextScope::run($tenantId, $facilityId, fn () => $api->callNext(new CallNextDto(
        queueId: $queue->id,
        roomId: (string) Str::uuid(),
    )));

    expect($called->callAttempts)->toBe(1);

    // Recall ticket
    $recalled = TenantContextScope::run($tenantId, $facilityId, fn () => $api->recall(new RecallTicketDto(
        entryId: $called->id,
    )));

    expect($recalled->callAttempts)->toBe(2)
        ->and($recalled->status)->toBe(QueueStatus::CALLED->value);
});

it('records no response when patient fails to appear', function (): void {
    $fx = queueingFixture();
    $tenantId = (string) $fx['tenant']->id;
    $facilityId = (string) $fx['facility']->id;

    $api = app(QueueingApi::class);
    $queueRepo = app(QueueRepository::class);

    $queue = new Queue([
        'tenant_id' => $tenantId,
        'facility_id' => $facilityId,
        'code' => 'OPD',
        'name' => 'OPD Clinic',
        'ticket_prefix' => 'A',
    ]);
    TenantContextScope::run($tenantId, $facilityId, fn () => $queueRepo->save($queue));

    $placed = TenantContextScope::run($tenantId, $facilityId, fn () => $api->place(new PlaceInQueueDto(
        encounterId: (string) Str::uuid(),
        patientId: (string) Str::uuid(),
        queueId: $queue->id,
    )));

    $called = TenantContextScope::run($tenantId, $facilityId, fn () => $api->callNext(new CallNextDto(
        queueId: $queue->id,
        roomId: (string) Str::uuid(),
    )));

    $noResponse = TenantContextScope::run($tenantId, $facilityId, fn () => $api->recordNoResponse($called->id));

    expect($noResponse->status)->toBe(QueueStatus::NO_RESPONSE->value);

    // It can be recalled back to CALLED
    $recalled = TenantContextScope::run($tenantId, $facilityId, fn () => $api->recall(new RecallTicketDto(
        entryId: $noResponse->id,
    )));

    expect($recalled->status)->toBe(QueueStatus::CALLED->value)
        ->and($recalled->callAttempts)->toBe(2);
});

it('populates calling board for TV waiting room displays', function (): void {
    $fx = queueingFixture();
    $tenantId = (string) $fx['tenant']->id;
    $facilityId = (string) $fx['facility']->id;

    $api = app(QueueingApi::class);
    $queueRepo = app(QueueRepository::class);

    $queue = new Queue([
        'tenant_id' => $tenantId,
        'facility_id' => $facilityId,
        'code' => 'CONSULT',
        'name' => 'Doctor Consultation',
        'ticket_prefix' => 'C',
    ]);
    TenantContextScope::run($tenantId, $facilityId, fn () => $queueRepo->save($queue));

    // Place 3 tickets
    $t1 = TenantContextScope::run($tenantId, $facilityId, fn () => $api->place(new PlaceInQueueDto(
        encounterId: (string) Str::uuid(),
        patientId: (string) Str::uuid(),
        queueId: $queue->id,
    )));
    $t2 = TenantContextScope::run($tenantId, $facilityId, fn () => $api->place(new PlaceInQueueDto(
        encounterId: (string) Str::uuid(),
        patientId: (string) Str::uuid(),
        queueId: $queue->id,
    )));
    $t3 = TenantContextScope::run($tenantId, $facilityId, fn () => $api->place(new PlaceInQueueDto(
        encounterId: (string) Str::uuid(),
        patientId: (string) Str::uuid(),
        queueId: $queue->id,
    )));

    // Call ticket 1
    $roomId = (string) Str::uuid();
    TenantContextScope::run($tenantId, $facilityId, fn () => $api->callNext(new CallNextDto(
        queueId: $queue->id,
        roomId: $roomId,
    )));

    // Query calling board
    $board = TenantContextScope::run($tenantId, $facilityId, fn () => $api->getCallingBoard($facilityId, $queue->id));

    expect($board->calledTickets)->toHaveCount(1)
        ->and($board->calledTickets[0]->ticketNumber)->toBe('C-001')
        ->and($board->calledTickets[0]->calledRoomId)->toBe($roomId)
        ->and($board->waitingTickets)->toHaveCount(2)
        ->and($board->waitingTickets[0]->ticketNumber)->toBe('C-002')
        ->and($board->waitingTickets[1]->ticketNumber)->toBe('C-003');
});

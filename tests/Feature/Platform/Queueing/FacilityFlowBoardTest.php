<?php

declare(strict_types=1);

namespace Tests\Feature\Platform\Queueing;

use App\Platform\Queueing\Application\Command\CallNextTicketCommand;
use App\Platform\Queueing\Application\Command\PlaceEncounterInQueueCommand;
use App\Platform\Queueing\Application\Command\RegisterQueueCommand;
use App\Platform\Queueing\Application\Command\StartTicketServiceCommand;
use App\Platform\Queueing\Application\Query\GetFacilityFlowBoardQuery;
use App\Platform\Queueing\Contracts\DTO\FacilityFlowBoardDto;
use App\Platform\Queueing\Domain\Model\Queue;
use App\Shared\Bus\CommandBus;
use App\Shared\Bus\QueryBus;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

test('GetFacilityFlowBoardQuery aggregates waiting, in-service, and priority counts across facility queues', function (): void {
    $fixture = queueingFixture();
    $tenantId = (string) $fixture['tenant']->id;
    $facilityId = (string) $fixture['facility']->id;
    $userId = (string) $fixture['user']->id;

    $commandBus = app(CommandBus::class);
    $queryBus = app(QueryBus::class);

    $opdQueue = TenantContextScope::run($tenantId, $facilityId, fn () => $commandBus->dispatch(new RegisterQueueCommand(
        facilityId: $facilityId,
        code: 'FLOW-OPD',
        name: 'OPD Flow Queue',
        departmentId: (string) Str::uuid7(),
        ticketPrefix: 'A',
    )));

    $labQueue = TenantContextScope::run($tenantId, $facilityId, fn () => $commandBus->dispatch(new RegisterQueueCommand(
        facilityId: $facilityId,
        code: 'FLOW-LAB',
        name: 'Lab Intake Queue',
        departmentId: (string) Str::uuid7(),
        ticketPrefix: 'L',
    )));

    // Place tickets in OPD queue (1 Emergency, 1 Routine)
    $t1 = TenantContextScope::run($tenantId, $facilityId, fn () => $commandBus->dispatch(new PlaceEncounterInQueueCommand(
        encounterId: (string) Str::uuid7(),
        patientId: (string) Str::uuid7(),
        queueId: $opdQueue->id,
        priority: 'EMERGENCY',
    )));

    TenantContextScope::run($tenantId, $facilityId, fn () => $commandBus->dispatch(new PlaceEncounterInQueueCommand(
        encounterId: (string) Str::uuid7(),
        patientId: (string) Str::uuid7(),
        queueId: $opdQueue->id,
        priority: 'ROUTINE',
    )));

    // Call and start service on t1
    TenantContextScope::run($tenantId, $facilityId, fn () => $commandBus->dispatch(new CallNextTicketCommand(
        queueId: $opdQueue->id,
        roomId: (string) Str::uuid7(),
        staffId: $userId,
    )));

    TenantContextScope::run($tenantId, $facilityId, fn () => $commandBus->dispatch(new StartTicketServiceCommand(
        entryId: $t1->id,
    )));

    // Place ticket in Lab queue
    TenantContextScope::run($tenantId, $facilityId, fn () => $commandBus->dispatch(new PlaceEncounterInQueueCommand(
        encounterId: (string) Str::uuid7(),
        patientId: (string) Str::uuid7(),
        queueId: $labQueue->id,
        priority: 'URGENT',
    )));

    TenantContextScope::run($tenantId, $facilityId, function () use ($labQueue): void {
        $queue = Queue::query()->findOrFail($labQueue->id);
        $queue->is_active = false;
        $queue->save();
    });

    /** @var FacilityFlowBoardDto $flowBoard */
    $flowBoard = TenantContextScope::run($tenantId, $facilityId, fn () => $queryBus->dispatch(new GetFacilityFlowBoardQuery(facilityId: $facilityId)));

    expect($flowBoard->facilityId)->toBe($facilityId);
    expect($flowBoard->totalWaiting)->toBe(2); // 1 in OPD, 1 in Lab
    expect($flowBoard->totalInService)->toBe(1); // 1 in OPD
    expect($flowBoard->queues)->toHaveCount(1);

    $opdSummary = collect($flowBoard->queues)->firstWhere('queue_id', $opdQueue->id);
    expect($opdSummary)->not->toBeNull();
    expect($opdSummary['waiting_count'])->toBe(1);
    expect($opdSummary['in_service_count'])->toBe(1);
    expect($opdSummary['routine_count'])->toBe(1);

    expect(collect($flowBoard->queues)->firstWhere('queue_id', $labQueue->id))->toBeNull();
});

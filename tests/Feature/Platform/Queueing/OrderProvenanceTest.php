<?php

declare(strict_types=1);

use App\Platform\Queueing\Contracts\DTO\PlaceInQueueDto;
use App\Platform\Queueing\Contracts\QueueingApi;
use App\Platform\Queueing\Domain\Enum\OrderType;
use App\Platform\Queueing\Domain\Enum\ServiceCapability;
use App\Platform\Queueing\Domain\Exception\MissingOrderProvenanceException;
use App\Platform\Queueing\Domain\Model\Queue;
use App\Platform\Queueing\Domain\Repository\QueueRepository;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Support\Str;

require_once __DIR__.'/../../../../tests/Support/queueing.php';

it('enforces source order provenance for diagnostic and dispensing queues', function (string $capability): void {
    $fx = queueingFixture();
    $tenantId = (string) $fx['tenant']->id;
    $facilityId = (string) $fx['facility']->id;

    $api = app(QueueingApi::class);
    $queueRepo = app(QueueRepository::class);

    $queue = new Queue([
        'tenant_id' => $tenantId,
        'facility_id' => $facilityId,
        'code' => "Q_{$capability}",
        'name' => "Queue for {$capability}",
        'service_capability' => $capability,
        'ticket_prefix' => substr($capability, 0, 2),
    ]);
    TenantContextScope::run($tenantId, $facilityId, fn () => $queueRepo->save($queue));

    // Placing WITHOUT order provenance must fail
    expect(fn () => TenantContextScope::run($tenantId, $facilityId, fn () => $api->place(new PlaceInQueueDto(
        encounterId: (string) Str::uuid(),
        patientId: (string) Str::uuid(),
        queueId: $queue->id,
        sourceOrderRef: null,
    ))))->toThrow(MissingOrderProvenanceException::class);

    // Placing WITH order provenance succeeds
    $orderRef = (string) Str::uuid();
    $ticket = TenantContextScope::run($tenantId, $facilityId, fn () => $api->place(new PlaceInQueueDto(
        encounterId: (string) Str::uuid(),
        patientId: (string) Str::uuid(),
        queueId: $queue->id,
        sourceOrderRef: $orderRef,
        orderType: OrderType::LAB_ORDER->value,
    )));

    expect($ticket->sourceOrderRef)->toBe($orderRef);
})->with([
    ServiceCapability::LABORATORY->value,
    ServiceCapability::PHARMACY->value,
    ServiceCapability::RADIOLOGY->value,
    ServiceCapability::PROCEDURE->value,
]);

it('permits placement without order provenance for consultation and triage queues', function (string $capability): void {
    $fx = queueingFixture();
    $tenantId = (string) $fx['tenant']->id;
    $facilityId = (string) $fx['facility']->id;

    $api = app(QueueingApi::class);
    $queueRepo = app(QueueRepository::class);

    $queue = new Queue([
        'tenant_id' => $tenantId,
        'facility_id' => $facilityId,
        'code' => "Q_{$capability}",
        'name' => "Queue for {$capability}",
        'service_capability' => $capability,
        'ticket_prefix' => substr($capability, 0, 2),
    ]);
    TenantContextScope::run($tenantId, $facilityId, fn () => $queueRepo->save($queue));

    $ticket = TenantContextScope::run($tenantId, $facilityId, fn () => $api->place(new PlaceInQueueDto(
        encounterId: (string) Str::uuid(),
        patientId: (string) Str::uuid(),
        queueId: $queue->id,
        sourceOrderRef: null,
    )));

    expect($ticket)->not->toBeNull()
        ->and($ticket->sourceOrderRef)->toBeNull();
})->with([
    ServiceCapability::TRIAGE->value,
    ServiceCapability::CONSULTATION->value,
    ServiceCapability::CASHIER->value,
]);

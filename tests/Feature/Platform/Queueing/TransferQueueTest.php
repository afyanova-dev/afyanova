<?php

declare(strict_types=1);

use App\Platform\Queueing\Contracts\DTO\PlaceInQueueDto;
use App\Platform\Queueing\Contracts\DTO\TransferQueueDto;
use App\Platform\Queueing\Contracts\QueueingApi;
use App\Platform\Queueing\Domain\Enum\OrderType;
use App\Platform\Queueing\Domain\Enum\QueueStatus;
use App\Platform\Queueing\Domain\Enum\ServiceCapability;
use App\Platform\Queueing\Domain\Exception\MissingOrderProvenanceException;
use App\Platform\Queueing\Domain\Model\Queue;
use App\Platform\Queueing\Domain\Model\QueueEntry;
use App\Platform\Queueing\Domain\Repository\QueueRepository;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Support\Str;

require_once __DIR__.'/../../../../tests/Support/queueing.php';

it('transfers patient from one queue to another cleanly', function (): void {
    $fx = queueingFixture();
    $tenantId = (string) $fx['tenant']->id;
    $facilityId = (string) $fx['facility']->id;

    $api = app(QueueingApi::class);
    $queueRepo = app(QueueRepository::class);

    $triageQueue = new Queue([
        'tenant_id' => $tenantId,
        'facility_id' => $facilityId,
        'code' => 'TRIAGE',
        'name' => 'Triage',
        'service_capability' => ServiceCapability::TRIAGE->value,
        'ticket_prefix' => 'T',
    ]);
    $consultQueue = new Queue([
        'tenant_id' => $tenantId,
        'facility_id' => $facilityId,
        'code' => 'CONSULT',
        'name' => 'General Consultation',
        'service_capability' => ServiceCapability::CONSULTATION->value,
        'ticket_prefix' => 'C',
    ]);
    TenantContextScope::run($tenantId, $facilityId, function () use ($queueRepo, $triageQueue, $consultQueue): void {
        $queueRepo->save($triageQueue);
        $queueRepo->save($consultQueue);
    });

    $encounterId = (string) Str::uuid();
    $patientId = (string) Str::uuid();

    // Place in triage
    $triageTicket = TenantContextScope::run($tenantId, $facilityId, fn () => $api->place(new PlaceInQueueDto(
        encounterId: $encounterId,
        patientId: $patientId,
        queueId: $triageQueue->id,
        priority: 'URGENT',
    )));

    // Transfer to consultation
    $consultTicket = TenantContextScope::run($tenantId, $facilityId, fn () => $api->transfer(new TransferQueueDto(
        entryId: $triageTicket->id,
        destinationQueueId: $consultQueue->id,
        reason: 'Triage vitals recorded, referred to doctor',
    )));

    expect($consultTicket->id)->not->toBe($triageTicket->id)
        ->and($consultTicket->queueId)->toBe($consultQueue->id)
        ->and($consultTicket->encounterId)->toBe($encounterId)
        ->and($consultTicket->patientId)->toBe($patientId)
        ->and($consultTicket->priority)->toBe('URGENT')
        ->and($consultTicket->status)->toBe(QueueStatus::WAITING->value)
        ->and($consultTicket->ticketNumber)->toBe('C-001');

    // Verify source ticket is completed
    TenantContextScope::run($tenantId, $facilityId, function () use ($triageTicket): void {
        $source = QueueEntry::query()->find($triageTicket->id);
        expect($source->status)->toBe(QueueStatus::COMPLETED);
    });
});

it('rejects transfer to pharmacy queue if order reference is missing', function (): void {
    $fx = queueingFixture();
    $tenantId = (string) $fx['tenant']->id;
    $facilityId = (string) $fx['facility']->id;

    $api = app(QueueingApi::class);
    $queueRepo = app(QueueRepository::class);

    $consultQueue = new Queue([
        'tenant_id' => $tenantId,
        'facility_id' => $facilityId,
        'code' => 'CONSULT',
        'name' => 'Consultation',
        'ticket_prefix' => 'C',
    ]);
    $pharmQueue = new Queue([
        'tenant_id' => $tenantId,
        'facility_id' => $facilityId,
        'code' => 'PHARM',
        'name' => 'Pharmacy',
        'service_capability' => ServiceCapability::PHARMACY->value,
        'ticket_prefix' => 'P',
    ]);
    TenantContextScope::run($tenantId, $facilityId, function () use ($queueRepo, $consultQueue, $pharmQueue): void {
        $queueRepo->save($consultQueue);
        $queueRepo->save($pharmQueue);
    });

    $ticket = TenantContextScope::run($tenantId, $facilityId, fn () => $api->place(new PlaceInQueueDto(
        encounterId: (string) Str::uuid(),
        patientId: (string) Str::uuid(),
        queueId: $consultQueue->id,
    )));

    // Transfer without order reference fails
    expect(fn () => TenantContextScope::run($tenantId, $facilityId, fn () => $api->transfer(new TransferQueueDto(
        entryId: $ticket->id,
        destinationQueueId: $pharmQueue->id,
        sourceOrderRef: null,
    ))))->toThrow(MissingOrderProvenanceException::class);

    // Transfer with order reference succeeds
    $orderRef = (string) Str::uuid();
    $pharmTicket = TenantContextScope::run($tenantId, $facilityId, fn () => $api->transfer(new TransferQueueDto(
        entryId: $ticket->id,
        destinationQueueId: $pharmQueue->id,
        sourceOrderRef: $orderRef,
        orderType: OrderType::PRESCRIPTION->value,
    )));

    expect($pharmTicket->sourceOrderRef)->toBe($orderRef);
});

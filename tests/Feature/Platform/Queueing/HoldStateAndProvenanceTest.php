<?php

declare(strict_types=1);

use App\Domains\Billing\Contracts\Event\PaymentRecorded;
use App\Platform\Queueing\Contracts\DTO\CallNextDto;
use App\Platform\Queueing\Contracts\DTO\PlaceInQueueDto;
use App\Platform\Queueing\Contracts\QueueingApi;
use App\Platform\Queueing\Domain\Enum\HoldReason;
use App\Platform\Queueing\Domain\Enum\QueueStatus;
use App\Platform\Queueing\Domain\Exception\MissingClearanceProvenanceException;
use App\Platform\Queueing\Domain\Model\Queue;
use App\Platform\Queueing\Domain\Model\QueueEntry;
use App\Platform\Queueing\Domain\Repository\QueueRepository;
use App\Platform\Queueing\Infrastructure\Listener\OnPaymentRecorded;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Support\Str;

require_once __DIR__.'/../../../../tests/Support/queueing.php';

it('skips tickets on hold when calling next ticket', function (): void {
    $fx = queueingFixture();
    $tenantId = (string) $fx['tenant']->id;
    $facilityId = (string) $fx['facility']->id;

    $api = app(QueueingApi::class);
    $queueRepo = app(QueueRepository::class);

    $queue = new Queue([
        'tenant_id' => $tenantId,
        'facility_id' => $facilityId,
        'code' => 'LAB',
        'name' => 'Laboratory Phlebotomy',
        'ticket_prefix' => 'L',
    ]);
    TenantContextScope::run($tenantId, $facilityId, fn () => $queueRepo->save($queue));

    // Ticket 1: Placed first
    $t1 = TenantContextScope::run($tenantId, $facilityId, fn () => $api->place(new PlaceInQueueDto(
        encounterId: (string) Str::uuid(),
        patientId: (string) Str::uuid(),
        queueId: $queue->id,
    )));

    // Ticket 2: Placed second
    $t2 = TenantContextScope::run($tenantId, $facilityId, fn () => $api->place(new PlaceInQueueDto(
        encounterId: (string) Str::uuid(),
        patientId: (string) Str::uuid(),
        queueId: $queue->id,
    )));

    // Place Ticket 1 on FINANCIAL_HOLD
    TenantContextScope::run($tenantId, $facilityId, fn () => $api->placeHold(
        entryId: $t1->id,
        holdReason: HoldReason::FINANCIAL_HOLD->value,
        holdProvenance: 'Invoice:INV-2026-0001',
    ));

    // Call next: Ticket 1 is on hold, so Ticket 2 must be called!
    $called = TenantContextScope::run($tenantId, $facilityId, fn () => $api->callNext(new CallNextDto(
        queueId: $queue->id,
        roomId: (string) Str::uuid(),
    )));

    expect($called)->not->toBeNull()
        ->and($called->id)->toBe($t2->id)
        ->and($called->ticketNumber)->toBe('L-002');
});

it('requires clearance provenance to release hold', function (): void {
    $fx = queueingFixture();
    $tenantId = (string) $fx['tenant']->id;
    $facilityId = (string) $fx['facility']->id;

    $api = app(QueueingApi::class);
    $queueRepo = app(QueueRepository::class);

    $queue = new Queue([
        'tenant_id' => $tenantId,
        'facility_id' => $facilityId,
        'code' => 'PHARM',
        'name' => 'Pharmacy',
        'ticket_prefix' => 'P',
    ]);
    TenantContextScope::run($tenantId, $facilityId, fn () => $queueRepo->save($queue));

    $ticket = TenantContextScope::run($tenantId, $facilityId, fn () => $api->place(new PlaceInQueueDto(
        encounterId: (string) Str::uuid(),
        patientId: (string) Str::uuid(),
        queueId: $queue->id,
    )));

    TenantContextScope::run($tenantId, $facilityId, fn () => $api->placeHold(
        entryId: $ticket->id,
        holdReason: HoldReason::FINANCIAL_HOLD->value,
        holdProvenance: 'Billing:UnpaidPrescription',
    ));

    // Attempting to release with blank clearance provenance throws exception
    expect(fn () => TenantContextScope::run($tenantId, $facilityId, fn () => $api->releaseHold(
        entryId: $ticket->id,
        clearanceProvenance: '   ',
    )))->toThrow(MissingClearanceProvenanceException::class);

    // Releasing with valid clearance provenance succeeds
    $released = TenantContextScope::run($tenantId, $facilityId, fn () => $api->releaseHold(
        entryId: $ticket->id,
        clearanceProvenance: 'Payment:RCP-998877',
    ));

    expect($released->status)->toBe(QueueStatus::WAITING->value)
        ->and($released->holdReason)->toBeNull()
        ->and($released->clearanceProvenance)->toBe('Payment:RCP-998877');
});

it('releases financial hold on PaymentRecorded domain event', function (): void {
    $fx = queueingFixture();
    $tenantId = (string) $fx['tenant']->id;
    $facilityId = (string) $fx['facility']->id;

    $api = app(QueueingApi::class);
    $queueRepo = app(QueueRepository::class);

    $queue = new Queue([
        'tenant_id' => $tenantId,
        'facility_id' => $facilityId,
        'code' => 'PHARM',
        'name' => 'Main Pharmacy',
        'ticket_prefix' => 'P',
    ]);
    TenantContextScope::run($tenantId, $facilityId, fn () => $queueRepo->save($queue));

    $patientId = (string) Str::uuid();
    $ticket = TenantContextScope::run($tenantId, $facilityId, fn () => $api->place(new PlaceInQueueDto(
        encounterId: (string) Str::uuid(),
        patientId: $patientId,
        queueId: $queue->id,
    )));

    TenantContextScope::run($tenantId, $facilityId, fn () => $api->placeHold(
        entryId: $ticket->id,
        holdReason: HoldReason::FINANCIAL_HOLD->value,
        holdProvenance: 'Billing:Unpaid',
    ));

    // Simulate PaymentRecorded event handled by OnPaymentRecorded listener
    $paymentId = (string) Str::uuid();
    $event = new PaymentRecorded(
        eventId: (string) Str::uuid(),
        tenantId: $tenantId,
        facilityId: $facilityId,
        patientId: $patientId,
        financialAccountId: (string) Str::uuid(),
        paymentId: $paymentId,
        method: 'M_PESA',
        amount: '25000',
        currency: 'TZS',
        occurredAt: (string) now()->toISOString(),
        correlationId: (string) Str::uuid(),
    );

    TenantContextScope::run($tenantId, $facilityId, function () use ($event): void {
        app(OnPaymentRecorded::class)($event);
    });

    // Verify hold is released
    TenantContextScope::run($tenantId, $facilityId, function () use ($ticket, $paymentId): void {
        $entry = QueueEntry::query()->find($ticket->id);
        expect($entry)->not->toBeNull()
            ->and($entry->status)->toBe(QueueStatus::WAITING)
            ->and($entry->hold_reason)->toBeNull()
            ->and($entry->clearance_provenance)->toBe("Payment:{$paymentId}");
    });
});

<?php

declare(strict_types=1);

use App\Platform\Queueing\Application\Command\RecordPatientMovementCommand;
use App\Platform\Queueing\Contracts\DTO\CallNextDto;
use App\Platform\Queueing\Contracts\DTO\PlaceInQueueDto;
use App\Platform\Queueing\Contracts\DTO\TransferQueueDto;
use App\Platform\Queueing\Contracts\QueueingApi;
use App\Platform\Queueing\Domain\Model\Queue;
use App\Platform\Queueing\Domain\Repository\QueueRepository;
use App\Shared\Bus\CommandBus;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Support\Str;

require_once __DIR__.'/../../../../tests/Support/queueing.php';

it('tracks patient operational location on place, call, transfer, and manual movement', function (): void {
    $fx = queueingFixture();
    $tenantId = (string) $fx['tenant']->id;
    $facilityId = (string) $fx['facility']->id;

    $api = app(QueueingApi::class);
    $queueRepo = app(QueueRepository::class);
    $commandBus = app(CommandBus::class);

    $triageQueue = new Queue([
        'tenant_id' => $tenantId,
        'facility_id' => $facilityId,
        'code' => 'TRIAGE',
        'name' => 'Triage Station',
        'ticket_prefix' => 'T',
        'is_active' => true,
    ]);
    $consultQueue = new Queue([
        'tenant_id' => $tenantId,
        'facility_id' => $facilityId,
        'code' => 'CONSULT',
        'name' => 'Consultation Room Area',
        'ticket_prefix' => 'C',
        'is_active' => true,
    ]);
    TenantContextScope::run($tenantId, $facilityId, function () use ($queueRepo, $triageQueue, $consultQueue): void {
        $queueRepo->save($triageQueue);
        $queueRepo->save($consultQueue);
    });

    $encounterId = (string) Str::uuid();
    $patientId = (string) Str::uuid();
    $room1Id = (string) Str::uuid();
    $doctorStaffId = (string) Str::uuid();

    // 1. Initially, no location recorded
    $initialLoc = TenantContextScope::run($tenantId, $facilityId, fn () => $api->getCurrentLocation($encounterId));
    expect($initialLoc)->toBeNull();

    // 2. Place in Triage Queue -> movement to Triage Station
    $placed = TenantContextScope::run($tenantId, $facilityId, fn () => $api->place(new PlaceInQueueDto(
        encounterId: $encounterId,
        patientId: $patientId,
        queueId: $triageQueue->id,
        priority: 'ROUTINE',
    )));

    $locAfterPlace = TenantContextScope::run($tenantId, $facilityId, fn () => $api->getCurrentLocation($encounterId));
    expect($locAfterPlace)->not->toBeNull()
        ->and($locAfterPlace->currentWorkArea)->toBe('Triage Station')
        ->and($locAfterPlace->currentState)->toBe('WAITING')
        ->and($locAfterPlace->encounterId)->toBe($encounterId);

    // 3. Call ticket to Room 1 -> movement to Room 1
    TenantContextScope::run($tenantId, $facilityId, fn () => $api->callNext(new CallNextDto(
        queueId: $triageQueue->id,
        roomId: $room1Id,
        staffId: $doctorStaffId,
    )));

    $locAfterCall = TenantContextScope::run($tenantId, $facilityId, fn () => $api->getCurrentLocation($encounterId));
    expect($locAfterCall)->not->toBeNull()
        ->and($locAfterCall->currentWorkArea)->toBe($room1Id)
        ->and($locAfterCall->fromArea)->toBe('Triage Station');

    // 4. Transfer to Consultation Queue -> movement to Consultation Room Area
    TenantContextScope::run($tenantId, $facilityId, fn () => $api->transfer(new TransferQueueDto(
        entryId: $placed->id,
        destinationQueueId: $consultQueue->id,
        reason: 'Triage complete, transferring to doctor',
    )));

    $locAfterTransfer = TenantContextScope::run($tenantId, $facilityId, fn () => $api->getCurrentLocation($encounterId));
    expect($locAfterTransfer)->not->toBeNull()
        ->and($locAfterTransfer->currentWorkArea)->toBe('Consultation Room Area');

    // 5. Manual patient movement (e.g. sent to X-Ray waiting bench)
    TenantContextScope::run($tenantId, $facilityId, fn () => $commandBus->dispatch(new RecordPatientMovementCommand(
        encounterId: $encounterId,
        patientId: $patientId,
        toArea: 'XRAY_WAITING_BENCH',
        fromArea: 'Consultation Room Area',
        reason: 'Sent for imaging',
    )));

    $locAfterManual = TenantContextScope::run($tenantId, $facilityId, fn () => $api->getCurrentLocation($encounterId));
    expect($locAfterManual)->not->toBeNull()
        ->and($locAfterManual->currentWorkArea)->toBe('XRAY_WAITING_BENCH')
        ->and($locAfterManual->fromArea)->toBe('Consultation Room Area')
        ->and($locAfterManual->reason)->toBe('Sent for imaging');

    // 6. Check movements history
    $movements = TenantContextScope::run($tenantId, $facilityId, fn () => $api->getPatientMovements($encounterId));
    expect($movements)->toHaveCount(4)
        ->and($movements[0]->toArea)->toBe('Triage Station')
        ->and($movements[1]->toArea)->toBe($room1Id)
        ->and($movements[2]->toArea)->toBe('Consultation Room Area')
        ->and($movements[3]->toArea)->toBe('XRAY_WAITING_BENCH');
});

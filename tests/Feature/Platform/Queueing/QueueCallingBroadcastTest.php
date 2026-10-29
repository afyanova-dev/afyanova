<?php

declare(strict_types=1);

namespace Tests\Feature\Platform\Queueing;

use App\Platform\Queueing\Application\Command\CallNextTicketCommand;
use App\Platform\Queueing\Application\Command\PlaceEncounterInQueueCommand;
use App\Platform\Queueing\Application\Command\RecallTicketCommand;
use App\Platform\Queueing\Application\Command\RegisterQueueCommand;
use App\Platform\Queueing\Infrastructure\Adapter\ReverbQueueCallingBroadcaster;
use App\Shared\Bus\CommandBus;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    ReverbQueueCallingBroadcaster::reset();
});

test('CallNextTicket broadcasts calling payload over WebSocket with zero PHI', function (): void {
    $fixture = queueingFixture();
    $tenantId = (string) $fixture['tenant']->id;
    $facilityId = (string) $fixture['facility']->id;
    $userId = (string) $fixture['user']->id;

    $commandBus = app(CommandBus::class);

    $queue = TenantContextScope::run($tenantId, $facilityId, fn () => $commandBus->dispatch(new RegisterQueueCommand(
        facilityId: $facilityId,
        code: 'OPD-'.Str::upper(Str::random(4)),
        name: 'OPD Consultation Queue',
        departmentId: (string) Str::uuid7(),
        ticketPrefix: 'A',
    )));

    $ticket = TenantContextScope::run($tenantId, $facilityId, fn () => $commandBus->dispatch(new PlaceEncounterInQueueCommand(
        encounterId: (string) Str::uuid7(),
        patientId: (string) Str::uuid7(),
        queueId: $queue->id,
        priority: 'URGENT',
    )));

    $roomId = (string) Str::uuid7();
    $called = TenantContextScope::run($tenantId, $facilityId, fn () => $commandBus->dispatch(new CallNextTicketCommand(
        queueId: $queue->id,
        roomId: $roomId,
        staffId: $userId,
    )));

    expect($called)->not->toBeNull();
    expect($called->ticketNumber)->toBe($ticket->ticketNumber);

    $broadcasts = ReverbQueueCallingBroadcaster::$broadcasts;
    expect($broadcasts)->toHaveCount(1);
    expect($broadcasts[0]['facilityId'])->toBe($facilityId);
    expect($broadcasts[0]['queueId'])->toBe($queue->id);
    expect($broadcasts[0]['ticketNumber'])->toBe($ticket->ticketNumber);
    expect($broadcasts[0]['roomId'])->toBe($roomId);
    expect($broadcasts[0]['chimeType'])->toBe('URGENT');
    expect($broadcasts[0]['status'])->toBe('CALLED');

    // Zero PHI assurance
    expect(array_keys($broadcasts[0]))->not->toContain('patient_name', 'patient_id', 'encounter_id', 'diagnosis');
});

test('RecallTicket broadcasts recall chime over WebSocket', function (): void {
    $fixture = queueingFixture();
    $tenantId = (string) $fixture['tenant']->id;
    $facilityId = (string) $fixture['facility']->id;
    $userId = (string) $fixture['user']->id;

    $commandBus = app(CommandBus::class);

    $queue = TenantContextScope::run($tenantId, $facilityId, fn () => $commandBus->dispatch(new RegisterQueueCommand(
        facilityId: $facilityId,
        code: 'TRG-'.Str::upper(Str::random(4)),
        name: 'Triage Queue',
        departmentId: (string) Str::uuid7(),
        ticketPrefix: 'T',
    )));

    $ticket = TenantContextScope::run($tenantId, $facilityId, fn () => $commandBus->dispatch(new PlaceEncounterInQueueCommand(
        encounterId: (string) Str::uuid7(),
        patientId: (string) Str::uuid7(),
        queueId: $queue->id,
        priority: 'ROUTINE',
    )));

    $triageRoomId = (string) Str::uuid7();
    $called = TenantContextScope::run($tenantId, $facilityId, fn () => $commandBus->dispatch(new CallNextTicketCommand(
        queueId: $queue->id,
        roomId: $triageRoomId,
        staffId: $userId,
    )));

    ReverbQueueCallingBroadcaster::reset();

    TenantContextScope::run($tenantId, $facilityId, fn () => $commandBus->dispatch(new RecallTicketCommand(
        entryId: $called->id,
        roomId: $triageRoomId,
        staffId: $userId,
    )));

    $broadcasts = ReverbQueueCallingBroadcaster::$broadcasts;
    expect($broadcasts)->toHaveCount(1);
    expect($broadcasts[0]['ticketNumber'])->toBe($ticket->ticketNumber);
    expect($broadcasts[0]['chimeType'])->toBe('RECALL');
    expect($broadcasts[0]['status'])->toBe('RECALLED');
});

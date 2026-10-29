<?php

declare(strict_types=1);

use App\Domains\Clinical\Contracts\Event\TriageAssessmentRecorded;
use App\Domains\Encounter\Contracts\Event\EncounterCancelled;
use App\Domains\Encounter\Contracts\Event\EncounterCompleted;
use App\Platform\Queueing\Contracts\DTO\PlaceInQueueDto;
use App\Platform\Queueing\Contracts\QueueingApi;
use App\Platform\Queueing\Domain\Enum\QueuePriority;
use App\Platform\Queueing\Domain\Enum\QueueStatus;
use App\Platform\Queueing\Domain\Model\Queue;
use App\Platform\Queueing\Domain\Model\QueueEntry;
use App\Platform\Queueing\Domain\Repository\QueueRepository;
use App\Platform\Queueing\Infrastructure\Listener\OnEncounterCancelled;
use App\Platform\Queueing\Infrastructure\Listener\OnEncounterCompleted;
use App\Platform\Queueing\Infrastructure\Listener\OnTriageAssessmentRecorded;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Support\Str;

require_once __DIR__.'/../../../../tests/Support/queueing.php';

it('cancels active queue tickets when EncounterCompleted is emitted', function (): void {
    $fx = queueingFixture();
    $tenantId = (string) $fx['tenant']->id;
    $facilityId = (string) $fx['facility']->id;

    $api = app(QueueingApi::class);
    $queueRepo = app(QueueRepository::class);

    $queue = new Queue([
        'tenant_id' => $tenantId,
        'facility_id' => $facilityId,
        'code' => 'OPD',
        'name' => 'OPD',
        'ticket_prefix' => 'A',
    ]);
    TenantContextScope::run($tenantId, $facilityId, fn () => $queueRepo->save($queue));

    $encounterId = (string) Str::uuid();
    $patientId = (string) Str::uuid();

    $ticket = TenantContextScope::run($tenantId, $facilityId, fn () => $api->place(new PlaceInQueueDto(
        encounterId: $encounterId,
        patientId: $patientId,
        queueId: $queue->id,
    )));

    // Encounter is completed/discharged
    $event = new EncounterCompleted(
        eventId: (string) Str::uuid(),
        tenantId: $tenantId,
        facilityId: $facilityId,
        encounterId: $encounterId,
        patientId: $patientId,
        disposition: 'DISCHARGED_HOME',
        occurredAt: (string) now()->toISOString(),
        correlationId: (string) Str::uuid(),
        causationId: null,
    );

    TenantContextScope::run($tenantId, $facilityId, function () use ($event): void {
        app(OnEncounterCompleted::class)($event);
    });

    TenantContextScope::run($tenantId, $facilityId, function () use ($ticket): void {
        $entry = QueueEntry::query()->find($ticket->id);
        expect($entry->status)->toBe(QueueStatus::EXPIRED);
    });
});

it('cancels active queue tickets when EncounterCancelled is emitted', function (): void {
    $fx = queueingFixture();
    $tenantId = (string) $fx['tenant']->id;
    $facilityId = (string) $fx['facility']->id;

    $api = app(QueueingApi::class);
    $queueRepo = app(QueueRepository::class);

    $queue = new Queue([
        'tenant_id' => $tenantId,
        'facility_id' => $facilityId,
        'code' => 'OPD',
        'name' => 'OPD',
        'ticket_prefix' => 'A',
    ]);
    TenantContextScope::run($tenantId, $facilityId, fn () => $queueRepo->save($queue));

    $encounterId = (string) Str::uuid();
    $patientId = (string) Str::uuid();

    $ticket = TenantContextScope::run($tenantId, $facilityId, fn () => $api->place(new PlaceInQueueDto(
        encounterId: $encounterId,
        patientId: $patientId,
        queueId: $queue->id,
    )));

    $event = new EncounterCancelled(
        eventId: (string) Str::uuid(),
        tenantId: $tenantId,
        facilityId: $facilityId,
        encounterId: $encounterId,
        patientId: $patientId,
        occurredAt: (string) now()->toISOString(),
    );

    TenantContextScope::run($tenantId, $facilityId, function () use ($event): void {
        app(OnEncounterCancelled::class)($event);
    });

    TenantContextScope::run($tenantId, $facilityId, function () use ($ticket): void {
        $entry = QueueEntry::query()->find($ticket->id);
        expect($entry->status)->toBe(QueueStatus::EXPIRED);
    });
});

it('maps clinical triage priority onto queue priority when TriageAssessmentRecorded is emitted', function (
    string $triagePriority,
    QueuePriority $expectedPriority,
): void {
    $fx = queueingFixture();
    $tenantId = (string) $fx['tenant']->id;
    $facilityId = (string) $fx['facility']->id;

    $api = app(QueueingApi::class);
    $queueRepo = app(QueueRepository::class);

    $queue = new Queue([
        'tenant_id' => $tenantId,
        'facility_id' => $facilityId,
        'code' => 'CONSULT',
        'name' => 'Consultation',
        'ticket_prefix' => 'C',
    ]);
    TenantContextScope::run($tenantId, $facilityId, fn () => $queueRepo->save($queue));

    $encounterId = (string) Str::uuid();
    $patientId = (string) Str::uuid();

    // Placed originally as routine
    $ticket = TenantContextScope::run($tenantId, $facilityId, fn () => $api->place(new PlaceInQueueDto(
        encounterId: $encounterId,
        patientId: $patientId,
        queueId: $queue->id,
        priority: 'ROUTINE',
    )));
    expect($ticket->priority)->toBe('ROUTINE');

    // Nurse completes triage assessment with EMERGENCY acuity
    $event = new TriageAssessmentRecorded(
        eventId: (string) Str::uuid(),
        tenantId: $tenantId,
        facilityId: $facilityId,
        patientId: $patientId,
        encounterId: $encounterId,
        triageAssessmentId: (string) Str::uuid(),
        triagePriority: $triagePriority,
        occurredAt: (string) now()->toISOString(),
        correlationId: (string) Str::uuid(),
        causationId: null,
    );

    TenantContextScope::run($tenantId, $facilityId, function () use ($event): void {
        app(OnTriageAssessmentRecorded::class)($event);
    });

    TenantContextScope::run($tenantId, $facilityId, function () use ($ticket, $expectedPriority): void {
        $entry = QueueEntry::query()->find($ticket->id);
        expect($entry->priority)->toBe($expectedPriority);
    });
})->with([
    'emergency' => ['EMERGENCY', QueuePriority::EMERGENCY],
    'urgent' => ['URGENT', QueuePriority::URGENT],
    'high' => ['HIGH', QueuePriority::PRIORITY],
    'normal' => ['NORMAL', QueuePriority::ROUTINE],
    'low' => ['LOW', QueuePriority::ROUTINE],
]);

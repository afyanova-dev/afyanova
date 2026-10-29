<?php

declare(strict_types=1);

use App\Platform\Queueing\Contracts\DTO\CallNextDto;
use App\Platform\Queueing\Contracts\DTO\PlaceInQueueDto;
use App\Platform\Queueing\Contracts\QueueingApi;
use App\Platform\Queueing\Domain\Model\Queue;
use App\Platform\Queueing\Domain\Model\QueueEntry;
use App\Platform\Queueing\Domain\Repository\QueueRepository;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Support\Str;

require_once __DIR__.'/../../../../tests/Support/queueing.php';

it('enforces row level security across tenants in queueing platform service', function (): void {
    $fxA = queueingFixture();
    $tenantA = (string) $fxA['tenant']->id;
    $facilityA = (string) $fxA['facility']->id;

    $fxB = queueingFixture();
    $tenantB = (string) $fxB['tenant']->id;
    $facilityB = (string) $fxB['facility']->id;

    $api = app(QueueingApi::class);
    $queueRepo = app(QueueRepository::class);

    // Tenant A creates queue, acting as its own user for bus authorization.
    $this->actingAs($fxA['user']);
    $queueA = new Queue([
        'tenant_id' => $tenantA,
        'facility_id' => $facilityA,
        'code' => 'OPD',
        'name' => 'Tenant A OPD',
        'ticket_prefix' => 'A',
    ]);
    TenantContextScope::run($tenantA, $facilityA, fn () => $queueRepo->save($queueA));

    // Tenant A enqueues patient
    $ticketA = TenantContextScope::run($tenantA, $facilityA, fn () => $api->place(new PlaceInQueueDto(
        encounterId: (string) Str::uuid(),
        patientId: (string) Str::uuid(),
        queueId: $queueA->id,
    )));

    // Tenant B executes query under Tenant B's RLS scope, as its own user.
    $this->actingAs($fxB['user']);
    TenantContextScope::run($tenantB, $facilityB, function () use ($ticketA, $queueA, $api): void {
        // Direct model query in Tenant B cannot see Tenant A's entry
        $entryInB = QueueEntry::query()->find($ticketA->id);
        expect($entryInB)->toBeNull();

        // Calling from Queue A within Tenant B context yields null
        $called = $api->callNext(new CallNextDto(
            queueId: $queueA->id,
            roomId: (string) Str::uuid(),
        ));
        expect($called)->toBeNull();
    });
});

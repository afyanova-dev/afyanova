<?php

declare(strict_types=1);

use App\Domains\Inventory\Application\Command\ReceiveStock;
use App\Domains\Inventory\Domain\Model\StockBalance;
use App\Domains\Inventory\Domain\Model\StockMovement;
use App\Domains\Laboratory\Domain\Model\LabExecution;
use App\Domains\Laboratory\Domain\Model\LabExecutionMaterialRequirement;
use App\Domains\Laboratory\Domain\Model\LabExecutionMaterialUse;
use App\Domains\Laboratory\Domain\Model\LabMethodInventoryRequirement;
use App\Domains\Laboratory\Domain\Model\LabOrderItem;
use App\Domains\Laboratory\Domain\Model\LabSpecimen;
use App\Domains\Laboratory\Domain\Model\LabTestMethod;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * @return array{
 *     tenantId: string,
 *     facilityId: string,
 *     user: mixed,
 *     orderId: string,
 *     orderItemId: string,
 *     specimenId: string,
 *     executionId: string,
 *     requirementId: string,
 *     itemId: string,
 *     locationId: string
 * }
 */
function labExecutionScenario(bool $grantInventoryPermissions = true): array
{
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = laboratoryFixture();
    $tenantId = (string) $tenant->id;
    $facilityId = (string) $facility->id;
    $userId = (string) $user->id;

    $inventoryPermissions = ['inventory.access', 'inventory.receipts.create'];
    if ($grantInventoryPermissions) {
        $inventoryPermissions[] = 'inventory.consumption.record';
    }
    grantInventoryRole($tenantId, $facilityId, $userId, $inventoryPermissions);

    ['orderId' => $orderId, 'specimenId' => $specimenId, 'orderItemId' => $orderItemId] =
        seedResultReadyLabOrder($tenantId, $facilityId, $userId);
    $item = seedInventoryItem($tenantId, [
        'code' => 'LAB-REAGENT',
        'name' => 'Laboratory reagent',
        'item_type' => 'CONSUMABLE',
        'uom' => 'ML',
    ]);
    $location = seedStockLocation($tenantId, $facilityId, ['code' => 'LAB-STORE']);
    $executionId = (string) Str::uuid7();
    $requirementId = (string) Str::uuid7();

    TenantContextScope::run($tenantId, $facilityId, function () use (
        $tenantId,
        $orderItemId,
        $specimenId,
        $item,
        $requirementId,
    ): void {
        LabSpecimen::query()->whereKey($specimenId)->update(['container_type' => 'EDTA_TUBE']);
        $orderItem = LabOrderItem::query()->findOrFail($orderItemId);
        $method = LabTestMethod::query()->create([
            'tenant_id' => $tenantId,
            'test_definition_id' => (string) $orderItem->test_definition_id,
            'code' => 'LAB-METHOD',
            'name' => 'Laboratory method',
            'specimen_type' => 'WHOLE_BLOOD',
            'active' => true,
            'version' => 1,
        ]);

        LabMethodInventoryRequirement::query()->create([
            'id' => $requirementId,
            'tenant_id' => $tenantId,
            'method_id' => (string) $method->id,
            'method_version' => 1,
            'inventory_item_id' => (string) $item->id,
            'item_code_snapshot' => (string) $item->code,
            'item_name_snapshot' => (string) $item->name,
            'item_uom_snapshot' => (string) $item->uom,
            'item_type_snapshot' => (string) $item->item_type,
            'item_category_snapshot' => $item->category,
            'requirement_role' => 'REAGENT',
            'required' => true,
            'usage_scope' => 'PER_TEST',
            'quantity' => '0.5000',
        ]);
    });

    dispatchInv($tenantId, $facilityId, new ReceiveStock(
        movementId: (string) Str::uuid7(),
        actorId: $userId,
        itemId: (string) $item->id,
        locationId: (string) $location->id,
        quantity: '10',
    ));

    test()->actingAs($user)->withSession(['active_facility_id' => $facilityId]);

    return [
        'tenantId' => $tenantId,
        'facilityId' => $facilityId,
        'user' => $user,
        'orderId' => $orderId,
        'orderItemId' => $orderItemId,
        'specimenId' => $specimenId,
        'executionId' => $executionId,
        'requirementId' => $requirementId,
        'itemId' => (string) $item->id,
        'locationId' => (string) $location->id,
    ];
}

it('snapshots required materials and records actual consumption before completing an execution', function (): void {
    $scenario = labExecutionScenario();
    $methodId = TenantContextScope::run(
        $scenario['tenantId'],
        $scenario['facilityId'],
        fn (): string => (string) LabTestMethod::query()->value('id'),
    );

    $this->post(route('laboratory.executions.store', [
        'order' => $scenario['orderId'],
        'item' => $scenario['orderItemId'],
    ]), [
        'execution_id' => $scenario['executionId'],
        'specimen_id' => $scenario['specimenId'],
        'method_id' => $methodId,
    ])->assertRedirect(route('laboratory.executions.show', ['execution' => $scenario['executionId']]));

    $snapshot = TenantContextScope::run(
        $scenario['tenantId'],
        $scenario['facilityId'],
        fn () => LabExecutionMaterialRequirement::query()
            ->where('execution_id', $scenario['executionId'])
            ->first(),
    );
    expect($snapshot)->not->toBeNull()
        ->and($snapshot->inventory_item_id)->toBe($scenario['itemId'])
        ->and((string) $snapshot->quantity)->toBe('0.5000');

    $useId = (string) Str::uuid7();
    $materialUseRoute = route('laboratory.executions.material-uses.store', [
        'execution' => $scenario['executionId'],
        'requirement' => $snapshot->id,
    ]);
    $payload = [
        'use_id' => $useId,
        'location_id' => $scenario['locationId'],
        'quantity' => '0.5000',
    ];

    $this->from(route('laboratory.executions.show', ['execution' => $scenario['executionId']]))
        ->post($materialUseRoute, $payload)
        ->assertRedirect(route('laboratory.executions.show', ['execution' => $scenario['executionId']]))
        ->assertSessionHasNoErrors();

    $this->from(route('laboratory.executions.show', ['execution' => $scenario['executionId']]))
        ->post($materialUseRoute, $payload)
        ->assertRedirect(route('laboratory.executions.show', ['execution' => $scenario['executionId']]))
        ->assertSessionHasNoErrors();

    $use = TenantContextScope::run(
        $scenario['tenantId'],
        $scenario['facilityId'],
        fn () => LabExecutionMaterialUse::query()->findOrFail($useId),
    );
    $balance = TenantContextScope::run(
        $scenario['tenantId'],
        $scenario['facilityId'],
        fn () => StockBalance::query()
            ->where('item_id', $scenario['itemId'])
            ->where('location_id', $scenario['locationId'])
            ->firstOrFail(),
    );
    $consumptionCount = TenantContextScope::run(
        $scenario['tenantId'],
        $scenario['facilityId'],
        fn (): int => StockMovement::query()
            ->where('movement_type', StockMovement::TYPE_CONSUMPTION)
            ->where('source_ref', $scenario['executionId'])
            ->count(),
    );

    expect($use->status)->toBe(LabExecutionMaterialUse::STATUS_CONSUMED)
        ->and((string) $balance->on_hand_qty)->toBe('9.5000')
        ->and($consumptionCount)->toBe(1);

    $this->post(route('laboratory.executions.complete', ['execution' => $scenario['executionId']]))
        ->assertRedirect();

    $execution = TenantContextScope::run(
        $scenario['tenantId'],
        $scenario['facilityId'],
        fn () => LabExecution::query()->findOrFail($scenario['executionId']),
    );
    expect($execution->status)->toBe(LabExecution::STATUS_COMPLETED);

    $this->get(route('laboratory.executions.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Laboratory/ExecutionWorklist')
            ->has('queue', 0));

    $this->from(route('laboratory.executions.index'))
        ->post(route('laboratory.executions.store', [
            'order' => $scenario['orderId'],
            'item' => $scenario['orderItemId'],
        ]), [
            'execution_id' => (string) Str::uuid7(),
            'specimen_id' => $scenario['specimenId'],
            'method_id' => $methodId,
        ])
        ->assertRedirect(route('laboratory.executions.index'))
        ->assertSessionHasErrors('order_item_id');
});

it('keeps already consumed stock consumed when an execution is aborted', function (): void {
    $scenario = labExecutionScenario();
    $methodId = TenantContextScope::run(
        $scenario['tenantId'],
        $scenario['facilityId'],
        fn (): string => (string) LabTestMethod::query()->value('id'),
    );
    $this->post(route('laboratory.executions.store', [
        'order' => $scenario['orderId'],
        'item' => $scenario['orderItemId'],
    ]), [
        'execution_id' => $scenario['executionId'],
        'specimen_id' => $scenario['specimenId'],
        'method_id' => $methodId,
    ]);

    $requirementId = TenantContextScope::run(
        $scenario['tenantId'],
        $scenario['facilityId'],
        fn (): string => (string) LabExecutionMaterialRequirement::query()
            ->where('execution_id', $scenario['executionId'])
            ->value('id'),
    );
    $this->post(route('laboratory.executions.material-uses.store', [
        'execution' => $scenario['executionId'],
        'requirement' => $requirementId,
    ]), [
        'use_id' => (string) Str::uuid7(),
        'location_id' => $scenario['locationId'],
        'quantity' => '0.5000',
    ]);

    $this->post(route('laboratory.executions.abort', ['execution' => $scenario['executionId']]), [
        'reason' => 'Instrument stopped during the run.',
    ])->assertRedirect();

    $balance = TenantContextScope::run(
        $scenario['tenantId'],
        $scenario['facilityId'],
        fn () => StockBalance::query()
            ->where('item_id', $scenario['itemId'])
            ->where('location_id', $scenario['locationId'])
            ->firstOrFail(),
    );
    $movements = TenantContextScope::run(
        $scenario['tenantId'],
        $scenario['facilityId'],
        fn () => StockMovement::query()->where('source_ref', $scenario['executionId'])->get(),
    );
    $execution = TenantContextScope::run(
        $scenario['tenantId'],
        $scenario['facilityId'],
        fn () => LabExecution::query()->findOrFail($scenario['executionId']),
    );

    expect($execution->status)->toBe(LabExecution::STATUS_ABORTED)
        ->and((string) $balance->on_hand_qty)->toBe('9.5000')
        ->and($movements)->toHaveCount(1)
        ->and($movements->first()->movement_type)->toBe(StockMovement::TYPE_CONSUMPTION);

    $this->get(route('laboratory.executions.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Laboratory/ExecutionWorklist')
            ->has('queue', 1)
            ->where('queue.0.orderItemId', $scenario['orderItemId'])
            ->where('queue.0.executionId', null));

    $retryExecutionId = (string) Str::uuid7();
    $this->post(route('laboratory.executions.store', [
        'order' => $scenario['orderId'],
        'item' => $scenario['orderItemId'],
    ]), [
        'execution_id' => $retryExecutionId,
        'specimen_id' => $scenario['specimenId'],
        'method_id' => $methodId,
    ])->assertRedirect(route('laboratory.executions.show', ['execution' => $retryExecutionId]));

    $retryExecution = TenantContextScope::run(
        $scenario['tenantId'],
        $scenario['facilityId'],
        fn () => LabExecution::query()->findOrFail($retryExecutionId),
    );
    expect($retryExecution->attempt_number)->toBe(2)
        ->and($retryExecution->status)->toBe(LabExecution::STATUS_IN_PROGRESS);
});

it('keeps an Inventory-denied material attempt pending so an authorized retry can finish it', function (): void {
    $scenario = labExecutionScenario(grantInventoryPermissions: false);
    $methodId = TenantContextScope::run(
        $scenario['tenantId'],
        $scenario['facilityId'],
        fn (): string => (string) LabTestMethod::query()->value('id'),
    );
    $this->post(route('laboratory.executions.store', [
        'order' => $scenario['orderId'],
        'item' => $scenario['orderItemId'],
    ]), [
        'execution_id' => $scenario['executionId'],
        'specimen_id' => $scenario['specimenId'],
        'method_id' => $methodId,
    ]);

    $requirementId = TenantContextScope::run(
        $scenario['tenantId'],
        $scenario['facilityId'],
        fn (): string => (string) LabExecutionMaterialRequirement::query()
            ->where('execution_id', $scenario['executionId'])
            ->value('id'),
    );
    $useId = (string) Str::uuid7();
    $route = route('laboratory.executions.material-uses.store', [
        'execution' => $scenario['executionId'],
        'requirement' => $requirementId,
    ]);
    $payload = [
        'use_id' => $useId,
        'location_id' => $scenario['locationId'],
        'quantity' => '0.5000',
    ];

    $this->post($route, $payload)->assertForbidden();

    $pendingUse = TenantContextScope::run(
        $scenario['tenantId'],
        $scenario['facilityId'],
        fn () => LabExecutionMaterialUse::query()->findOrFail($useId),
    );
    expect($pendingUse->status)->toBe(LabExecutionMaterialUse::STATUS_PENDING);

    grantInventoryRole($scenario['tenantId'], $scenario['facilityId'], (string) $scenario['user']->id, [
        'inventory.consumption.record',
    ]);

    $this->post($route, $payload)
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $completedUse = TenantContextScope::run(
        $scenario['tenantId'],
        $scenario['facilityId'],
        fn () => LabExecutionMaterialUse::query()->findOrFail($useId),
    );
    expect($completedUse->status)->toBe(LabExecutionMaterialUse::STATUS_CONSUMED);
});

it('rejects a repeated execution identifier when the specimen method or instrument selection changes', function (): void {
    $scenario = labExecutionScenario();
    $methodId = TenantContextScope::run(
        $scenario['tenantId'],
        $scenario['facilityId'],
        fn (): string => (string) LabTestMethod::query()->value('id'),
    );
    $route = route('laboratory.executions.store', [
        'order' => $scenario['orderId'],
        'item' => $scenario['orderItemId'],
    ]);
    $payload = [
        'execution_id' => $scenario['executionId'],
        'specimen_id' => $scenario['specimenId'],
        'method_id' => $methodId,
    ];

    $this->post($route, $payload)
        ->assertRedirect(route('laboratory.executions.show', ['execution' => $scenario['executionId']]))
        ->assertSessionHasNoErrors();
    $this->post($route, $payload)
        ->assertRedirect(route('laboratory.executions.show', ['execution' => $scenario['executionId']]))
        ->assertSessionHasNoErrors();

    foreach ([
        'specimen_id' => (string) Str::uuid7(),
        'method_id' => (string) Str::uuid7(),
        'instrument_id' => (string) Str::uuid7(),
    ] as $field => $value) {
        $this->from(route('laboratory.executions.index'))
            ->post($route, [...$payload, $field => $value])
            ->assertRedirect(route('laboratory.executions.index'))
            ->assertSessionHasErrors('execution_id');
    }

    $executionCount = TenantContextScope::run(
        $scenario['tenantId'],
        $scenario['facilityId'],
        fn (): int => LabExecution::query()->count(),
    );
    expect($executionCount)->toBe(1);
});

it('rejects execution when the selected specimen container does not match the test configuration', function (): void {
    $scenario = labExecutionScenario();
    $methodId = TenantContextScope::run(
        $scenario['tenantId'],
        $scenario['facilityId'],
        function (): string {
            $method = LabTestMethod::query()->firstOrFail();
            $method->container_type = 'CITRATE_TUBE';
            $method->save();

            return (string) $method->id;
        },
    );

    $this->from(route('laboratory.executions.index'))
        ->post(route('laboratory.executions.store', [
            'order' => $scenario['orderId'],
            'item' => $scenario['orderItemId'],
        ]), [
            'execution_id' => $scenario['executionId'],
            'specimen_id' => $scenario['specimenId'],
            'method_id' => $methodId,
        ])
        ->assertRedirect(route('laboratory.executions.index'))
        ->assertSessionHasErrors('specimen_id');

    $executionCount = TenantContextScope::run(
        $scenario['tenantId'],
        $scenario['facilityId'],
        fn (): int => LabExecution::query()->count(),
    );
    expect($executionCount)->toBe(0);
});

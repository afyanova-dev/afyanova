<?php

declare(strict_types=1);

use App\Domains\Organization\Domain\Model\Facility;
use App\Domains\Procurement\Application\Query\GetOpenPurchaseOrders;
use App\Domains\Procurement\Application\Query\GetPurchaseOrder;
use App\Domains\Procurement\Application\Query\GetPurchaseRequisition;
use App\Domains\Procurement\Application\Query\SearchPurchaseOrders;
use App\Domains\Procurement\Domain\Model\PurchaseOrder;
use App\Domains\Procurement\Domain\Model\PurchaseRequisition;
use App\Domains\Procurement\Domain\Model\RequisitionLine;
use App\Domains\Procurement\Domain\Model\Supplier;
use App\Models\User;
use App\Shared\Domain\NotFound;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

uses(RefreshDatabase::class);

/**
 * Procurement workspace → Requests (Shape B).
 *
 * The command handlers already own the requisition lifecycle and the
 * different-actor rule. These tests pin the HTTP layer: who may read, who may
 * write, the department on the request, and — the point of the whole flow —
 * that an approved request carries its department into the purchase order's
 * money limit.
 */

/**
 * @param  list<string>|null  $permissions
 * @return array{t: string, f: string, user: User, org: string, item: string}
 */
function requestHttpFixture(?array $permissions = null): array
{
    ['tenant' => $tenant, 'organization' => $organization, 'facility' => $facility, 'user' => $user]
        = procurementFixture($permissions);
    $t = (string) $tenant->id;
    $f = (string) $facility->id;
    $item = seedInventoryItem($t, ['code' => 'GLU-100', 'name' => 'Glucose strips', 'uom' => 'BOX']);

    return [
        't' => $t,
        'f' => $f,
        'user' => $user,
        'org' => (string) $organization->id,
        'item' => (string) $item->id,
    ];
}

function seedRequestDepartment(string $tenantId, string $facilityId, string $code): string
{
    $departmentId = (string) Str::uuid7();

    TenantContextScope::run($tenantId, null, function () use ($tenantId, $facilityId, $departmentId, $code): void {
        DB::table('org_departments')->insert([
            'id' => $departmentId,
            'tenant_id' => $tenantId,
            'facility_id' => $facilityId,
            'name' => 'Department '.$code,
            'code' => $code,
            'status' => 'ACTIVE',
            'version' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    return $departmentId;
}

function readExportWorkbook(TestResponse $response): Spreadsheet
{
    $path = tempnam(sys_get_temp_dir(), 'pcr-xlsx-');
    if ($path === false) {
        throw new RuntimeException('Could not create a temporary XLSX test file.');
    }

    try {
        file_put_contents($path, $response->streamedContent());

        return IOFactory::load($path);
    } finally {
        unlink($path);
    }
}

/**
 * @param  array{t: string, f: string, user: User, org: string, item: string}  $fixture
 */
function raiseRequest(
    array $fixture,
    ?string $departmentRef = null,
    string $quantity = '10',
    ?string $unit = null,
    ?string $packSize = null,
): string {
    test()->post('/workspaces/procurement/requests', [
        'requesting_department_ref' => $departmentRef,
        'priority' => 'ROUTINE',
        'reason' => 'Monthly reagents',
        'lines' => [[
            'item_id' => $fixture['item'],
            'quantity' => $quantity,
            'unit' => $unit,
            'pack_size' => $packSize,
        ]],
    ])->assertRedirect()->assertSessionHas('status');

    return (string) TenantContextScope::run(
        $fixture['t'],
        null,
        fn () => PurchaseRequisition::query()->orderByDesc('recorded_at')->orderByDesc('id')->value('id'),
    );
}

/**
 * @param  array{t: string, f: string, user: User, org: string, item: string}  $fixture
 */
function requisitionStatus(array $fixture, string $requisitionId): string
{
    return (string) TenantContextScope::run(
        $fixture['t'],
        null,
        fn () => PurchaseRequisition::query()->findOrFail($requisitionId)->status,
    );
}

it('renders the requests screen with the creator actions and the department name', function (): void {
    $fixture = requestHttpFixture();
    $departmentRef = seedRequestDepartment($fixture['t'], $fixture['f'], 'LAB');
    $requisitionId = raiseRequest($fixture, $departmentRef);

    $this->get('/workspaces/procurement?section=requests')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Procurement/Index')
            ->where('section', 'requests')
            ->where('canCreateRequests', true)
            ->where('canCreateForAnyDepartment', true)
            ->where('currentUserId', (string) $fixture['user']->id)
            ->has('items', 1)
            ->where('items.0.id', $fixture['item'])
            ->where('items.0.code', 'GLU-100')
            ->has('departments', 1)
            ->where('departments.0.id', $departmentRef)
            ->has('purchaseRequisitions', 1)
            ->where('purchaseRequisitions.0.id', $requisitionId)
            ->where('purchaseRequisitions.0.status', PurchaseRequisition::STATUS_DRAFT)
            ->where('purchaseRequisitions.0.departmentRef', $departmentRef)
            ->where('purchaseRequisitions.0.departmentName', 'Department LAB')
            ->where('purchaseRequisitions.0.allowedActions', ['submit', 'edit', 'cancel'])
            ->where('purchaseRequisitions.0.lines.0.itemCode', 'GLU-100')
            ->where('purchaseRequisitions.0.lines.0.itemRef', $fixture['item'])
            ->where('purchaseRequisitions.0.lines.0.quantity', '10.0000')
            ->where('purchaseRequisitions.0.lines.0.uom', 'BOX'));
});

it('lets the creator update a draft requisition and records an audit entry', function (): void {
    $fixture = requestHttpFixture();
    $departmentRef = seedRequestDepartment($fixture['t'], $fixture['f'], 'LAB');
    $requisitionId = raiseRequest($fixture, $departmentRef);

    $this->patch('/workspaces/procurement/requests/'.$requisitionId, [
        'requesting_department_ref' => $departmentRef,
        'priority' => 'URGENT',
        'reason' => 'Corrected monthly demand',
        'lines' => [[
            'item_id' => $fixture['item'],
            'quantity' => '25',
        ]],
    ])->assertRedirect()->assertSessionHas('status', 'Draft request updated.');

    TenantContextScope::run($fixture['t'], null, function () use ($requisitionId, $departmentRef, $fixture): void {
        $requisition = PurchaseRequisition::query()->findOrFail($requisitionId);
        $line = RequisitionLine::query()->where('requisition_id', $requisitionId)->firstOrFail();

        expect($requisition->priority)->toBe('URGENT')
            ->and($requisition->reason)->toBe('Corrected monthly demand')
            ->and($requisition->requesting_department_ref)->toBe($departmentRef)
            ->and($line->quantity)->toBe('25.0000')
            ->and((string) $line->item_ref)->toBe($fixture['item'])
            ->and(RequisitionLine::query()->where('requisition_id', $requisitionId)->count())->toBe(1);
    });

    expect(DB::table('shared_audit_log')
        ->where('action', 'procurement.requisition_draft_updated')
        ->where('target_id', $requisitionId)
        ->exists())->toBeTrue();
});

it('does not let another requester edit a draft or its creator edit it after submission', function (): void {
    $fixture = requestHttpFixture();
    $requisitionId = raiseRequest($fixture);
    $otherUser = User::factory()->forTenant($fixture['t'])->create();
    grantProcurementRole(
        $fixture['t'],
        $fixture['f'],
        (string) $otherUser->id,
        ['procurement.access', 'procurement.requisitions.create'],
    );

    $this->actingAs($otherUser)
        ->patch('/workspaces/procurement/requests/'.$requisitionId, [
            'priority' => 'URGENT',
            'lines' => [['item_id' => $fixture['item'], 'quantity' => '99']],
        ])
        ->assertForbidden();

    $this->actingAs($fixture['user'])
        ->post('/workspaces/procurement/requests/'.$requisitionId.'/submit')
        ->assertRedirect();
    $this->patch('/workspaces/procurement/requests/'.$requisitionId, [
        'priority' => 'URGENT',
        'lines' => [['item_id' => $fixture['item'], 'quantity' => '99']],
    ])->assertForbidden();

    expect(requisitionStatus($fixture, $requisitionId))->toBe(PurchaseRequisition::STATUS_SUBMITTED);
});

it('does not let a department-scoped requester move a draft outside their department', function (): void {
    $fixture = requestHttpFixture();
    $labId = seedRequestDepartment($fixture['t'], $fixture['f'], 'LAB');
    $pharmacyId = seedRequestDepartment($fixture['t'], $fixture['f'], 'PHD');
    $departmentUser = User::factory()->forTenant($fixture['t'])->create();
    grantProcurementRole(
        $fixture['t'],
        $fixture['f'],
        (string) $departmentUser->id,
        ['procurement.access', 'procurement.requisitions.create'],
        $labId,
    );

    $this->actingAs($departmentUser);
    $requisitionId = raiseRequest($fixture, $labId);

    $this->patch('/workspaces/procurement/requests/'.$requisitionId, [
        'requesting_department_ref' => $pharmacyId,
        'priority' => 'URGENT',
        'reason' => 'Move to another department',
        'lines' => [['item_id' => $fixture['item'], 'quantity' => '99']],
    ])->assertForbidden();

    TenantContextScope::run($fixture['t'], null, function () use ($requisitionId, $labId): void {
        $requisition = PurchaseRequisition::query()->findOrFail($requisitionId);
        $line = RequisitionLine::query()->where('requisition_id', $requisitionId)->firstOrFail();

        expect($requisition->requesting_department_ref)->toBe($labId)
            ->and($requisition->priority)->toBe('ROUTINE')
            ->and($line->quantity)->toBe('10.0000');
    });
});

it('lets a department-scoped procurement user read shared suppliers and only their department purchase orders', function (): void {
    $fixture = requestHttpFixture();
    $supplier = seedSupplier($fixture['t'], [
        'code' => 'ACME-01',
        'name' => 'Acme Medical',
        'status' => Supplier::STATUS_ACTIVE,
    ]);
    $labId = seedRequestDepartment($fixture['t'], $fixture['f'], 'LAB');
    $pharmacyId = seedRequestDepartment($fixture['t'], $fixture['f'], 'PHD');
    $labRequestId = raiseRequest($fixture, $labId);
    $pharmacyRequestId = raiseRequest($fixture, $pharmacyId);

    $this->post('/workspaces/procurement/requests/'.$labRequestId.'/submit')->assertRedirect();
    $this->post('/workspaces/procurement/requests/'.$pharmacyRequestId.'/submit')->assertRedirect();

    $reviewer = User::factory()->forTenant($fixture['t'])->create();
    grantProcurementRole(
        $fixture['t'],
        $fixture['f'],
        (string) $reviewer->id,
        ['procurement.access', 'procurement.requisitions.approve'],
    );
    $this->actingAs($reviewer)
        ->post('/workspaces/procurement/requests/'.$labRequestId.'/approve')
        ->assertRedirect();
    $this->post('/workspaces/procurement/requests/'.$pharmacyRequestId.'/approve')
        ->assertRedirect();

    $this->actingAs($fixture['user']);
    foreach ([$labRequestId, $pharmacyRequestId] as $requestId) {
        $this->post('/workspaces/procurement/orders', [
            'supplier_id' => (string) $supplier->id,
            'requisition_id' => $requestId,
            'priority' => 'ROUTINE',
            'lines' => [[
                'item_id' => $fixture['item'],
                'quantity' => '1',
                'unit_price' => '100',
            ]],
        ])->assertRedirect()->assertSessionHas('status');
    }

    $departmentUser = User::factory()->forTenant($fixture['t'])->create();
    grantProcurementRole(
        $fixture['t'],
        $fixture['f'],
        (string) $departmentUser->id,
        ['procurement.access'],
        $labId,
    );

    $this->actingAs($departmentUser)
        ->get('/workspaces/procurement?section=suppliers')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('section', 'suppliers')
            ->where('canManageSuppliers', false)
            ->has('suppliers', 1)
            ->where('suppliers.0.id', (string) $supplier->id));

    $this->get('/workspaces/procurement?section=orders')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('section', 'orders')
            ->where('canCreateOrders', false)
            ->has('purchaseOrders', 1)
            ->where('purchaseOrders.0.requisitionId', $labRequestId));
});

it('prints requisitions and purchase orders as PDF documents', function (): void {
    $fixture = requestHttpFixture();
    $requisitionId = raiseRequest($fixture);
    $supplier = seedSupplier($fixture['t'], [
        'code' => 'ACME-01',
        'name' => 'Acme Medical',
        'status' => Supplier::STATUS_ACTIVE,
    ]);

    $this->post('/workspaces/procurement/orders', [
        'supplier_id' => (string) $supplier->id,
        'priority' => 'ROUTINE',
        'notes' => 'Deliver during business hours',
        'lines' => [[
            'item_id' => $fixture['item'],
            'quantity' => '2',
            'unit_price' => '125.50',
        ]],
    ])->assertRedirect()->assertSessionHas('status');
    $orderId = (string) TenantContextScope::run(
        $fixture['t'],
        null,
        fn () => PurchaseOrder::query()->latest('recorded_at')->value('id'),
    );

    $this->get('/workspaces/procurement/requests/'.$requisitionId.'/pdf')
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf')
        ->assertSee('%PDF-', false);

    $this->get('/workspaces/procurement/orders/'.$orderId.'/pdf')
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf')
        ->assertSee('%PDF-', false);

    $export = $this->get('/workspaces/procurement/orders/export.xlsx?status=DRAFT')
        ->assertOk()
        ->assertHeader(
            'Content-Type',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        );
    $workbook = readExportWorkbook($export);
    $order = queryProc($fixture['t'], $fixture['f'], new GetPurchaseOrder($orderId));

    expect($workbook->getSheetNames())->toBe(['Purchase Orders', 'Order Lines'])
        ->and($workbook->getSheet(0)->getCell('A2')->getValue())->toBe($order->poNumber)
        ->and($workbook->getSheet(1)->getCell('A2')->getValue())->toBe($order->poNumber);
    $workbook->disconnectWorksheets();
});

it('exports the visible purchase requests and their lines to a filtered Excel workbook', function (): void {
    $fixture = requestHttpFixture();
    $draftId = raiseRequest($fixture, quantity: '7');
    $submittedId = raiseRequest($fixture, quantity: '9');
    $this->post('/workspaces/procurement/requests/'.$submittedId.'/submit')->assertRedirect();

    $response = $this->get('/workspaces/procurement/requests/export.xlsx?status=DRAFT')
        ->assertOk()
        ->assertHeader(
            'Content-Type',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        )
        ->assertHeader('content-disposition', 'attachment; filename=purchase-requests-'.now()->format('Y-m-d').'.xlsx');

    $workbook = readExportWorkbook($response);
    $draft = queryProc($fixture['t'], $fixture['f'], new GetPurchaseRequisition($draftId, $fixture['f']));

    expect($workbook->getSheetNames())->toBe(['Purchase Requests', 'Request Lines'])
        ->and($workbook->getSheet(0)->getCell('A2')->getValue())->toBe($draft->requisitionNumber)
        ->and($workbook->getSheet(0)->getCell('A3')->getValue())->toBeNull()
        ->and($workbook->getSheet(1)->getCell('A2')->getValue())->toBe($draft->requisitionNumber);
    $workbook->disconnectWorksheets();

    expect($draftId)->not->toBe($submittedId);
});

it('limits purchase order exports and receipt pickers to the assigned department', function (): void {
    $fixture = requestHttpFixture();
    $supplier = seedSupplier($fixture['t'], [
        'code' => 'ACME-01',
        'name' => 'Acme Medical',
        'status' => Supplier::STATUS_ACTIVE,
    ]);
    $labId = seedRequestDepartment($fixture['t'], $fixture['f'], 'LAB');
    $pharmacyId = seedRequestDepartment($fixture['t'], $fixture['f'], 'PHD');
    $labRequestId = raiseRequest($fixture, $labId);
    $pharmacyRequestId = raiseRequest($fixture, $pharmacyId);

    $orders = TenantContextScope::run($fixture['t'], null, function () use (
        $fixture,
        $supplier,
        $labRequestId,
        $pharmacyRequestId,
    ): array {
        $labOrder = PurchaseOrder::factory()->forTenant($fixture['t'])->issued()->create([
            'facility_id' => $fixture['f'],
            'supplier_id' => (string) $supplier->id,
            'requisition_id' => $labRequestId,
        ]);
        $pharmacyOrder = PurchaseOrder::factory()->forTenant($fixture['t'])->issued()->create([
            'facility_id' => $fixture['f'],
            'supplier_id' => (string) $supplier->id,
            'requisition_id' => $pharmacyRequestId,
        ]);

        return [(string) $labOrder->id, (string) $pharmacyOrder->id];
    });

    $departmentUser = User::factory()->forTenant($fixture['t'])->create();
    grantProcurementRole(
        $fixture['t'],
        $fixture['f'],
        (string) $departmentUser->id,
        ['procurement.access'],
        $labId,
    );
    $this->actingAs($departmentUser);

    $ordersVisible = queryProc($fixture['t'], $fixture['f'], new SearchPurchaseOrders($fixture['f']));
    $openOrders = queryProc($fixture['t'], $fixture['f'], new GetOpenPurchaseOrders($fixture['f']));

    expect(array_map(static fn ($order): string => $order->purchaseOrderId, $ordersVisible))
        ->toBe([$orders[0]])
        ->and(array_map(static fn ($order): string => $order->purchaseOrderId, $openOrders))
        ->toBe([$orders[0]]);

    $visibleOrder = $ordersVisible[0] ?? throw new RuntimeException('Expected one purchase order in the assigned department.');
    $labRequest = queryProc(
        $fixture['t'],
        $fixture['f'],
        new GetPurchaseRequisition($labRequestId, $fixture['f']),
    );
    $this->get('/workspaces/procurement/orders/'.$orders[0].'/pdf')->assertOk();
    $this->getJson('/workspaces/procurement/orders/'.$orders[1].'/pdf')->assertNotFound();
    $this->get('/workspaces/procurement/requests/'.$labRequestId.'/pdf')->assertOk();
    $this->getJson('/workspaces/procurement/requests/'.$pharmacyRequestId.'/pdf')->assertNotFound();

    $orderWorkbook = readExportWorkbook($this->get('/workspaces/procurement/orders/export.xlsx')->assertOk());
    expect($orderWorkbook->getSheet(0)->getCell('A2')->getValue())->toBe($visibleOrder->poNumber)
        ->and($orderWorkbook->getSheet(0)->getCell('D2')->getValue())->toBe($labRequest->requisitionNumber)
        ->and($orderWorkbook->getSheet(0)->getCell('A3')->getValue())->toBeNull();
    $orderWorkbook->disconnectWorksheets();

    $requestWorkbook = readExportWorkbook($this->get('/workspaces/procurement/requests/export.xlsx')->assertOk());
    expect($requestWorkbook->getSheet(0)->getCell('A2')->getValue())->toBe($labRequest->requisitionNumber)
        ->and($requestWorkbook->getSheet(0)->getCell('A3')->getValue())->toBeNull();
    $requestWorkbook->disconnectWorksheets();
});

it('allows a facility-wide requester to create a request without a department', function (): void {
    $fixture = requestHttpFixture();
    $requisitionId = raiseRequest($fixture);

    $requisition = TenantContextScope::run(
        $fixture['t'],
        null,
        fn () => PurchaseRequisition::query()->findOrFail($requisitionId),
    );

    expect($requisition->requesting_department_ref)->toBeNull();
});

it('scopes department request creation, visibility, and actions to the active assignment', function (): void {
    $fixture = requestHttpFixture();
    $labId = seedRequestDepartment($fixture['t'], $fixture['f'], 'LAB');
    $pharmacyId = seedRequestDepartment($fixture['t'], $fixture['f'], 'PHD');
    $labRequestId = raiseRequest($fixture, $labId);
    $pharmacyRequestId = raiseRequest($fixture, $pharmacyId);
    $this->post('/workspaces/procurement/requests/'.$labRequestId.'/submit')->assertRedirect();
    $this->post('/workspaces/procurement/requests/'.$pharmacyRequestId.'/submit')->assertRedirect();

    $departmentUser = User::factory()->forTenant($fixture['t'])->create();
    grantProcurementRole(
        $fixture['t'],
        $fixture['f'],
        (string) $departmentUser->id,
        [
            'procurement.access',
            'procurement.requisitions.create',
            'procurement.requisitions.approve',
            'pharmacy.access',
        ],
        $labId,
    );
    $this->actingAs($departmentUser)
        ->get('/workspaces/procurement?section=requests')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('auth.can', fn ($can): bool => $can->get('procurement.access') === true
                && $can->get('procurement.requisitions.create') === true
                && $can->get('pharmacy.access') === true)
            ->where('canCreateRequests', true)
            ->where('canCreateForAnyDepartment', false)
            ->has('departments', 1)
            ->where('departments.0.id', $labId)
            ->has('purchaseRequisitions', 1)
            ->where('purchaseRequisitions.0.id', $labRequestId)
            ->where('purchaseRequisitions.0.allowedActions', ['approve', 'reject', 'cancel']));

    $this->post('/workspaces/procurement/requests/'.$labRequestId.'/approve')
        ->assertRedirect()
        ->assertSessionHas('status');
    $this->post('/workspaces/procurement/requests/'.$pharmacyRequestId.'/approve')
        ->assertForbidden();
    expect(fn () => queryProc(
        $fixture['t'],
        $fixture['f'],
        new GetPurchaseRequisition($pharmacyRequestId, $fixture['f']),
    ))->toThrow(NotFound::class);

    $this->post('/workspaces/procurement/requests', [
        'requesting_department_ref' => $labId,
        'priority' => 'ROUTINE',
        'lines' => [['item_id' => $fixture['item'], 'quantity' => '1']],
    ])->assertRedirect()->assertSessionHas('status');

    $this->post('/workspaces/procurement/requests', [
        'requesting_department_ref' => $pharmacyId,
        'priority' => 'ROUTINE',
        'lines' => [['item_id' => $fixture['item'], 'quantity' => '1']],
    ])->assertForbidden();

    $this->post('/workspaces/procurement/requests', [
        'priority' => 'ROUTINE',
        'lines' => [['item_id' => $fixture['item'], 'quantity' => '1']],
    ])->assertForbidden();

    $this->post('/workspaces/procurement/requests/'.$pharmacyRequestId.'/submit')
        ->assertForbidden();

    expect(requisitionStatus($fixture, $pharmacyRequestId))
        ->toBe(PurchaseRequisition::STATUS_SUBMITTED);
});

it('combines multiple department assignments without granting other departments', function (): void {
    $fixture = requestHttpFixture();
    $labId = seedRequestDepartment($fixture['t'], $fixture['f'], 'LAB');
    $pharmacyId = seedRequestDepartment($fixture['t'], $fixture['f'], 'PHD');
    $administrationId = seedRequestDepartment($fixture['t'], $fixture['f'], 'ADM');
    $departmentUser = User::factory()->forTenant($fixture['t'])->create();

    foreach ([$labId, $pharmacyId] as $departmentId) {
        grantProcurementRole(
            $fixture['t'],
            $fixture['f'],
            (string) $departmentUser->id,
            ['procurement.access', 'procurement.requisitions.create'],
            $departmentId,
        );
    }

    $this->actingAs($departmentUser)
        ->get('/workspaces/procurement?section=requests')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('canCreateRequests', true)
            ->where('canCreateForAnyDepartment', false)
            ->has('departments', 2));

    foreach ([$labId, $pharmacyId] as $departmentId) {
        $this->post('/workspaces/procurement/requests', [
            'requesting_department_ref' => $departmentId,
            'priority' => 'ROUTINE',
            'lines' => [['item_id' => $fixture['item'], 'quantity' => '1']],
        ])->assertRedirect()->assertSessionHas('status');
    }

    $this->post('/workspaces/procurement/requests', [
        'requesting_department_ref' => $administrationId,
        'priority' => 'ROUTINE',
        'lines' => [['item_id' => $fixture['item'], 'quantity' => '1']],
    ])->assertForbidden();
});

it('raises a DRAFT request with the department and the item snapshot', function (): void {
    $fixture = requestHttpFixture();
    $departmentRef = seedRequestDepartment($fixture['t'], $fixture['f'], 'LAB');

    $requisitionId = raiseRequest($fixture, $departmentRef, '3');

    $requisition = TenantContextScope::run(
        $fixture['t'],
        null,
        fn () => PurchaseRequisition::query()->findOrFail($requisitionId),
    );
    $line = TenantContextScope::run(
        $fixture['t'],
        null,
        fn () => RequisitionLine::query()->where('requisition_id', $requisitionId)->firstOrFail(),
    );

    expect($requisition->status)->toBe(PurchaseRequisition::STATUS_DRAFT)
        ->and((string) $requisition->facility_id)->toBe($fixture['f'])
        ->and($requisition->requesting_department_ref)->toBe($departmentRef)
        ->and($requisition->requisition_number)->toStartWith('PR-')
        ->and($requisition->reason)->toBe('Monthly reagents')
        ->and((string) $requisition->created_by_user_id)->toBe((string) $fixture['user']->id)
        ->and($line->item_code)->toBe('GLU-100')
        ->and($line->item_name)->toBe('Glucose strips')
        ->and((string) $line->quantity)->toBe('3.0000')
        ->and((string) $line->uom)->toBe('BOX');
});

it('refuses a department that belongs to another facility', function (): void {
    $fixture = requestHttpFixture();

    $otherFacility = TenantContextScope::run($fixture['t'], null, fn () => Facility::factory()
        ->forTenant($fixture['t'], $fixture['org'])
        ->create(['name' => 'Second Facility', 'code' => 'SECOND']));
    $foreignDepartment = seedRequestDepartment($fixture['t'], (string) $otherFacility->id, 'THEATRE');

    $this->post('/workspaces/procurement/requests', [
        'requesting_department_ref' => $foreignDepartment,
        'priority' => 'ROUTINE',
        'lines' => [[
            'item_id' => $fixture['item'],
            'quantity' => '1',
        ]],
    ])->assertForbidden();

    expect(TenantContextScope::run(
        $fixture['t'],
        null,
        fn () => PurchaseRequisition::query()->count(),
    ))->toBe(0);
});

it('refuses an inactive department without creating a requisition', function (): void {
    $fixture = requestHttpFixture();
    $departmentRef = seedRequestDepartment($fixture['t'], $fixture['f'], 'LAB');
    TenantContextScope::run($fixture['t'], null, fn () => DB::table('org_departments')
        ->where('id', $departmentRef)
        ->update(['status' => 'INACTIVE']));

    $this->actingAs($fixture['user'])
        ->post('/workspaces/procurement/requests', [
            'requesting_department_ref' => $departmentRef,
            'priority' => 'ROUTINE',
            'lines' => [['item_id' => $fixture['item'], 'quantity' => '1']],
        ])
        ->assertForbidden();

    expect(TenantContextScope::run($fixture['t'], null, fn (): int => PurchaseRequisition::query()->count()))
        ->toBe(0);
});

it('submits and then lets someone else approve', function (): void {
    $fixture = requestHttpFixture();
    $requisitionId = raiseRequest($fixture);

    $this->post('/workspaces/procurement/requests/'.$requisitionId.'/submit')
        ->assertRedirect()
        ->assertSessionHas('status');

    expect(requisitionStatus($fixture, $requisitionId))->toBe(PurchaseRequisition::STATUS_SUBMITTED);

    // The person who raised it can never approve it — no limit relaxes this.
    $this->post('/workspaces/procurement/requests/'.$requisitionId.'/approve')
        ->assertRedirect()
        ->assertSessionHasErrors('request');

    expect(requisitionStatus($fixture, $requisitionId))->toBe(PurchaseRequisition::STATUS_SUBMITTED);

    $reviewer = User::factory()->forTenant($fixture['t'])->create();
    grantProcurementRole($fixture['t'], $fixture['f'], (string) $reviewer->id, [
        'procurement.access', 'procurement.requisitions.approve',
    ]);

    $this->actingAs($reviewer)
        ->post('/workspaces/procurement/requests/'.$requisitionId.'/approve')
        ->assertRedirect()
        ->assertSessionHas('status');

    expect(requisitionStatus($fixture, $requisitionId))->toBe(PurchaseRequisition::STATUS_APPROVED);
});

it('rejects only with a reason', function (): void {
    $fixture = requestHttpFixture();
    $requisitionId = raiseRequest($fixture);
    $this->post('/workspaces/procurement/requests/'.$requisitionId.'/submit')->assertRedirect();

    $reviewer = User::factory()->forTenant($fixture['t'])->create();
    grantProcurementRole($fixture['t'], $fixture['f'], (string) $reviewer->id, [
        'procurement.access', 'procurement.requisitions.approve',
    ]);

    $this->actingAs($reviewer)
        ->post('/workspaces/procurement/requests/'.$requisitionId.'/reject', ['reason' => ''])
        ->assertSessionHasErrors('reason');

    expect(requisitionStatus($fixture, $requisitionId))->toBe(PurchaseRequisition::STATUS_SUBMITTED);

    $this->actingAs($reviewer)
        ->post('/workspaces/procurement/requests/'.$requisitionId.'/reject', [
            'reason' => 'Order it next month',
        ])
        ->assertRedirect()
        ->assertSessionHas('status');

    $requisition = TenantContextScope::run(
        $fixture['t'],
        null,
        fn () => PurchaseRequisition::query()->findOrFail($requisitionId),
    );

    expect($requisition->status)->toBe(PurchaseRequisition::STATUS_REJECTED)
        ->and($requisition->rejection_reason)->toBe('Order it next month')
        ->and((string) $requisition->reviewed_by_user_id)->toBe((string) $reviewer->id);
});

it('cancels a draft request', function (): void {
    $fixture = requestHttpFixture();
    $requisitionId = raiseRequest($fixture);

    $this->post('/workspaces/procurement/requests/'.$requisitionId.'/cancel')
        ->assertRedirect()
        ->assertSessionHas('status');

    expect(requisitionStatus($fixture, $requisitionId))->toBe(PurchaseRequisition::STATUS_CANCELLED);
});

it('gives a reader no request actions and refuses a write', function (): void {
    $fixture = requestHttpFixture(['procurement.access']);

    $requester = User::factory()->forTenant($fixture['t'])->create();
    grantProcurementRole($fixture['t'], $fixture['f'], (string) $requester->id, [
        'procurement.access', 'procurement.requisitions.create',
    ]);

    $this->actingAs($requester);
    $requisitionId = raiseRequest($fixture);

    $this->actingAs($fixture['user'])
        ->get('/workspaces/procurement?section=requests')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('canCreateRequests', false)
            ->where('purchaseRequisitions.0.allowedActions', []));

    $this->actingAs($fixture['user'])
        ->post('/workspaces/procurement/requests/'.$requisitionId.'/submit')
        ->assertForbidden();
});

it('prefills the order panel from an approved request only', function (): void {
    $fixture = requestHttpFixture();
    $item = seedInventoryItem($fixture['t'], [
        'code' => 'GLU-PACK',
        'name' => 'Glucose strips',
        'uom' => 'TABLET',
        'pack_unit' => 'BOX',
        'pack_size' => '100',
    ]);
    $fixture['item'] = (string) $item->id;
    $departmentRef = seedRequestDepartment($fixture['t'], $fixture['f'], 'LAB');
    $requisitionId = raiseRequest($fixture, $departmentRef, '4', 'BOX', '100');

    $line = TenantContextScope::run(
        $fixture['t'],
        null,
        fn () => RequisitionLine::query()->where('requisition_id', $requisitionId)->firstOrFail(),
    );
    expect($line->unit)->toBe('BOX')
        ->and($line->pack_size)->toBe('100.0000')
        ->and($line->ordered_base_qty)->toBe('400.0000');

    $this->post('/workspaces/procurement/requests/'.$requisitionId.'/submit')->assertRedirect();

    $reviewer = User::factory()->forTenant($fixture['t'])->create();
    grantProcurementRole($fixture['t'], $fixture['f'], (string) $reviewer->id, [
        'procurement.access', 'procurement.requisitions.approve',
    ]);

    $this->actingAs($reviewer)
        ->post('/workspaces/procurement/requests/'.$requisitionId.'/approve')
        ->assertRedirect();

    $this->actingAs($fixture['user'])
        ->get('/workspaces/procurement?section=orders&request='.$requisitionId)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('section', 'orders')
            ->where('initialRequest.id', $requisitionId)
            ->where('initialRequest.departmentRef', $departmentRef)
            ->where('initialRequest.departmentName', 'Department LAB')
            ->where('initialRequest.priority', 'ROUTINE')
            ->where('initialRequest.reason', 'Monthly reagents')
            ->where('initialRequest.lines.0.itemId', $fixture['item'])
            ->where('initialRequest.lines.0.itemCode', 'GLU-PACK')
            ->where('initialRequest.lines.0.uom', 'TABLET')
            ->where('initialRequest.lines.0.unit', 'BOX')
            ->where('initialRequest.lines.0.packSize', '100.0000')
            ->where('initialRequest.unorderableLineCount', 0)
            ->where('orderableRequests.0.id', $requisitionId)
            ->where('orderableRequests.0.requisitionNumber', 'PR-00000001'));

    // A request that is not approved cannot be ordered from the screen.
    $this->travel(2)->seconds();
    $draftId = raiseRequest($fixture);

    $this->actingAs($fixture['user'])
        ->get('/workspaces/procurement?section=orders&request='.$draftId)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('initialRequest', null));
});

it('does not offer or accept a second active purchase order for an approved request', function (): void {
    $fixture = requestHttpFixture();
    $supplier = seedSupplier($fixture['t'], [
        'code' => 'ACME-01',
        'name' => 'Acme Medical',
        'status' => Supplier::STATUS_ACTIVE,
    ]);
    $requisition = TenantContextScope::run($fixture['t'], null, fn () => PurchaseRequisition::factory()
        ->forTenant($fixture['t'])
        ->approved()
        ->create([
            'facility_id' => $fixture['f'],
            'created_by_user_id' => (string) $fixture['user']->id,
        ]));
    TenantContextScope::run($fixture['t'], null, fn () => PurchaseOrder::factory()
        ->forTenant($fixture['t'])
        ->issued()
        ->create([
            'facility_id' => $fixture['f'],
            'supplier_id' => (string) $supplier->id,
            'requisition_id' => (string) $requisition->id,
        ]));

    $this->actingAs($fixture['user'])
        ->get('/workspaces/procurement?section=requests')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('purchaseRequisitions.0.hasActivePurchaseOrder', true)
            ->where(
                'purchaseRequisitions.0.allowedActions',
                fn (Collection $actions): bool => ! $actions->contains('order'),
            ));

    $this->get('/workspaces/procurement?section=orders&request='.$requisition->id)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('initialRequest', null));

    $this->post('/workspaces/procurement/orders', [
        'supplier_id' => (string) $supplier->id,
        'requisition_id' => (string) $requisition->id,
        'priority' => 'ROUTINE',
        'lines' => [[
            'item_id' => $fixture['item'],
            'quantity' => '1',
            'unit_price' => '100',
        ]],
    ])->assertRedirect()->assertSessionHasErrors('order');

    expect(TenantContextScope::run($fixture['t'], null, fn () => PurchaseOrder::query()
        ->where('requisition_id', $requisition->id)
        ->count()))->toBe(1);
});

it('carries the request department into the order money limit', function (): void {
    $fixture = requestHttpFixture();
    $supplier = seedSupplier($fixture['t'], [
        'code' => 'ACME-01',
        'name' => 'Acme Medical',
        'status' => Supplier::STATUS_ACTIVE,
    ]);
    $departmentRef = seedRequestDepartment($fixture['t'], $fixture['f'], 'LAB');

    // The department allows one person up to 50,000; the facility default is
    // 1,000,000 for a HEALTH_CENTRE.
    configureApprovalLimit($fixture['t'], $fixture['f'], '50000', $departmentRef);

    $requisitionId = raiseRequest($fixture, $departmentRef, '1');
    $this->post('/workspaces/procurement/requests/'.$requisitionId.'/submit')->assertRedirect();

    $reviewer = User::factory()->forTenant($fixture['t'])->create();
    grantProcurementRole($fixture['t'], $fixture['f'], (string) $reviewer->id, [
        'procurement.access', 'procurement.requisitions.approve',
    ]);
    $this->actingAs($reviewer)
        ->post('/workspaces/procurement/requests/'.$requisitionId.'/approve')
        ->assertRedirect();

    $this->actingAs($fixture['user'])
        ->post('/workspaces/procurement/orders', [
            'supplier_id' => (string) $supplier->id,
            'requisition_id' => $requisitionId,
            'priority' => 'ROUTINE',
            'lines' => [[
                'item_id' => $fixture['item'],
                'quantity' => '1',
                'unit_price' => '100000',
            ]],
        ])
        ->assertRedirect()
        ->assertSessionHas('status');

    // A direct order of the same amount is judged at the facility limit…
    $this->travel(2)->seconds();
    $this->actingAs($fixture['user'])
        ->post('/workspaces/procurement/orders', [
            'supplier_id' => (string) $supplier->id,
            'priority' => 'ROUTINE',
            'lines' => [[
                'item_id' => $fixture['item'],
                'quantity' => '1',
                'unit_price' => '100000',
            ]],
        ])
        ->assertRedirect();
    $this->actingAs($fixture['user']);

    $this->get('/workspaces/procurement?section=orders')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('purchaseOrders', 2)
            // Newest first: the direct order, then the order from the request.
            ->where('purchaseOrders.0.requisitionId', null)
            ->where('purchaseOrders.0.sourcingMethod', 'DIRECT')
            ->where('purchaseOrders.0.selfApprovalPermitted', true)
            ->where('purchaseOrders.1.requisitionId', $requisitionId)
            ->where('purchaseOrders.1.sourcingMethod', 'REQUISITION')
            ->where('purchaseOrders.1.selfApprovalPermitted', false));
});

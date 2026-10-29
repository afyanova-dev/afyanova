<?php

declare(strict_types=1);

use App\Domains\Procurement\Application\Command\ApprovePurchaseOrder;
use App\Domains\Procurement\Application\Command\ApprovePurchaseRequisition;
use App\Domains\Procurement\Application\Command\CreatePurchaseOrder;
use App\Domains\Procurement\Application\Command\CreatePurchaseRequisition;
use App\Domains\Procurement\Application\Command\IssuePurchaseOrder;
use App\Domains\Procurement\Application\Command\SubmitPurchaseOrder;
use App\Domains\Procurement\Application\Command\SubmitPurchaseRequisition;
use App\Domains\Procurement\Domain\Exception\SelfApprovalNotPermitted;
use App\Domains\Procurement\Domain\Exception\SelfIssuanceNotPermitted;
use App\Domains\Procurement\Domain\Model\ApprovalPolicy;
use App\Domains\Procurement\Domain\Model\PurchaseOrder;
use App\Domains\Procurement\Domain\Model\Supplier;
use App\Models\User;
use App\Shared\Tenancy\Tenancy;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * Single-actor approval limits (Shape B; 19-procurement-domain.md §24 / §228).
 *
 * The limit is operational policy, not deployment config: it resolves per
 * department, then per facility, then by facility-type default, then off. The
 * test facility is a HEALTH_CENTRE, whose default is TZS 1,000,000
 * (`config/procurement.php`).
 *
 * @return array{t: string, f: string, user: User, actor: string, supplier: string}
 */
function approvalFixture(): array
{
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $actor] = procurementFixture();
    $t = (string) $tenant->id;

    return [
        't' => $t,
        'f' => (string) $facility->id,
        'user' => $actor,
        'actor' => (string) $actor->id,
        'supplier' => (string) seedSupplier($t, ['status' => Supplier::STATUS_ACTIVE])->id,
    ];
}

/**
 * A submitted purchase order for one service line at `$unitPrice`, optionally
 * raised against an approved requisition (which is what supplies a department).
 *
 * @param  array{t: string, f: string, user: User, actor: string, supplier: string}  $fixture
 */
function approvalDraft(array $fixture, string $unitPrice, ?string $requisitionId = null): string
{
    $poId = (string) Str::uuid7();

    dispatchProc($fixture['t'], $fixture['f'], new CreatePurchaseOrder(
        purchaseOrderId: $poId,
        actorId: $fixture['actor'],
        facilityId: $fixture['f'],
        supplierId: $fixture['supplier'],
        lines: [[
            'lineType' => 'SERVICE',
            'description' => 'Reagents',
            'quantity' => '1',
            'uom' => 'EACH',
            'unitPrice' => $unitPrice,
        ]],
        requisitionId: $requisitionId,
    ));

    dispatchProc($fixture['t'], $fixture['f'], new SubmitPurchaseOrder($poId, $fixture['actor']));

    return $poId;
}

/**
 * An APPROVED requisition raised by the fixture actor for one department, so a
 * purchase order built from it inherits that department.
 *
 * @param  array{t: string, f: string, user: User, actor: string, supplier: string}  $fixture
 */
function approvedRequisitionIn(array $fixture, string $departmentRef): string
{
    $requisitionId = (string) Str::uuid7();

    dispatchProc($fixture['t'], $fixture['f'], new CreatePurchaseRequisition(
        requisitionId: $requisitionId,
        actorId: $fixture['actor'],
        facilityId: $fixture['f'],
        lines: [['lineType' => 'SERVICE', 'description' => 'Reagents', 'quantity' => '1', 'uom' => 'EACH']],
        requestingDepartmentRef: $departmentRef,
    ));

    dispatchProc($fixture['t'], $fixture['f'], new SubmitPurchaseRequisition($requisitionId, $fixture['actor']));

    $approver = User::factory()->forTenant($fixture['t'])->create();
    grantProcurementRole($fixture['t'], $fixture['f'], (string) $approver->id, [
        'procurement.access', 'procurement.requisitions.approve',
    ]);

    test()->actingAs($approver);
    dispatchProc($fixture['t'], $fixture['f'], new ApprovePurchaseRequisition($requisitionId, (string) $approver->id));
    test()->actingAs($fixture['user']);

    return $requisitionId;
}

/**
 * @param  array{t: string, f: string, user: User, actor: string, supplier: string}  $fixture
 */
function approveAndIssueAsCreator(array $fixture, string $poId): void
{
    dispatchProc($fixture['t'], $fixture['f'], new ApprovePurchaseOrder($poId, $fixture['actor']));
    dispatchProc($fixture['t'], $fixture['f'], new IssuePurchaseOrder($poId, $fixture['actor']));
}

/**
 * @param  array{t: string, f: string, user: User, actor: string, supplier: string}  $fixture
 */
function poStatus(array $fixture, string $poId): string
{
    return (string) TenantContextScope::run(
        $fixture['t'],
        null,
        fn () => PurchaseOrder::query()->find($poId)->status,
    );
}

test('with no policy row the facility-type default applies — a health centre may self-approve up to TZS 1,000,000', function () {
    $fixture = approvalFixture();

    $atLimit = approvalDraft($fixture, '1000000.00');
    approveAndIssueAsCreator($fixture, $atLimit);

    expect(poStatus($fixture, $atLimit))->toBe(PurchaseOrder::STATUS_ISSUED);

    $aboveLimit = approvalDraft($fixture, '1000000.01');

    expect(fn () => dispatchProc($fixture['t'], $fixture['f'], new ApprovePurchaseOrder($aboveLimit, $fixture['actor'])))
        ->toThrow(SelfApprovalNotPermitted::class);
});

test('a facility row overrides the facility-type default', function () {
    $fixture = approvalFixture();
    configureApprovalLimit($fixture['t'], $fixture['f'], '50000');

    $atLimit = approvalDraft($fixture, '50000.00');
    approveAndIssueAsCreator($fixture, $atLimit);

    expect(poStatus($fixture, $atLimit))->toBe(PurchaseOrder::STATUS_ISSUED);

    $aboveLimit = approvalDraft($fixture, '50000.01');

    expect(fn () => dispatchProc($fixture['t'], $fixture['f'], new ApprovePurchaseOrder($aboveLimit, $fixture['actor'])))
        ->toThrow(SelfApprovalNotPermitted::class);
});

test('a department row overrides the facility row for orders raised from that department', function () {
    $fixture = approvalFixture();
    $laboratory = (string) Str::uuid7();
    TenantContextScope::run($fixture['t'], null, function () use ($fixture, $laboratory): void {
        DB::table('org_departments')->insert([
            'id' => $laboratory,
            'tenant_id' => $fixture['t'],
            'facility_id' => $fixture['f'],
            'name' => 'Laboratory',
            'code' => 'LAB',
            'status' => 'ACTIVE',
            'version' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    configureApprovalLimit($fixture['t'], $fixture['f'], '500000');
    configureApprovalLimit($fixture['t'], $fixture['f'], '20000', $laboratory);

    $atLimit = approvalDraft($fixture, '20000.00', approvedRequisitionIn($fixture, $laboratory));
    approveAndIssueAsCreator($fixture, $atLimit);

    expect(poStatus($fixture, $atLimit))->toBe(PurchaseOrder::STATUS_ISSUED);

    $aboveLimit = approvalDraft($fixture, '20000.01', approvedRequisitionIn($fixture, $laboratory));

    expect(fn () => dispatchProc($fixture['t'], $fixture['f'], new ApprovePurchaseOrder($aboveLimit, $fixture['actor'])))
        ->toThrow(SelfApprovalNotPermitted::class);
});

test('a direct order has no department, so the wider facility row applies', function () {
    $fixture = approvalFixture();

    configureApprovalLimit($fixture['t'], $fixture['f'], '500000');
    configureApprovalLimit($fixture['t'], $fixture['f'], '20000', (string) Str::uuid7());

    $direct = approvalDraft($fixture, '400000.00');
    approveAndIssueAsCreator($fixture, $direct);

    expect(poStatus($fixture, $direct))->toBe(PurchaseOrder::STATUS_ISSUED);
});

test('a limit of 0 pins the scope off even where the facility-type default is positive', function () {
    $fixture = approvalFixture();
    configureApprovalLimit($fixture['t'], $fixture['f'], '0');

    $poId = approvalDraft($fixture, '100.00');

    expect(fn () => dispatchProc($fixture['t'], $fixture['f'], new ApprovePurchaseOrder($poId, $fixture['actor'])))
        ->toThrow(SelfApprovalNotPermitted::class);
});

test('a facility type with no default is off', function () {
    config(['procurement.approval.facility_type_defaults' => []]);

    $fixture = approvalFixture();
    $poId = approvalDraft($fixture, '100.00');

    expect(fn () => dispatchProc($fixture['t'], $fixture['f'], new ApprovePurchaseOrder($poId, $fixture['actor'])))
        ->toThrow(SelfApprovalNotPermitted::class);
});

test('procurement:configure-approval-limit writes each scope, audits it, and clears it', function () {
    $fixture = approvalFixture();
    $laboratory = (string) Str::uuid7();

    test()->artisan('procurement:configure-approval-limit', [
        '--tenant' => $fixture['t'], '--facility' => $fixture['f'], '--limit' => '750000',
    ])->assertExitCode(0);

    test()->artisan('procurement:configure-approval-limit', [
        '--tenant' => $fixture['t'], '--facility' => $fixture['f'],
        '--department' => $laboratory, '--limit' => '120000',
    ])->assertExitCode(0);

    // Re-running a scope UPDATES its row rather than adding a second one.
    test()->artisan('procurement:configure-approval-limit', [
        '--tenant' => $fixture['t'], '--facility' => $fixture['f'], '--limit' => '800000',
    ])->assertExitCode(0);

    [$facilityRow, $departmentRow] = TenantContextScope::run($fixture['t'], null, fn (): array => [
        ApprovalPolicy::query()->whereNull('department_ref')->first(),
        ApprovalPolicy::query()->whereNotNull('department_ref')->first(),
    ]);

    expect((string) $facilityRow->self_approval_limit)->toBe('800000.00')
        ->and((int) $facilityRow->version)->toBe(2)
        ->and((string) $departmentRow->self_approval_limit)->toBe('120000.00')
        ->and((string) $departmentRow->department_ref)->toBe($laboratory);

    $audits = Tenancy::run($fixture['t'], fn () => DB::table('shared_audit_log')
        ->where('action', 'procurement.approval_limit_configured')->count());
    expect($audits)->toBe(3);

    test()->artisan('procurement:configure-approval-limit', [
        '--tenant' => $fixture['t'], '--facility' => $fixture['f'],
        '--department' => $laboratory, '--clear' => true,
    ])->assertExitCode(0);

    expect(TenantContextScope::run($fixture['t'], null, fn () => ApprovalPolicy::query()->count()))->toBe(1);

    // The effective facility-wide limit is what the operator sees.
    test()->artisan('procurement:configure-approval-limit', [
        '--tenant' => $fixture['t'], '--facility' => $fixture['f'], '--list' => true,
    ])->expectsOutputToContain('Effective facility-wide limit: 800000.00 TZS')
        ->assertExitCode(0);
});

test('procurement:configure-approval-limit rejects a non-uuid department and a missing limit', function () {
    $fixture = approvalFixture();

    test()->artisan('procurement:configure-approval-limit', [
        '--tenant' => $fixture['t'], '--facility' => $fixture['f'], '--department' => 'lab', '--limit' => '1000',
    ])->assertExitCode(1);

    test()->artisan('procurement:configure-approval-limit', [
        '--tenant' => $fixture['t'], '--facility' => $fixture['f'],
    ])->assertExitCode(1);

    expect(TenantContextScope::run($fixture['t'], null, fn () => ApprovalPolicy::query()->count()))->toBe(0);
});

test('the issuing guard follows the same effective limit', function () {
    $fixture = approvalFixture();
    configureApprovalLimit($fixture['t'], $fixture['f'], '50000');

    $approver = User::factory()->forTenant($fixture['t'])->create();
    grantProcurementRole($fixture['t'], $fixture['f'], (string) $approver->id, [
        'procurement.access', 'procurement.orders.approve', 'procurement.orders.issue',
    ]);

    // Under the limit the approver may also issue.
    $small = approvalDraft($fixture, '50000.00');
    // Above it, the same actor is blocked at issue.
    $big = approvalDraft($fixture, '900000.00');

    test()->actingAs($approver);
    dispatchProc($fixture['t'], $fixture['f'], new ApprovePurchaseOrder($small, (string) $approver->id));
    dispatchProc($fixture['t'], $fixture['f'], new IssuePurchaseOrder($small, (string) $approver->id));

    expect(poStatus($fixture, $small))->toBe(PurchaseOrder::STATUS_ISSUED);

    test()->actingAs($approver);
    dispatchProc($fixture['t'], $fixture['f'], new ApprovePurchaseOrder($big, (string) $approver->id));

    expect(fn () => dispatchProc($fixture['t'], $fixture['f'], new IssuePurchaseOrder($big, (string) $approver->id)))
        ->toThrow(SelfIssuanceNotPermitted::class);
});

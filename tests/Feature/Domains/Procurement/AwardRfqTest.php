<?php

declare(strict_types=1);

use App\Domains\Procurement\Application\Command\AwardRfq;
use App\Domains\Procurement\Application\Command\CloseRfq;
use App\Domains\Procurement\Application\Command\CreatePurchaseOrder;
use App\Domains\Procurement\Application\Command\IssueRfq;
use App\Domains\Procurement\Application\Query\GetRfq;
use App\Domains\Procurement\Domain\Exception\RfqAwardInvalid;
use App\Domains\Procurement\Domain\Exception\RfqNotAwardable;
use App\Domains\Procurement\Domain\Exception\SelfAwardNotPermitted;
use App\Domains\Procurement\Domain\Model\PoLine;
use App\Domains\Procurement\Domain\Model\PurchaseOrder;
use App\Domains\Procurement\Domain\Model\Quotation;
use App\Domains\Procurement\Domain\Model\Rfq;
use App\Domains\Procurement\Domain\Model\RfqAward;
use App\Models\User;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * A CLOSED RFQ with one line (qty 10) and two SUBMITTED quotations:
 *   Q1 (supplier s1) unit 1000, offered 10
 *   Q2 (supplier s2) unit 900,  offered 6
 *
 * @return array{t: string, f: string, creator: string, decider: User, rfqId: string, lineId: string, q1: string, q2: string, s1: string, s2: string}
 */
function closedRfq(): array
{
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = procurementFixture([
        'procurement.access', 'procurement.rfqs.manage', 'procurement.quotations.record', 'procurement.quotations.view',
        'procurement.orders.create', 'inventory.access',
    ]);
    $t = (string) $tenant->id;
    $f = (string) $facility->id;
    $s1 = (string) seedSupplier($t, ['code' => 'S1'])->id;
    $s2 = (string) seedSupplier($t, ['code' => 'S2'])->id;

    [$rfqId, $lineIds] = createRfq($t, $f, (string) $user->id, [$s1, $s2], [
        ['lineType' => 'SERVICE', 'description' => 'Widgets', 'quantity' => '10', 'uom' => 'EACH'],
    ]);
    dispatchProc($t, $f, new IssueRfq($rfqId, (string) $user->id));
    $q1 = recordQuotation($t, $f, (string) $user->id, $rfqId, $s1, $lineIds, '1000', '10');
    $q2 = recordQuotation($t, $f, (string) $user->id, $rfqId, $s2, $lineIds, '900', '6');
    dispatchProc($t, $f, new CloseRfq($rfqId, (string) $user->id));

    $decider = User::factory()->forTenant($t)->create();
    grantProcurementRole($t, $f, (string) $decider->id, ['procurement.access', 'procurement.awards.decide', 'procurement.orders.create']);

    return ['t' => $t, 'f' => $f, 'creator' => (string) $user->id, 'decider' => $decider,
        'rfqId' => $rfqId, 'lineId' => $lineIds[0], 'q1' => $q1, 'q2' => $q2, 's1' => $s1, 's2' => $s2];
}

test('split + partial award: 6 to Q2 + 3 to Q1 (partial — 1 outstanding); RFQ → AWARDED; quotations marked; §35 no auto-select', function () {
    ['t' => $t, 'f' => $f, 'decider' => $decider, 'rfqId' => $rfqId, 'lineId' => $lineId, 'q1' => $q1, 'q2' => $q2] = closedRfq();

    test()->actingAs($decider);
    dispatchProc($t, $f, new AwardRfq($rfqId, (string) $decider->id, [
        ['rfqLineId' => $lineId, 'quotationId' => $q2, 'awardedQty' => '6', 'reason' => 'Best price'],
        ['rfqLineId' => $lineId, 'quotationId' => $q1, 'awardedQty' => '3', 'reason' => 'Remaining volume, faster'],
    ]));

    $rfq = TenantContextScope::run($t, null, fn () => Rfq::query()->find($rfqId));
    expect($rfq->status)->toBe(Rfq::STATUS_AWARDED)->and($rfq->awarded_by_user_id)->toBe((string) $decider->id);

    expect(TenantContextScope::run($t, null, fn () => RfqAward::query()->where('rfq_id', $rfqId)->count()))->toBe(2);
    expect(TenantContextScope::run($t, null, fn () => Quotation::query()->find($q1)->status))->toBe(Quotation::STATUS_AWARDED);
    expect(TenantContextScope::run($t, null, fn () => Quotation::query()->find($q2)->status))->toBe(Quotation::STATUS_AWARDED);

    $summary = queryProc($t, $f, new GetRfq($rfqId));
    expect($summary->lines[0]->awardedQty)->toBe('9.0000')
        ->and($summary->lines[0]->outstandingQty)->toBe('1.0000') // §39 partial — surfaced, not auto-re-sourced
        ->and($summary->awards)->toHaveCount(2);
});

test('after award, GetRfq on procurement.access shows the award OUTCOME but NO quotation price (§101 / ADR-173 D-F)', function () {
    ['t' => $t, 'f' => $f, 'decider' => $decider, 'rfqId' => $rfqId, 'lineId' => $lineId, 'q1' => $q1, 'q2' => $q2, 's2' => $s2] = closedRfq();

    test()->actingAs($decider); // holds procurement.access + procurement.awards.decide — NOT procurement.quotations.view
    dispatchProc($t, $f, new AwardRfq($rfqId, (string) $decider->id, [
        ['rfqLineId' => $lineId, 'quotationId' => $q2, 'awardedQty' => '6', 'reason' => 'Best price'],
        ['rfqLineId' => $lineId, 'quotationId' => $q1, 'awardedQty' => '4', 'reason' => 'Remaining volume'],
    ]));

    $summary = queryProc($t, $f, new GetRfq($rfqId));

    // the OUTCOME is visible — who won which line, the qty, the reason
    expect($summary->awards)->toHaveCount(2)
        ->and($summary->awards[0]->supplierId)->toBe($s2)
        ->and($summary->awards[0]->awardedQty)->toBe('6.0000')
        ->and($summary->awards[0]->reason)->toBe('Best price');

    // …but NOT the winning (or any) quotation price — the field is gone from the DTO
    expect(property_exists($summary->awards[0], 'unitPrice'))->toBeFalse();
    expect(json_encode($summary))->not->toContain('900.00')->not->toContain('1000.00');
});

test('a supplier with no awarded rows is marked NOT_AWARDED', function () {
    ['t' => $t, 'f' => $f, 'decider' => $decider, 'rfqId' => $rfqId, 'lineId' => $lineId, 'q1' => $q1, 'q2' => $q2] = closedRfq();
    test()->actingAs($decider);
    dispatchProc($t, $f, new AwardRfq($rfqId, (string) $decider->id, [
        ['rfqLineId' => $lineId, 'quotationId' => $q1, 'awardedQty' => '10', 'reason' => 'Full award to the incumbent'],
    ]));

    expect(TenantContextScope::run($t, null, fn () => Quotation::query()->find($q1)->status))->toBe(Quotation::STATUS_AWARDED);
    expect(TenantContextScope::run($t, null, fn () => Quotation::query()->find($q2)->status))->toBe(Quotation::STATUS_NOT_AWARDED);
});

test('the RFQ creator cannot award it (§24 / §228 SoD)', function () {
    ['t' => $t, 'f' => $f, 'creator' => $creator, 'rfqId' => $rfqId, 'lineId' => $lineId, 'q1' => $q1] = closedRfq();
    // grant the creator the award key so the SoD guard, not the policy, is what blocks
    grantProcurementRole($t, $f, $creator, ['procurement.awards.decide']);
    test()->actingAs(User::query()->find($creator));

    expect(fn () => dispatchProc($t, $f, new AwardRfq($rfqId, $creator, [
        ['rfqLineId' => $lineId, 'quotationId' => $q1, 'awardedQty' => '10', 'reason' => 'x'],
    ])))->toThrow(SelfAwardNotPermitted::class);
});

test('over-award (more than the line requested) is rejected; awarding an un-CLOSED RFQ is rejected', function () {
    ['t' => $t, 'f' => $f, 'decider' => $decider, 'rfqId' => $rfqId, 'lineId' => $lineId, 'q1' => $q1] = closedRfq();
    test()->actingAs($decider);

    expect(fn () => dispatchProc($t, $f, new AwardRfq($rfqId, (string) $decider->id, [
        ['rfqLineId' => $lineId, 'quotationId' => $q1, 'awardedQty' => '11', 'reason' => 'x'],
    ])))->toThrow(RfqAwardInvalid::class);

    // fresh un-closed RFQ
    ['tenant' => $tn, 'facility' => $fac, 'user' => $u] = procurementFixture(['procurement.access', 'procurement.rfqs.manage', 'procurement.awards.decide']);
    [$openRfq, $openLines] = createRfq((string) $tn->id, (string) $fac->id, (string) $u->id, [(string) seedSupplier((string) $tn->id)->id]);
    $other = User::factory()->forTenant((string) $tn->id)->create();
    grantProcurementRole((string) $tn->id, (string) $fac->id, (string) $other->id, ['procurement.awards.decide']);
    test()->actingAs($other);
    expect(fn () => dispatchProc((string) $tn->id, (string) $fac->id, new AwardRfq($openRfq, (string) $other->id, [
        ['rfqLineId' => $openLines[0], 'quotationId' => (string) Str::uuid7(), 'awardedQty' => '1', 'reason' => 'x'],
    ])))->toThrow(RfqNotAwardable::class);
});

test('CreatePurchaseOrder(rfqId) seeds PO lines from the award for one supplier; sets sourcing_method = RFQ', function () {
    ['t' => $t, 'f' => $f, 'decider' => $decider, 'rfqId' => $rfqId, 'lineId' => $lineId, 'q1' => $q1, 'q2' => $q2, 's2' => $s2] = closedRfq();
    test()->actingAs($decider);
    dispatchProc($t, $f, new AwardRfq($rfqId, (string) $decider->id, [
        ['rfqLineId' => $lineId, 'quotationId' => $q2, 'awardedQty' => '6', 'reason' => 'Best price'],
        ['rfqLineId' => $lineId, 'quotationId' => $q1, 'awardedQty' => '4', 'reason' => 'Remaining'],
    ]));

    $poId = (string) Str::uuid7();
    dispatchProc($t, $f, new CreatePurchaseOrder(
        purchaseOrderId: $poId, actorId: (string) $decider->id, facilityId: $f, supplierId: $s2,
        lines: [], rfqId: $rfqId,
    ));

    $po = TenantContextScope::run($t, null, fn () => PurchaseOrder::query()->find($poId));
    expect($po->sourcing_method)->toBe(PurchaseOrder::SOURCING_METHOD_RFQ)
        ->and($po->sourcing_rfq_id)->toBe($rfqId);

    $lines = TenantContextScope::run($t, null, fn () => PoLine::query()->where('po_id', $poId)->get());
    expect($lines)->toHaveCount(1)
        ->and((string) $lines[0]->ordered_qty)->toBe('6.0000')   // s2's awarded qty
        ->and((string) $lines[0]->unit_price)->toBe('900.00');   // s2's quoted price
});

test('CreatePurchaseOrder(rfqId) for a supplier with no award rows is rejected', function () {
    ['t' => $t, 'f' => $f, 'decider' => $decider, 'rfqId' => $rfqId, 'lineId' => $lineId, 'q1' => $q1, 's2' => $s2] = closedRfq();
    test()->actingAs($decider);
    dispatchProc($t, $f, new AwardRfq($rfqId, (string) $decider->id, [
        ['rfqLineId' => $lineId, 'quotationId' => $q1, 'awardedQty' => '10', 'reason' => 'All to s1'],
    ]));

    expect(fn () => dispatchProc($t, $f, new CreatePurchaseOrder(
        purchaseOrderId: (string) Str::uuid7(), actorId: (string) $decider->id, facilityId: $f, supplierId: $s2,
        lines: [], rfqId: $rfqId,
    )))->toThrow(RfqAwardInvalid::class);
});

<?php

declare(strict_types=1);

use App\Domains\Procurement\Application\Command\AwardRfq;
use App\Domains\Procurement\Application\Command\EvaluateQuotation;
use App\Domains\Procurement\Domain\Exception\RfqEvaluationIncomplete;
use App\Domains\Procurement\Domain\Model\Rfq;
use App\Models\User;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * ADR-174 D-F — the SOFT award gate. A CLOSED RFQ with one line (qty 10) and two
 * SUBMITTED quotations; a decider distinct from the RFQ creator.
 *
 * @return array{t: string, f: string, decider: User, rfqId: string, lineId: string, q1: string, q2: string}
 */
function closedRfqForGate(): array
{
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $creator] = procurementFixture([
        'procurement.access', 'procurement.rfqs.manage', 'procurement.quotations.record',
        'procurement.quotations.view', 'procurement.quotations.evaluate',
    ]);
    $t = (string) $tenant->id;
    $f = (string) $facility->id;
    $s1 = (string) seedSupplier($t, ['code' => 'S1'])->id;
    $s2 = (string) seedSupplier($t, ['code' => 'S2'])->id;

    [$rfqId, $lineIds, $quoteIds] = closedRfqWithQuotes($t, $f, (string) $creator->id, [$s1, $s2], [
        ['lineType' => 'SERVICE', 'description' => 'Widgets', 'quantity' => '10', 'uom' => 'EACH'],
    ], ['1000', '900']);

    $decider = User::factory()->forTenant($t)->create();
    grantProcurementRole($t, $f, (string) $decider->id, [
        'procurement.access', 'procurement.awards.decide', 'procurement.quotations.evaluate',
    ]);

    return ['t' => $t, 'f' => $f, 'decider' => $decider, 'rfqId' => $rfqId,
        'lineId' => $lineIds[0], 'q1' => $quoteIds[0], 'q2' => $quoteIds[1]];
}

test('with ZERO submitted evaluations, AwardRfq proceeds unchanged (Proc.2a behaviour)', function () {
    ['t' => $t, 'f' => $f, 'decider' => $decider, 'rfqId' => $rfqId, 'lineId' => $lineId, 'q1' => $q1] = closedRfqForGate();

    test()->actingAs($decider);
    dispatchProc($t, $f, new AwardRfq($rfqId, (string) $decider->id, [
        ['rfqLineId' => $lineId, 'quotationId' => $q1, 'awardedQty' => '10', 'reason' => 'No formal evaluation needed'],
    ]));

    expect(TenantContextScope::run($t, null, fn () => Rfq::query()->find($rfqId)->status))->toBe(Rfq::STATUS_AWARDED);
});

test('once an evaluation is SUBMITTED, AwardRfq is refused until EVERY submitted quotation is evaluated', function () {
    ['t' => $t, 'f' => $f, 'decider' => $decider, 'rfqId' => $rfqId, 'lineId' => $lineId, 'q1' => $q1, 'q2' => $q2] = closedRfqForGate();

    test()->actingAs($decider);
    // only q1 is evaluated
    evaluateQuotation($t, $f, (string) $decider->id, $q1, [80, 80, 80, 80]);

    expect(fn () => dispatchProc($t, $f, new AwardRfq($rfqId, (string) $decider->id, [
        ['rfqLineId' => $lineId, 'quotationId' => $q1, 'awardedQty' => '10', 'reason' => 'x'],
    ])))->toThrow(RfqEvaluationIncomplete::class);

    // evaluate q2 as well → the gate clears
    evaluateQuotation($t, $f, (string) $decider->id, $q2, [70, 70, 70, 70]);

    dispatchProc($t, $f, new AwardRfq($rfqId, (string) $decider->id, [
        ['rfqLineId' => $lineId, 'quotationId' => $q1, 'awardedQty' => '10', 'reason' => 'Best overall fit'],
    ]));
    expect(TenantContextScope::run($t, null, fn () => Rfq::query()->find($rfqId)->status))->toBe(Rfq::STATUS_AWARDED);
});

test('a DRAFT evaluation neither triggers nor blocks the gate (no deadlock)', function () {
    ['t' => $t, 'f' => $f, 'decider' => $decider, 'rfqId' => $rfqId, 'lineId' => $lineId, 'q1' => $q1] = closedRfqForGate();

    test()->actingAs($decider);
    // a stray DRAFT — never submitted
    dispatchProc($t, $f, new EvaluateQuotation(
        evaluationId: (string) Str::uuid7(), actorId: (string) $decider->id, quotationId: $q1, commercialScore: 50,
    ));

    dispatchProc($t, $f, new AwardRfq($rfqId, (string) $decider->id, [
        ['rfqLineId' => $lineId, 'quotationId' => $q1, 'awardedQty' => '10', 'reason' => 'Draft evaluation abandoned'],
    ]));

    expect(TenantContextScope::run($t, null, fn () => Rfq::query()->find($rfqId)->status))->toBe(Rfq::STATUS_AWARDED);
});

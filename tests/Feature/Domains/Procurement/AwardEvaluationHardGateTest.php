<?php

declare(strict_types=1);

use App\Domains\Procurement\Application\Command\AwardRfq;
use App\Domains\Procurement\Application\Command\IssueRfq;
use App\Domains\Procurement\Application\Command\RequireRfqEvaluation;
use App\Domains\Procurement\Application\Query\GetRfq;
use App\Domains\Procurement\Domain\Exception\IllegalRfqTransition;
use App\Domains\Procurement\Domain\Exception\RfqEvaluationIncomplete;
use App\Domains\Procurement\Domain\Model\Rfq;
use App\Models\User;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * ADR-175 D-C / D-D — the HARD award gate. A CLOSED RFQ, one line qty 10, two
 * SUBMITTED quotations at unit 1000 (line total 10 000 each), a distinct decider.
 *
 * @return array{t: string, f: string, decider: User, rfqId: string, lineId: string, q1: string, q2: string}
 */
function closedRfqForHardGate(array $unitPrices = ['1000', '1000']): array
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
    ], $unitPrices);

    $decider = User::factory()->forTenant($t)->create();
    grantProcurementRole($t, $f, (string) $decider->id, [
        'procurement.access', 'procurement.rfqs.manage', 'procurement.quotations.record',
        'procurement.awards.decide', 'procurement.quotations.evaluate',
    ]);

    return ['t' => $t, 'f' => $f, 'decider' => $decider, 'rfqId' => $rfqId,
        'lineId' => $lineIds[0], 'q1' => $quoteIds[0], 'q2' => $quoteIds[1]];
}

test('RequireRfqEvaluation HARD-gates the award even with ZERO evaluations', function () {
    ['t' => $t, 'f' => $f, 'decider' => $decider, 'rfqId' => $rfqId, 'lineId' => $lineId, 'q1' => $q1, 'q2' => $q2] = closedRfqForHardGate();

    dispatchProc($t, $f, new RequireRfqEvaluation($rfqId, (string) $decider->id));
    expect(TenantContextScope::run($t, null, fn () => Rfq::query()->find($rfqId)->requires_evaluation))->toBeTrue();
    expect(queryProc($t, $f, new GetRfq($rfqId))->requiresEvaluation)->toBeTrue();

    test()->actingAs($decider);
    expect(fn () => dispatchProc($t, $f, new AwardRfq($rfqId, (string) $decider->id, [
        ['rfqLineId' => $lineId, 'quotationId' => $q1, 'awardedQty' => '10', 'reason' => 'x'],
    ])))->toThrow(RfqEvaluationIncomplete::class);

    // evaluate BOTH → the gate clears
    evaluateQuotation($t, $f, (string) $decider->id, $q1, [80, 80, 80, 80]);
    evaluateQuotation($t, $f, (string) $decider->id, $q2, [70, 70, 70, 70]);

    dispatchProc($t, $f, new AwardRfq($rfqId, (string) $decider->id, [
        ['rfqLineId' => $lineId, 'quotationId' => $q1, 'awardedQty' => '10', 'reason' => 'Evaluated'],
    ]));
    expect(TenantContextScope::run($t, null, fn () => Rfq::query()->find($rfqId)->status))->toBe(Rfq::STATUS_AWARDED);
});

test('the value threshold HARD-gates a high-total RFQ; a below-threshold RFQ stays soft', function () {
    // quotation line total = 1000 * 10 = 10 000 each.
    ['t' => $t, 'f' => $f, 'decider' => $decider, 'rfqId' => $rfqId, 'lineId' => $lineId, 'q1' => $q1, 'q2' => $q2] = closedRfqForHardGate();

    test()->artisan('procurement:configure-evaluation', ['--tenant' => $t, '--evaluation-required-above' => '9000'])->assertExitCode(0);

    test()->actingAs($decider);
    expect(fn () => dispatchProc($t, $f, new AwardRfq($rfqId, (string) $decider->id, [
        ['rfqLineId' => $lineId, 'quotationId' => $q1, 'awardedQty' => '10', 'reason' => 'x'],
    ])))->toThrow(RfqEvaluationIncomplete::class);

    // A cheaper RFQ (line total 500 each) under the same threshold → soft (awards freely).
    $s3 = (string) seedSupplier($t, ['code' => 'S3'])->id;
    [$cheapRfq, $cheapLines, $cheapQuotes] = closedRfqWithQuotes($t, $f, (string) $decider->id, [$s3], [
        ['lineType' => 'SERVICE', 'description' => 'Cheap', 'quantity' => '10', 'uom' => 'EACH'],
    ], ['50']);
    $other = User::factory()->forTenant($t)->create();
    grantProcurementRole($t, $f, (string) $other->id, ['procurement.access', 'procurement.awards.decide']);
    test()->actingAs($other);
    dispatchProc($t, $f, new AwardRfq($cheapRfq, (string) $other->id, [
        ['rfqLineId' => $cheapLines[0], 'quotationId' => $cheapQuotes[0], 'awardedQty' => '10', 'reason' => 'Under threshold'],
    ]));
    expect(TenantContextScope::run($t, null, fn () => Rfq::query()->find($cheapRfq)->status))->toBe(Rfq::STATUS_AWARDED);
});

test('RequireRfqEvaluation is legal from ISSUED and CLOSED, not DRAFT or AWARDED', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = procurementFixture([
        'procurement.access', 'procurement.rfqs.manage',
    ]);
    $t = (string) $tenant->id;
    $f = (string) $facility->id;
    $s1 = (string) seedSupplier($t, ['code' => 'S1'])->id;
    [$rfqId] = createRfq($t, $f, (string) $user->id, [$s1]);

    // DRAFT → rejected
    expect(fn () => dispatchProc($t, $f, new RequireRfqEvaluation($rfqId, (string) $user->id)))
        ->toThrow(IllegalRfqTransition::class);

    dispatchProc($t, $f, new IssueRfq($rfqId, (string) $user->id));
    dispatchProc($t, $f, new RequireRfqEvaluation($rfqId, (string) $user->id));   // ISSUED → ok
    dispatchProc($t, $f, new RequireRfqEvaluation($rfqId, (string) $user->id));   // idempotent
    expect(TenantContextScope::run($t, null, fn () => Rfq::query()->find($rfqId)->requires_evaluation))->toBeTrue();
});

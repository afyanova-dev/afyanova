<?php

declare(strict_types=1);

use App\Domains\Procurement\Application\Command\EvaluateQuotation;
use App\Domains\Procurement\Application\Command\ReviseEvaluation;
use App\Domains\Procurement\Application\Command\SubmitEvaluation;
use App\Domains\Procurement\Application\Query\GetQuotationEvaluations;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * §207 — an evaluator with an unsubmitted DRAFT for a quotation cannot see
 * peers' SUBMITTED scores for that quotation until they submit their own (or
 * the RFQ is AWARDED). Always on in Proc.2b (ADR-174 D-E).
 */
test('a mid-evaluation evaluator is gated from peers\' scores until they submit', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $creator] = procurementFixture([
        'procurement.access', 'procurement.rfqs.manage', 'procurement.quotations.record',
        'procurement.quotations.view', 'procurement.quotations.evaluate',
    ]);
    $t = (string) $tenant->id;
    $f = (string) $facility->id;
    $s1 = (string) seedSupplier($t, ['code' => 'S1'])->id;

    [$rfqId, , $quoteIds] = closedRfqWithQuotes($t, $f, (string) $creator->id, [$s1], [
        ['lineType' => 'SERVICE', 'description' => 'Widgets', 'quantity' => '10', 'uom' => 'EACH'],
    ]);
    $quoteId = $quoteIds[0];

    $alice = User::factory()->forTenant($t)->create();
    $bob = User::factory()->forTenant($t)->create();
    foreach ([$alice, $bob] as $u) {
        grantProcurementRole($t, $f, (string) $u->id, ['procurement.access', 'procurement.quotations.view', 'procurement.quotations.evaluate']);
    }

    // Alice submits a full evaluation.
    test()->actingAs($alice);
    evaluateQuotation($t, $f, (string) $alice->id, $quoteId, [90, 80, 70, 60]); // overall 80 (79.5 → 80)

    // Bob opens a DRAFT but has not submitted.
    test()->actingAs($bob);
    $bobEval = (string) Str::uuid7();
    dispatchProc($t, $f, new EvaluateQuotation(
        evaluationId: $bobEval, actorId: (string) $bob->id, quotationId: $quoteId, commercialScore: 40,
    ));

    // Bob's view is GATED — Alice's scores are hidden, only the count + Bob's own row.
    $gated = queryProc($t, $f, new GetQuotationEvaluations($rfqId));
    expect($gated->groups[0]->viewerGated)->toBeTrue()
        ->and($gated->groups[0]->submittedCount)->toBe(1)
        ->and($gated->groups[0]->averageOverall)->toBeNull()
        ->and($gated->groups[0]->evaluations)->toHaveCount(1)
        ->and($gated->groups[0]->evaluations[0]->evaluatorUserId)->toBe((string) $bob->id);
    expect(json_encode($gated))->not->toContain('"overallScore":80');

    // Bob completes + submits → now he sees Alice's SUBMITTED evaluation.
    dispatchProc($t, $f, new ReviseEvaluation(
        $bobEval, (string) $bob->id, technicalScore: 40, qualityScore: 40, deliveryScore: 40,
    ));
    dispatchProc($t, $f, new SubmitEvaluation($bobEval, (string) $bob->id));

    $revealed = queryProc($t, $f, new GetQuotationEvaluations($rfqId));
    expect($revealed->groups[0]->viewerGated)->toBeFalse()
        ->and($revealed->groups[0]->submittedCount)->toBe(2)
        ->and($revealed->groups[0]->evaluations)->toHaveCount(2);
    expect(collect($revealed->groups[0]->evaluations)->pluck('overallScore')->sort()->values()->all())
        ->toBe([40, 80]);
});

test('a procurement.access holder WITHOUT procurement.quotations.view cannot read evaluations', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $creator] = procurementFixture([
        'procurement.access', 'procurement.rfqs.manage', 'procurement.quotations.record',
        'procurement.quotations.view', 'procurement.quotations.evaluate',
    ]);
    $t = (string) $tenant->id;
    $f = (string) $facility->id;
    $s1 = (string) seedSupplier($t, ['code' => 'S1'])->id;

    [$rfqId, , $quoteIds] = closedRfqWithQuotes($t, $f, (string) $creator->id, [$s1], [
        ['lineType' => 'SERVICE', 'description' => 'Widgets', 'quantity' => '10', 'uom' => 'EACH'],
    ]);
    evaluateQuotation($t, $f, (string) $creator->id, $quoteIds[0]);

    $requester = User::factory()->forTenant($t)->create();
    grantProcurementRole($t, $f, (string) $requester->id, ['procurement.access']);
    test()->actingAs($requester);

    expect(fn () => queryProc($t, $f, new GetQuotationEvaluations($rfqId)))
        ->toThrow(AuthorizationException::class);
});

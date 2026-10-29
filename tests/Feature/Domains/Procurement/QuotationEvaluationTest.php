<?php

declare(strict_types=1);

use App\Domains\Procurement\Application\Command\CancelRfq;
use App\Domains\Procurement\Application\Command\EvaluateQuotation;
use App\Domains\Procurement\Application\Command\ReviseEvaluation;
use App\Domains\Procurement\Application\Command\SubmitEvaluation;
use App\Domains\Procurement\Application\Query\GetQuotationEvaluations;
use App\Domains\Procurement\Domain\Exception\EvaluationAlreadyExists;
use App\Domains\Procurement\Domain\Exception\EvaluationScoresIncomplete;
use App\Domains\Procurement\Domain\Exception\IllegalEvaluationTransition;
use App\Domains\Procurement\Domain\Exception\RfqNotOpenForEvaluation;
use App\Domains\Procurement\Domain\Model\QuotationEvaluation;
use App\Models\User;
use App\Shared\Domain\NotFound;
use App\Shared\Tenancy\Tenancy;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * @return array{t: string, f: string, actor: string, rfqId: string, quoteId: string, s1: string}
 */
function closedRfqOneQuote(): array
{
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = procurementFixture([
        'procurement.access', 'procurement.rfqs.manage', 'procurement.quotations.record',
        'procurement.quotations.view', 'procurement.quotations.evaluate',
    ]);
    $t = (string) $tenant->id;
    $f = (string) $facility->id;
    $s1 = (string) seedSupplier($t, ['code' => 'S1'])->id;

    [$rfqId, , $quoteIds] = closedRfqWithQuotes($t, $f, (string) $user->id, [$s1], [
        ['lineType' => 'SERVICE', 'description' => 'Widgets', 'quantity' => '10', 'uom' => 'EACH'],
    ]);

    return ['t' => $t, 'f' => $f, 'actor' => (string) $user->id, 'rfqId' => $rfqId, 'quoteId' => $quoteIds[0], 's1' => $s1];
}

test('evaluate → submit: a DRAFT row is created then locked; overall is the weighted mean', function () {
    ['t' => $t, 'f' => $f, 'actor' => $a, 'rfqId' => $rfqId, 'quoteId' => $quoteId] = closedRfqOneQuote();

    $evalId = (string) Str::uuid7();
    dispatchProc($t, $f, new EvaluateQuotation(
        evaluationId: $evalId, actorId: $a, quotationId: $quoteId,
        commercialScore: 90, technicalScore: 70, qualityScore: 60, deliveryScore: 50,
        comments: 'Strong commercial terms.',
    ));

    $row = TenantContextScope::run($t, null, fn () => QuotationEvaluation::query()->find($evalId));
    expect($row->status)->toBe(QuotationEvaluation::STATUS_DRAFT)
        ->and($row->overall_score)->toBeNull()
        ->and($row->rfq_id)->toBe($rfqId);

    dispatchProc($t, $f, new SubmitEvaluation($evalId, $a));

    $row = TenantContextScope::run($t, null, fn () => QuotationEvaluation::query()->find($evalId));
    expect($row->status)->toBe(QuotationEvaluation::STATUS_SUBMITTED)
        ->and($row->overall_score)->toBe(74)         // 90*40 + 70*30 + 60*15 + 50*15 = 7350 / 100
        ->and((int) $row->version)->toBeGreaterThan(0)
        ->and($row->submitted_at)->not->toBeNull();
});

test('one evaluation per (quotation, evaluator); a second EvaluateQuotation is rejected', function () {
    ['t' => $t, 'f' => $f, 'actor' => $a, 'quoteId' => $quoteId] = closedRfqOneQuote();
    evaluateQuotation($t, $f, $a, $quoteId, submit: false);

    expect(fn () => dispatchProc($t, $f, new EvaluateQuotation(
        evaluationId: (string) Str::uuid7(), actorId: $a, quotationId: $quoteId,
        commercialScore: 50, technicalScore: 50, qualityScore: 50, deliveryScore: 50,
    )))->toThrow(EvaluationAlreadyExists::class);
});

test('revise a DRAFT patches only the supplied fields; bumps version', function () {
    ['t' => $t, 'f' => $f, 'actor' => $a, 'quoteId' => $quoteId] = closedRfqOneQuote();
    $evalId = evaluateQuotation($t, $f, $a, $quoteId, [80, 70, 75, 65], submit: false);

    dispatchProc($t, $f, new ReviseEvaluation($evalId, $a, commercialScore: 95, comments: 'Revised after clarification.'));

    $row = TenantContextScope::run($t, null, fn () => QuotationEvaluation::query()->find($evalId));
    expect((int) $row->commercial_score)->toBe(95)
        ->and((int) $row->technical_score)->toBe(70)   // untouched
        ->and($row->comments)->toBe('Revised after clarification.')
        ->and((int) $row->version)->toBeGreaterThan(0);
});

test('SubmitEvaluation is refused while any dimension score is missing', function () {
    ['t' => $t, 'f' => $f, 'actor' => $a, 'quoteId' => $quoteId] = closedRfqOneQuote();
    $evalId = (string) Str::uuid7();
    dispatchProc($t, $f, new EvaluateQuotation(
        evaluationId: $evalId, actorId: $a, quotationId: $quoteId, commercialScore: 80, technicalScore: 70,
    )); // quality + delivery missing

    expect(fn () => dispatchProc($t, $f, new SubmitEvaluation($evalId, $a)))
        ->toThrow(EvaluationScoresIncomplete::class);
});

test('a SUBMITTED evaluation can no longer be revised or submitted', function () {
    ['t' => $t, 'f' => $f, 'actor' => $a, 'quoteId' => $quoteId] = closedRfqOneQuote();
    $evalId = evaluateQuotation($t, $f, $a, $quoteId);

    expect(fn () => dispatchProc($t, $f, new ReviseEvaluation($evalId, $a, commercialScore: 10)))
        ->toThrow(IllegalEvaluationTransition::class);
    expect(fn () => dispatchProc($t, $f, new SubmitEvaluation($evalId, $a)))
        ->toThrow(IllegalEvaluationTransition::class);
});

test('evaluation is legal only while the RFQ is CLOSED', function () {
    ['t' => $t, 'f' => $f, 'actor' => $a, 'rfqId' => $rfqId, 'quoteId' => $quoteId] = closedRfqOneQuote();
    dispatchProc($t, $f, new CancelRfq($rfqId, $a, 'Requirement withdrawn'));

    expect(fn () => dispatchProc($t, $f, new EvaluateQuotation(
        evaluationId: (string) Str::uuid7(), actorId: $a, quotationId: $quoteId,
        commercialScore: 50, technicalScore: 50, qualityScore: 50, deliveryScore: 50,
    )))->toThrow(RfqNotOpenForEvaluation::class);
});

test('only the author may revise / submit their evaluation', function () {
    ['t' => $t, 'f' => $f, 'actor' => $a, 'f' => $f, 'quoteId' => $quoteId] = closedRfqOneQuote();
    $evalId = evaluateQuotation($t, $f, $a, $quoteId, submit: false);

    $other = User::factory()->forTenant($t)->create();
    grantProcurementRole($t, $f, (string) $other->id, ['procurement.access', 'procurement.quotations.evaluate']);
    test()->actingAs($other);

    expect(fn () => dispatchProc($t, $f, new SubmitEvaluation($evalId, (string) $other->id)))
        ->toThrow(NotFound::class);
});

test('the audit row for an evaluation carries a dimensions-scored count, never the score values or comment', function () {
    ['t' => $t, 'f' => $f, 'actor' => $a, 'quoteId' => $quoteId] = closedRfqOneQuote();
    dispatchProc($t, $f, new EvaluateQuotation(
        evaluationId: (string) Str::uuid7(), actorId: $a, quotationId: $quoteId,
        commercialScore: 88, technicalScore: 77, qualityScore: 66, deliveryScore: 55,
        comments: 'confidential internal note',
    ));

    $row = Tenancy::run($t, fn () => DB::table('shared_audit_log')
        ->where('action', 'procurement.quotation_evaluated')->latest('id')->first());

    expect($row)->not->toBeNull()
        ->and($row->reason)->toBeNull();

    $metadata = json_decode((string) $row->metadata, true);
    expect(array_keys($metadata))->toBe(['quotation_id', 'dimensions_scored'])   // no score fields, no comment
        ->and($metadata['quotation_id'])->toBe($quoteId)
        ->and($metadata['dimensions_scored'])->toBe(4)
        ->and(json_encode($metadata))->not->toContain('confidential');
});

test('GetQuotationEvaluations groups per SUBMITTED quotation and reports the average overall', function () {
    ['t' => $t, 'f' => $f, 'actor' => $a, 'rfqId' => $rfqId, 'quoteId' => $quoteId] = closedRfqOneQuote();
    evaluateQuotation($t, $f, $a, $quoteId, [90, 70, 60, 50]); // overall 74

    $overview = queryProc($t, $f, new GetQuotationEvaluations($rfqId));
    expect($overview->rfqId)->toBe($rfqId)
        ->and($overview->groups)->toHaveCount(1)
        ->and($overview->groups[0]->quotationId)->toBe($quoteId)
        ->and($overview->groups[0]->submittedCount)->toBe(1)
        ->and($overview->groups[0]->averageOverall)->toBe(74)
        ->and($overview->groups[0]->viewerGated)->toBeFalse()
        ->and($overview->groups[0]->evaluations[0]->overallScore)->toBe(74);
});

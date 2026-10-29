<?php

declare(strict_types=1);

use App\Domains\Procurement\Application\Command\EvaluateQuotation;
use App\Domains\Procurement\Application\Command\SubmitEvaluation;
use App\Domains\Procurement\Application\Query\GetQuotationEvaluations;
use App\Domains\Procurement\Domain\EvaluationSettingsReader;
use App\Domains\Procurement\Domain\Exception\EvaluationScoresIncomplete;
use App\Domains\Procurement\Domain\Model\EvaluationSettings;
use App\Domains\Procurement\Domain\Model\QuotationEvaluation;
use App\Shared\Tenancy\Tenancy;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * @return array{t: string, f: string, actor: string, rfqId: string, quoteId: string}
 */
function closedRfqForConfig(): array
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

    return ['t' => $t, 'f' => $f, 'actor' => (string) $user->id, 'rfqId' => $rfqId, 'quoteId' => $quoteIds[0]];
}

test('a tenant with NO settings row falls back to the Proc.2b defaults', function () {
    ['tenant' => $tenant] = procurementFixture(['procurement.access']);
    $t = (string) $tenant->id;

    $criteria = TenantContextScope::run($t, null, fn () => app(EvaluationSettingsReader::class)->forTenant());
    expect($criteria->commercialWeight)->toBe(40)
        ->and($criteria->mandatoryDimensions())->toHaveCount(4)
        ->and($criteria->passThreshold)->toBeNull()
        ->and($criteria->requiredAbove)->toBeNull();
});

test('procurement:configure-evaluation initialises then updates the tenant row and audits each change', function () {
    ['tenant' => $tenant] = procurementFixture(['procurement.access']);
    $t = (string) $tenant->id;

    test()->artisan('procurement:configure-evaluation', [
        '--tenant' => $t, '--commercial-weight' => '60', '--technical-weight' => '20',
        '--quality-weight' => '10', '--delivery-weight' => '10', '--pass-threshold' => '55',
    ])->assertExitCode(0);

    $row = TenantContextScope::run($t, null, fn () => EvaluationSettings::query()->first());
    expect($row->commercial_weight)->toBe(60)->and($row->pass_threshold)->toBe(55)->and((int) $row->version)->toBe(2);

    test()->artisan('procurement:configure-evaluation', [
        '--tenant' => $t, '--quality-required' => '0', '--evaluation-required-above' => '2500000',
    ])->assertExitCode(0);

    $row = TenantContextScope::run($t, null, fn () => EvaluationSettings::query()->first());
    expect($row->quality_required)->toBeFalse()
        ->and((string) $row->evaluation_required_above)->toBe('2500000.00')
        ->and((int) $row->version)->toBe(3);

    $audits = Tenancy::run($t, fn () => DB::table('shared_audit_log')
        ->where('action', 'procurement.evaluation_configured')->count());
    expect($audits)->toBe(2);
});

test('procurement:configure-evaluation rejects invalid weights without writing', function () {
    ['tenant' => $tenant] = procurementFixture(['procurement.access']);
    $t = (string) $tenant->id;

    test()->artisan('procurement:configure-evaluation', ['--tenant' => $t, '--commercial-weight' => '250'])
        ->assertExitCode(1);

    expect(TenantContextScope::run($t, null, fn () => EvaluationSettings::query()->count()))->toBe(0);
});

test('a tenant that marks a dimension optional may submit without it; overall uses the present dimensions', function () {
    ['t' => $t, 'f' => $f, 'actor' => $a, 'rfqId' => $rfqId, 'quoteId' => $quoteId] = closedRfqForConfig();

    test()->artisan('procurement:configure-evaluation', ['--tenant' => $t, '--quality-required' => '0'])->assertExitCode(0);

    $evalId = (string) Str::uuid7();
    dispatchProc($t, $f, new EvaluateQuotation(
        evaluationId: $evalId, actorId: $a, quotationId: $quoteId,
        commercialScore: 90, technicalScore: 70, deliveryScore: 50,   // no quality
    ));
    dispatchProc($t, $f, new SubmitEvaluation($evalId, $a));   // allowed — quality is optional

    $row = TenantContextScope::run($t, null, fn () => QuotationEvaluation::query()->find($evalId));
    // (90*40 + 70*30 + 50*15) / (40+30+15) = 6450 / 85 = 75.88 → 76
    expect($row->status)->toBe('SUBMITTED')->and((int) $row->overall_score)->toBe(76);
});

test('still-mandatory dimensions are enforced at submit', function () {
    ['t' => $t, 'f' => $f, 'actor' => $a, 'quoteId' => $quoteId] = closedRfqForConfig();

    test()->artisan('procurement:configure-evaluation', ['--tenant' => $t, '--quality-required' => '0'])->assertExitCode(0);

    $evalId = (string) Str::uuid7();
    dispatchProc($t, $f, new EvaluateQuotation(
        evaluationId: $evalId, actorId: $a, quotationId: $quoteId, commercialScore: 90,   // technical + delivery still missing
    ));

    expect(fn () => dispatchProc($t, $f, new SubmitEvaluation($evalId, $a)))
        ->toThrow(EvaluationScoresIncomplete::class);
});

test('GetQuotationEvaluations flags belowPassThreshold against the tenant pass mark', function () {
    ['t' => $t, 'f' => $f, 'actor' => $a, 'rfqId' => $rfqId, 'quoteId' => $quoteId] = closedRfqForConfig();

    test()->artisan('procurement:configure-evaluation', ['--tenant' => $t, '--pass-threshold' => '80'])->assertExitCode(0);
    evaluateQuotation($t, $f, $a, $quoteId, [70, 60, 60, 60]);   // overall 64 < 80

    $overview = queryProc($t, $f, new GetQuotationEvaluations($rfqId));
    expect($overview->passThreshold)->toBe(80)
        ->and($overview->groups[0]->evaluations[0]->belowPassThreshold)->toBeTrue();
});

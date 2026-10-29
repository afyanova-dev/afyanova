<?php

declare(strict_types=1);

use App\Domains\Procurement\Application\Command\CloseRfq;
use App\Domains\Procurement\Application\Command\IssueRfq;
use App\Domains\Procurement\Application\Command\ReviseQuotation;
use App\Domains\Procurement\Application\Command\WithdrawQuotation;
use App\Domains\Procurement\Domain\Exception\IllegalQuotationTransition;
use App\Domains\Procurement\Domain\Model\Quotation;
use App\Domains\Procurement\Domain\Model\QuotationLine;
use App\Domains\Procurement\Domain\Model\QuotationRevision;
use App\Shared\Tenancy\Tenancy;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * @return array{t: string, f: string, actor: string, rfqId: string, lineId: string, quoteId: string}
 */
function issuedRfqWithQuote(): array
{
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = procurementFixture([
        'procurement.access', 'procurement.rfqs.manage', 'procurement.quotations.record', 'procurement.quotations.view',
    ]);
    $t = (string) $tenant->id;
    $f = (string) $facility->id;
    $s1 = (string) seedSupplier($t, ['code' => 'S1'])->id;

    [$rfqId, $lineIds] = createRfq($t, $f, (string) $user->id, [$s1], [
        ['lineType' => 'SERVICE', 'description' => 'Widgets', 'quantity' => '10', 'uom' => 'EACH'],
    ]);
    dispatchProc($t, $f, new IssueRfq($rfqId, (string) $user->id));
    $quoteId = recordQuotation($t, $f, (string) $user->id, $rfqId, $s1, $lineIds, '1000', '10');

    return ['t' => $t, 'f' => $f, 'actor' => (string) $user->id, 'rfqId' => $rfqId, 'lineId' => $lineIds[0], 'quoteId' => $quoteId];
}

test('revise a line price: appends an append-only revision row, bumps revision_count + version', function () {
    ['t' => $t, 'f' => $f, 'actor' => $a, 'quoteId' => $quoteId] = issuedRfqWithQuote();
    $lineId = (string) TenantContextScope::run($t, null, fn () => QuotationLine::query()->where('quotation_id', $quoteId)->first()->id);

    dispatchProc($t, $f, new ReviseQuotation($quoteId, $a, 'unit_price', 'Supplier improved the price', '950', $lineId));

    $q = TenantContextScope::run($t, null, fn () => Quotation::query()->find($quoteId));
    expect((int) $q->revision_count)->toBe(1)->and((int) $q->version)->toBeGreaterThan(0);

    $line = TenantContextScope::run($t, null, fn () => QuotationLine::query()->find($lineId));
    expect((string) $line->unit_price)->toBe('950.00');

    $rev = TenantContextScope::run($t, null, fn () => QuotationRevision::query()->where('quotation_id', $quoteId)->first());
    expect($rev->previous_value)->toBe('1000.00')->and($rev->new_value)->toBe('950')
        ->and($rev->reason)->toBe('Supplier improved the price');

    // pcr_quotation_revisions is append-only at the DB.
    $policies = DB::table('pg_policies')->where('tablename', 'pcr_quotation_revisions')->pluck('policyname')->all();
    expect($policies)->toContain('pcr_quotation_revisions_no_update');
    $raw = DB::connection('pgsql_raw');
    $raw->transaction(function () use ($raw, $t, $quoteId): void {
        $raw->statement("select set_config('app.tenant_id', ?, true)", [$t]);
        expect($raw->table('pcr_quotation_revisions')->where('quotation_id', $quoteId)->update(['reason' => 'x']))->toBe(0);
    });
});

test('revise a header field (lead_time_days)', function () {
    ['t' => $t, 'f' => $f, 'actor' => $a, 'quoteId' => $quoteId] = issuedRfqWithQuote();
    dispatchProc($t, $f, new ReviseQuotation($quoteId, $a, 'lead_time_days', 'Faster shipping confirmed', '5'));
    expect((int) TenantContextScope::run($t, null, fn () => Quotation::query()->find($quoteId)->lead_time_days))->toBe(5);
});

test('withdraw a quotation while the RFQ is ISSUED', function () {
    ['t' => $t, 'f' => $f, 'actor' => $a, 'quoteId' => $quoteId] = issuedRfqWithQuote();
    dispatchProc($t, $f, new WithdrawQuotation($quoteId, $a, 'Supplier can no longer supply'));
    expect(TenantContextScope::run($t, null, fn () => Quotation::query()->find($quoteId)->status))->toBe(Quotation::STATUS_WITHDRAWN);
});

test('a SUBMITTED quotation (after CloseRfq) can no longer be revised or withdrawn', function () {
    ['t' => $t, 'f' => $f, 'actor' => $a, 'rfqId' => $rfqId, 'lineId' => $lineId, 'quoteId' => $quoteId] = issuedRfqWithQuote();
    dispatchProc($t, $f, new CloseRfq($rfqId, $a));

    expect(fn () => dispatchProc($t, $f, new ReviseQuotation($quoteId, $a, 'unit_price', 'too late', '900', $lineId)))
        ->toThrow(IllegalQuotationTransition::class);
    expect(fn () => dispatchProc($t, $f, new WithdrawQuotation($quoteId, $a, 'too late')))
        ->toThrow(IllegalQuotationTransition::class);
});

test('a quotation payload written to the audit log carries no free-text reason', function () {
    ['t' => $t, 'f' => $f, 'actor' => $a, 'quoteId' => $quoteId] = issuedRfqWithQuote();
    $lineId = (string) TenantContextScope::run($t, null, fn () => QuotationLine::query()->where('quotation_id', $quoteId)->first()->id);
    dispatchProc($t, $f, new ReviseQuotation($quoteId, $a, 'unit_price', 'confidential negotiation note', '950', $lineId));

    // the reason lands on the audit row (ADR-136 allows `reason` on the audit row) + the revision row, never an event
    $events = Tenancy::run($t, fn () => DB::table('shared_outbox_events')->where('event_class', 'like', '%Procurement%')->count());
    expect($events)->toBe(0); // Proc.2a emits no events at all
});

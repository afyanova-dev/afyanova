<?php

declare(strict_types=1);

use App\Domains\Procurement\Application\Command\CancelRfq;
use App\Domains\Procurement\Application\Command\CloseRfq;
use App\Domains\Procurement\Application\Command\IssueRfq;
use App\Domains\Procurement\Application\Command\RecordQuotation;
use App\Domains\Procurement\Application\Query\GetRfq;
use App\Domains\Procurement\Domain\Exception\IllegalRfqTransition;
use App\Domains\Procurement\Domain\Exception\QuotationAlreadyExists;
use App\Domains\Procurement\Domain\Exception\RfqNotOpenForQuotation;
use App\Domains\Procurement\Domain\Exception\SupplierNotInvited;
use App\Domains\Procurement\Domain\Exception\SupplierNotOrderable;
use App\Domains\Procurement\Domain\Model\Quotation;
use App\Domains\Procurement\Domain\Model\Rfq;
use App\Domains\Procurement\Domain\Model\RfqInvitation;
use App\Domains\Procurement\Domain\Model\RfqLine;
use App\Domains\Procurement\Domain\Model\Supplier;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * @return array{t: string, f: string, actor: string, s1: string, s2: string}
 */
function rfqFixture(): array
{
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = procurementFixture([
        'procurement.access', 'procurement.rfqs.manage', 'procurement.quotations.record', 'procurement.quotations.view', 'inventory.access',
    ]);
    $t = (string) $tenant->id;

    return [
        't' => $t,
        'f' => (string) $facility->id,
        'actor' => (string) $user->id,
        's1' => (string) seedSupplier($t, ['code' => 'S1'])->id,
        's2' => (string) seedSupplier($t, ['code' => 'S2'])->id,
    ];
}

test('create → issue: STOCK lines are snapshotted from InventoryApi; invitations are recorded; the RFQ is DRAFT then ISSUED', function () {
    ['t' => $t, 'f' => $f, 'actor' => $a, 's1' => $s1, 's2' => $s2] = rfqFixture();
    $item = seedInventoryItem($t, ['code' => 'GLOVE-M', 'name' => 'Gloves M', 'uom' => 'BOX']);

    [$rfqId] = createRfq($t, $f, $a, [$s1, $s2], [
        ['lineType' => 'STOCK', 'itemRef' => (string) $item->id, 'quantity' => '100'],
    ]);

    $line = TenantContextScope::run($t, null, fn () => RfqLine::query()->where('rfq_id', $rfqId)->first());
    expect($line->item_code)->toBe('GLOVE-M')->and($line->item_name)->toBe('Gloves M')->and($line->uom)->toBe('BOX');

    expect(TenantContextScope::run($t, null, fn () => RfqInvitation::query()->where('rfq_id', $rfqId)->count()))->toBe(2);
    expect(TenantContextScope::run($t, null, fn () => Rfq::query()->find($rfqId)->status))->toBe(Rfq::STATUS_DRAFT);

    dispatchProc($t, $f, new IssueRfq($rfqId, $a));
    expect(TenantContextScope::run($t, null, fn () => Rfq::query()->find($rfqId)->status))->toBe(Rfq::STATUS_ISSUED);
});

test('inviting a non-ACTIVE supplier is rejected at create', function () {
    ['t' => $t, 'f' => $f, 'actor' => $a, 's1' => $s1] = rfqFixture();
    $blacklisted = (string) seedSupplier($t, ['code' => 'BAD', 'status' => Supplier::STATUS_BLACKLISTED])->id;

    expect(fn () => createRfq($t, $f, $a, [$s1, $blacklisted]))
        ->toThrow(SupplierNotOrderable::class);
});

test('a quotation is accepted only from an invited supplier, and only while the RFQ is ISSUED; one per (RFQ, supplier)', function () {
    ['t' => $t, 'f' => $f, 'actor' => $a, 's1' => $s1, 's2' => $s2] = rfqFixture();
    $uninvited = (string) seedSupplier($t, ['code' => 'S3'])->id;

    [$rfqId, $lineIds] = createRfq($t, $f, $a, [$s1, $s2]);

    // RFQ still DRAFT → not open
    expect(fn () => recordQuotation($t, $f, $a, $rfqId, $s1, $lineIds, '1000'))
        ->toThrow(RfqNotOpenForQuotation::class);

    dispatchProc($t, $f, new IssueRfq($rfqId, $a));

    expect(fn () => recordQuotation($t, $f, $a, $rfqId, $uninvited, $lineIds, '1000'))
        ->toThrow(SupplierNotInvited::class);

    recordQuotation($t, $f, $a, $rfqId, $s1, $lineIds, '1000');

    expect(fn () => dispatchProc($t, $f, new RecordQuotation(
        quotationId: (string) Str::uuid7(), actorId: $a, rfqId: $rfqId, supplierId: $s1,
        lines: [['rfqLineId' => $lineIds[0], 'unitPrice' => '900', 'offeredQty' => '2']],
    )))->toThrow(QuotationAlreadyExists::class);
});

test('close: ISSUED → CLOSED and every RECEIVED quotation → SUBMITTED', function () {
    ['t' => $t, 'f' => $f, 'actor' => $a, 's1' => $s1, 's2' => $s2] = rfqFixture();
    [$rfqId, $lineIds] = createRfq($t, $f, $a, [$s1, $s2]);
    dispatchProc($t, $f, new IssueRfq($rfqId, $a));
    recordQuotation($t, $f, $a, $rfqId, $s1, $lineIds, '1000');
    recordQuotation($t, $f, $a, $rfqId, $s2, $lineIds, '1100');

    dispatchProc($t, $f, new CloseRfq($rfqId, $a));

    expect(TenantContextScope::run($t, null, fn () => Rfq::query()->find($rfqId)->status))->toBe(Rfq::STATUS_CLOSED);
    $statuses = TenantContextScope::run($t, null, fn () => Quotation::query()->where('rfq_id', $rfqId)->pluck('status')->all());
    expect($statuses)->each->toBe(Quotation::STATUS_SUBMITTED);
});

test('cancel needs a reason; not legal from AWARDED', function () {
    ['t' => $t, 'f' => $f, 'actor' => $a, 's1' => $s1, 's2' => $s2] = rfqFixture();
    [$rfqId] = createRfq($t, $f, $a, [$s1, $s2]);
    dispatchProc($t, $f, new CancelRfq($rfqId, $a, 'Requirement withdrawn'));

    $fresh = TenantContextScope::run($t, null, fn () => Rfq::query()->find($rfqId));
    expect($fresh->status)->toBe(Rfq::STATUS_CANCELLED)->and($fresh->cancellation_reason)->toBe('Requirement withdrawn');

    expect(fn () => dispatchProc($t, $f, new IssueRfq($rfqId, $a)))->toThrow(IllegalRfqTransition::class);
});

test('GetRfq returns metadata + lines + invitations on procurement.access — no quotation prices', function () {
    ['t' => $t, 'f' => $f, 'actor' => $a, 's1' => $s1, 's2' => $s2] = rfqFixture();
    [$rfqId, $lineIds] = createRfq($t, $f, $a, [$s1, $s2]);
    dispatchProc($t, $f, new IssueRfq($rfqId, $a));
    recordQuotation($t, $f, $a, $rfqId, $s1, $lineIds, '1000');

    $summary = queryProc($t, $f, new GetRfq($rfqId));
    expect($summary->status)->toBe(Rfq::STATUS_ISSUED)
        ->and($summary->lines)->toHaveCount(1)
        ->and($summary->invitations)->toHaveCount(2)
        ->and($summary->quotationCount)->toBe(1)
        ->and(collect($summary->invitations)->firstWhere('supplierId', $s1)->hasQuotation)->toBeTrue()
        ->and(collect($summary->invitations)->firstWhere('supplierId', $s2)->hasQuotation)->toBeFalse();

    // the RfqSummary DTO carries no price fields at all
    expect(json_encode($summary))->not->toContain('1000');
});

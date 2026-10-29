<?php

declare(strict_types=1);

use App\Domains\Procurement\Application\Command\IssueRfq;
use App\Domains\Procurement\Application\Query\CompareQuotations;
use App\Domains\Procurement\Application\Query\GetRfq;
use App\Domains\Procurement\Application\Query\GetRfqQuotations;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * §101 / §208 — quotation prices are gated on `procurement.quotations.view`,
 * strictly stronger than `procurement.access`.
 */
test('a procurement.access holder WITHOUT procurement.quotations.view sees the RFQ but NOT the quotes', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $manager] = procurementFixture([
        'procurement.access', 'procurement.rfqs.manage', 'procurement.quotations.record', 'procurement.quotations.view',
    ]);
    $t = (string) $tenant->id;
    $f = (string) $facility->id;
    $s1 = (string) seedSupplier($t, ['code' => 'S1'])->id;
    $s2 = (string) seedSupplier($t, ['code' => 'S2'])->id;

    [$rfqId, $lineIds] = createRfq($t, $f, (string) $manager->id, [$s1, $s2], [
        ['lineType' => 'SERVICE', 'description' => 'Widgets', 'quantity' => '10', 'uom' => 'EACH'],
    ]);
    dispatchProc($t, $f, new IssueRfq($rfqId, (string) $manager->id));
    recordQuotation($t, $f, (string) $manager->id, $rfqId, $s1, $lineIds, '1000', '10');
    recordQuotation($t, $f, (string) $manager->id, $rfqId, $s2, $lineIds, '850', '10');

    // A requester with only `procurement.access`.
    $requester = User::factory()->forTenant($t)->create();
    grantProcurementRole($t, $f, (string) $requester->id, ['procurement.access']);
    test()->actingAs($requester);

    // GetRfq works — metadata + award outcome, no losing prices (price values
    // serialize as money strings, e.g. "850.00"; the broader '850' substring
    // is intentionally avoided because ISO timestamps carry microseconds).
    $summary = queryProc($t, $f, new GetRfq($rfqId));
    expect($summary->rfqId)->toBe($rfqId)->and($summary->quotationCount)->toBe(2);
    expect(json_encode($summary))->not->toContain('850.00'); // no competing price leaks

    // GetRfqQuotations / CompareQuotations are refused — `procurement.quotations.view`
    // is required and `procurement.access` does not satisfy it (§101).
    expect(fn () => queryProc($t, $f, new GetRfqQuotations($rfqId)))->toThrow(AuthorizationException::class);
    expect(fn () => queryProc($t, $f, new CompareQuotations($rfqId)))->toThrow(AuthorizationException::class);
});

test('a procurement.quotations.view holder sees every quote and the comparison matrix — ranking NOT applied (§35)', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $viewer] = procurementFixture([
        'procurement.access', 'procurement.rfqs.manage', 'procurement.quotations.record', 'procurement.quotations.view',
    ]);
    $t = (string) $tenant->id;
    $f = (string) $facility->id;
    $s1 = (string) seedSupplier($t, ['code' => 'S1'])->id;
    $s2 = (string) seedSupplier($t, ['code' => 'S2'])->id;

    [$rfqId, $lineIds] = createRfq($t, $f, (string) $viewer->id, [$s1, $s2], [
        ['lineType' => 'SERVICE', 'description' => 'Widgets', 'quantity' => '10', 'uom' => 'EACH'],
    ]);
    dispatchProc($t, $f, new IssueRfq($rfqId, (string) $viewer->id));
    recordQuotation($t, $f, (string) $viewer->id, $rfqId, $s1, $lineIds, '1000', '10');
    recordQuotation($t, $f, (string) $viewer->id, $rfqId, $s2, $lineIds, '850', '10');

    $quotes = queryProc($t, $f, new GetRfqQuotations($rfqId));
    $prices = collect($quotes)->map(fn ($q) => $q->lines[0]->unitPrice)->sort()->values()->all();
    expect($quotes)->toHaveCount(2)->and($prices)->toBe(['850.00', '1000.00']);

    $comparison = queryProc($t, $f, new CompareQuotations($rfqId));
    expect($comparison->rows)->toHaveCount(1)
        ->and($comparison->quotations)->toHaveCount(2)
        ->and(count($comparison->rows[0]->cells))->toBe(2); // both suppliers' cells present, unranked
});

<?php

declare(strict_types=1);

use App\Domains\Billing\Application\Command\AddInvoiceLine;
use App\Domains\Billing\Application\Command\CreateInvoice;
use App\Domains\Billing\Application\Command\RemoveInvoiceLine;
use App\Domains\Billing\Application\Command\VoidInvoice;
use App\Domains\Billing\Application\Query\GetAccountInvoices;
use App\Domains\Billing\Application\Query\GetInvoice;
use App\Domains\Billing\Application\Query\GetOutstandingBalance;
use App\Domains\Billing\Application\Query\GetUninvoicedCharges;
use App\Domains\Billing\Contracts\Event\InvoiceIssued;
use App\Domains\Billing\Contracts\Event\InvoiceVoided;
use App\Domains\Billing\Domain\Exception\ChargeNotForInvoiceAccount;
use App\Domains\Billing\Domain\Exception\IllegalInvoiceTransition;
use App\Domains\Billing\Domain\Exception\InvoiceHasReversedLine;
use App\Domains\Billing\Domain\Exception\NoChargesToInvoice;
use App\Domains\Billing\Domain\ValueObject\InvoiceStatus;
use App\Shared\Tenancy\Tenancy;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * Bill.4 — the Invoice aggregate (ADR-190). An Invoice GROUPS POSTED charges into
 * a facility financial document; it posts ZERO ledger entries (INV-1), never
 * mutates `bill_charges` (INV-2), one live line per charge (INV-3), ISSUED
 * lines + `net` immutable (INV-4), SELF_PAY only (INV-5), number minted only at
 * issue (INV-6), void preserves history (INV-7).
 *
 * @return array{t: string, f: string, a: string, svc: string, item: string, patient: string, account: string}
 */
function invoiceSetup(): array
{
    $fixture = billingFixture();
    $t = (string) $fixture['tenant']->id;
    $f = (string) $fixture['facility']->id;
    $a = (string) $fixture['user']->id;
    $svc = billingAddService($t, $f, $a, 'MED-'.Str::upper(Str::random(4)), 'MEDICATION');
    $item = (string) Str::uuid7();
    $patient = billingPatient($t);

    billingMapItem($t, $f, $a, $item, $svc);
    billingSetTariff($t, $f, $a, $svc, null, '500.00', '2020-01-01');

    return ['t' => $t, 'f' => $f, 'a' => $a, 'svc' => $svc, 'item' => $item, 'patient' => $patient, 'account' => ''];
}

/** Capture a POSTED charge via the Bill.3 dispensing path; return [chargeId, accountId]. */
function invoiceCharge(array $s, string $quantity): array
{
    ['dispensingId' => $d] = billingRelayDispensed($s['t'], $s['f'], $s['patient'], $s['item'], $quantity, $s['a']);
    $chargeId = billingChargeIdForDispensing($s['t'], $d);
    $accountId = (string) Tenancy::run($s['t'], fn () => DB::table('bill_financial_accounts')
        ->where('patient_id', $s['patient'])->where('facility_id', $s['f'])->value('id'));

    return [$chargeId, $accountId, $d];
}

function ledgerRowCount(string $tenantId, string $accountId): int
{
    return (int) Tenancy::run($tenantId, fn () => DB::table('bill_account_ledger_entries')
        ->where('financial_account_id', $accountId)->count());
}

test('CreateInvoice groups all eligible POSTED charges into a DRAFT invoice, posts ZERO ledger entries, mints no number', function () {
    $s = invoiceSetup();
    [, $account] = invoiceCharge($s, '10');   // 5 000.00
    invoiceCharge($s, '4');                    // 2 000.00

    $ledgerBefore = ledgerRowCount($s['t'], $account);

    $invoiceId = billingCreateInvoice($s['t'], $s['f'], $s['a'], $account);

    $invoice = queryBilling($s['t'], $s['f'], new GetInvoice($invoiceId));
    expect($invoice->status)->toBe(InvoiceStatus::DRAFT)
        ->and($invoice->invoiceNumber)->toBeNull()
        ->and($invoice->payerType)->toBe('SELF_PAY')
        ->and($invoice->gross)->toBe('7000.00')
        ->and($invoice->discount)->toBe('0.00')
        ->and($invoice->tax)->toBe('0.00')
        ->and($invoice->net)->toBe('7000.00')
        ->and($invoice->derivedOutstanding)->toBe('7000.00')
        ->and($invoice->lines)->toHaveCount(2)
        ->and($invoice->facilityId)->toBe($s['f'])
        ->and($invoice->patientId)->toBe($s['patient']);

    // INV-1 — no ledger entries were posted by invoicing.
    expect(ledgerRowCount($s['t'], $account))->toBe($ledgerBefore);
    // INV-2 — charges untouched.
    expect(Tenancy::run($s['t'], fn () => DB::table('bill_charges')->where('status', '!=', 'POSTED')->count()))->toBe(0);
});

test('CreateInvoice with an explicit chargeIds subset invoices only those', function () {
    $s = invoiceSetup();
    [$c1, $account] = invoiceCharge($s, '10');
    invoiceCharge($s, '4');

    $invoiceId = billingCreateInvoice($s['t'], $s['f'], $s['a'], $account, [$c1]);

    $invoice = queryBilling($s['t'], $s['f'], new GetInvoice($invoiceId));
    expect($invoice->lines)->toHaveCount(1)
        ->and($invoice->net)->toBe('5000.00')
        ->and($invoice->lines[0]->chargeId)->toBe($c1);

    // the other charge is still uninvoiced
    expect(queryBilling($s['t'], $s['f'], new GetUninvoicedCharges($account)))->toHaveCount(1);
});

test('CreateInvoice with zero eligible charges throws NoChargesToInvoice', function () {
    $s = invoiceSetup();
    [, $account] = invoiceCharge($s, '10');
    billingCreateInvoice($s['t'], $s['f'], $s['a'], $account); // claims the only charge

    expect(fn () => billingCreateInvoice($s['t'], $s['f'], $s['a'], $account))
        ->toThrow(NoChargesToInvoice::class);
});

test('a charge claimed by a live invoice line is excluded from a second CreateInvoice (INV-3)', function () {
    $s = invoiceSetup();
    [$c1, $account] = invoiceCharge($s, '10');
    [$c2] = invoiceCharge($s, '4');

    $first = billingCreateInvoice($s['t'], $s['f'], $s['a'], $account, [$c1]);
    $second = billingCreateInvoice($s['t'], $s['f'], $s['a'], $account); // only c2 remains eligible

    $invoice = queryBilling($s['t'], $s['f'], new GetInvoice($second));
    expect($invoice->lines)->toHaveCount(1)->and($invoice->lines[0]->chargeId)->toBe($c2);
    expect($first)->not->toBe($second);
});

test('IssueInvoice mints INV-00000001, freezes net, records InvoiceIssued, posts no ledger entry', function () {
    $s = invoiceSetup();
    [, $account] = invoiceCharge($s, '10');
    $invoiceId = billingCreateInvoice($s['t'], $s['f'], $s['a'], $account);
    $ledgerBefore = ledgerRowCount($s['t'], $account);

    billingIssueInvoice($s['t'], $s['f'], $s['a'], $invoiceId);

    $invoice = queryBilling($s['t'], $s['f'], new GetInvoice($invoiceId));
    expect($invoice->status)->toBe(InvoiceStatus::ISSUED)
        ->and($invoice->invoiceNumber)->toBe('INV-00000001')
        ->and($invoice->net)->toBe('5000.00')
        ->and($invoice->issuedByUserId)->toBe($s['a'])
        ->and($invoice->issuedAt)->not->toBeNull();

    expect(ledgerRowCount($s['t'], $account))->toBe($ledgerBefore);

    $event = Tenancy::run($s['t'], fn () => DB::table('shared_outbox_events')
        ->where('event_class', InvoiceIssued::class)->first());
    expect($event)->not->toBeNull();
    $payload = json_decode((string) $event->payload, true);
    expect($payload['invoiceNumber'])->toBe('INV-00000001')->and($payload['net'])->toBe('5000.00');
});

test('IssueInvoice on an invoice with no active lines throws NoChargesToInvoice', function () {
    $s = invoiceSetup();
    [$c1, $account] = invoiceCharge($s, '10');
    $invoiceId = billingCreateInvoice($s['t'], $s['f'], $s['a'], $account);
    dispatchBilling($s['t'], $s['f'], new RemoveInvoiceLine(actorId: $s['a'], invoiceId: $invoiceId, chargeId: $c1));

    expect(fn () => billingIssueInvoice($s['t'], $s['f'], $s['a'], $invoiceId))
        ->toThrow(NoChargesToInvoice::class);
});

test('AddInvoiceLine / RemoveInvoiceLine mutate a DRAFT; RemoveInvoiceLine releases the charge', function () {
    $s = invoiceSetup();
    [$c1, $account] = invoiceCharge($s, '10');
    [$c2] = invoiceCharge($s, '4');

    $invoiceId = billingCreateInvoice($s['t'], $s['f'], $s['a'], $account, [$c1]);
    dispatchBilling($s['t'], $s['f'], new AddInvoiceLine(actorId: $s['a'], invoiceId: $invoiceId, chargeId: $c2));

    expect(queryBilling($s['t'], $s['f'], new GetInvoice($invoiceId))->net)->toBe('7000.00');

    dispatchBilling($s['t'], $s['f'], new RemoveInvoiceLine(actorId: $s['a'], invoiceId: $invoiceId, chargeId: $c2));
    $invoice = queryBilling($s['t'], $s['f'], new GetInvoice($invoiceId));
    expect($invoice->net)->toBe('5000.00')
        ->and(array_values(array_filter($invoice->lines, fn ($l) => $l->lineActive)))->toHaveCount(1) // active
        ->and($invoice->lines)->toHaveCount(2); // history preserved (INV-7)

    // c2 is invoiceable again
    expect(queryBilling($s['t'], $s['f'], new GetUninvoicedCharges($account)))->toHaveCount(1);
    $second = billingCreateInvoice($s['t'], $s['f'], $s['a'], $account, [$c2]);
    expect(queryBilling($s['t'], $s['f'], new GetInvoice($second))->lines)->toHaveCount(1);
});

test('RemoveInvoiceLine is a no-op when the charge has no live line on the invoice', function () {
    $s = invoiceSetup();
    [$c1, $account] = invoiceCharge($s, '10');
    $invoiceId = billingCreateInvoice($s['t'], $s['f'], $s['a'], $account, [$c1]);

    dispatchBilling($s['t'], $s['f'], new RemoveInvoiceLine(actorId: $s['a'], invoiceId: $invoiceId, chargeId: (string) Str::uuid7()));

    expect(queryBilling($s['t'], $s['f'], new GetInvoice($invoiceId))->net)->toBe('5000.00');
});

test('AddInvoiceLine on an ISSUED invoice throws IllegalInvoiceTransition', function () {
    $s = invoiceSetup();
    [$c1, $account] = invoiceCharge($s, '10');
    [$c2] = invoiceCharge($s, '4');
    $invoiceId = billingCreateInvoice($s['t'], $s['f'], $s['a'], $account, [$c1]);
    billingIssueInvoice($s['t'], $s['f'], $s['a'], $invoiceId);

    expect(fn () => dispatchBilling($s['t'], $s['f'], new AddInvoiceLine(actorId: $s['a'], invoiceId: $invoiceId, chargeId: $c2)))
        ->toThrow(IllegalInvoiceTransition::class);
});

test('AddInvoiceLine with a charge on a different FinancialAccount throws ChargeNotForInvoiceAccount', function () {
    $s = invoiceSetup();
    [$c1, $account] = invoiceCharge($s, '10');
    $invoiceId = billingCreateInvoice($s['t'], $s['f'], $s['a'], $account, [$c1]);

    // a second patient → a second account → a charge on it
    $patient2 = billingPatient($s['t']);
    ['dispensingId' => $d2] = billingRelayDispensed($s['t'], $s['f'], $patient2, $s['item'], '2', $s['a']);
    $foreignCharge = billingChargeIdForDispensing($s['t'], $d2);

    expect(fn () => dispatchBilling($s['t'], $s['f'], new AddInvoiceLine(actorId: $s['a'], invoiceId: $invoiceId, chargeId: $foreignCharge)))
        ->toThrow(ChargeNotForInvoiceAccount::class);
});

test('VoidInvoice on an ISSUED invoice preserves history, releases the charges, records InvoiceVoided', function () {
    $s = invoiceSetup();
    [$c1, $account] = invoiceCharge($s, '10');
    $invoiceId = billingCreateInvoice($s['t'], $s['f'], $s['a'], $account, [$c1]);
    billingIssueInvoice($s['t'], $s['f'], $s['a'], $invoiceId);

    dispatchBilling($s['t'], $s['f'], new VoidInvoice(actorId: $s['a'], invoiceId: $invoiceId, reasonCode: 'BILLING_ERROR'));

    $invoice = queryBilling($s['t'], $s['f'], new GetInvoice($invoiceId));
    expect($invoice->status)->toBe(InvoiceStatus::VOIDED)
        ->and($invoice->invoiceNumber)->toBe('INV-00000001')   // history preserved (INV-7)
        ->and($invoice->net)->toBe('5000.00')
        ->and($invoice->derivedOutstanding)->toBe('0.00')
        ->and($invoice->voidReasonCode)->toBe('BILLING_ERROR')
        ->and($invoice->lines)->toHaveCount(1)
        ->and($invoice->lines[0]->lineActive)->toBeFalse();

    // the charge is invoiceable again
    expect(queryBilling($s['t'], $s['f'], new GetUninvoicedCharges($account)))->toHaveCount(1);
    $event = Tenancy::run($s['t'], fn () => DB::table('shared_outbox_events')
        ->where('event_class', InvoiceVoided::class)->first());
    expect($event)->not->toBeNull();
});

test('a post-issue Charge reversal never mutates the issued net; derivedOutstanding drops by the reversed line (INV-4)', function () {
    $s = invoiceSetup();
    [$c1, $account, $d1] = invoiceCharge($s, '10');  // 5 000.00
    [$c2] = invoiceCharge($s, '4');                  // 2 000.00
    $invoiceId = billingCreateInvoice($s['t'], $s['f'], $s['a'], $account);
    billingIssueInvoice($s['t'], $s['f'], $s['a'], $invoiceId);

    billingRelayDispensingReversed($s['t'], $s['f'], $s['patient'], $s['item'], '10', $d1, 'WRONG_ITEM');

    $invoice = queryBilling($s['t'], $s['f'], new GetInvoice($invoiceId));
    expect($invoice->status)->toBe(InvoiceStatus::ISSUED)
        ->and($invoice->net)->toBe('7000.00')                 // FROZEN
        ->and($invoice->derivedOutstanding)->toBe('2000.00'); // 7 000 − 5 000 reversed
});

test('IssueInvoice refuses while an active line references a REVERSED charge (InvoiceHasReversedLine)', function () {
    $s = invoiceSetup();
    [$c1, $account, $d1] = invoiceCharge($s, '10');
    $invoiceId = billingCreateInvoice($s['t'], $s['f'], $s['a'], $account, [$c1]);

    billingRelayDispensingReversed($s['t'], $s['f'], $s['patient'], $s['item'], '10', $d1, 'WRONG_ITEM');

    expect(fn () => billingIssueInvoice($s['t'], $s['f'], $s['a'], $invoiceId))
        ->toThrow(InvoiceHasReversedLine::class);
});

test('AddInvoiceLine of a REVERSED charge throws InvoiceHasReversedLine', function () {
    $s = invoiceSetup();
    [$c1, $account] = invoiceCharge($s, '10');
    [$c2, , $d2] = invoiceCharge($s, '4');
    $invoiceId = billingCreateInvoice($s['t'], $s['f'], $s['a'], $account, [$c1]);

    billingRelayDispensingReversed($s['t'], $s['f'], $s['patient'], $s['item'], '4', $d2, 'WRONG_ITEM');

    expect(fn () => dispatchBilling($s['t'], $s['f'], new AddInvoiceLine(actorId: $s['a'], invoiceId: $invoiceId, chargeId: $c2)))
        ->toThrow(InvoiceHasReversedLine::class);
});

test('GetAccountInvoices returns the account invoices newest first', function () {
    $s = invoiceSetup();
    [$c1, $account] = invoiceCharge($s, '10');
    [$c2] = invoiceCharge($s, '4');
    $i1 = billingCreateInvoice($s['t'], $s['f'], $s['a'], $account, [$c1]);
    $i2 = billingCreateInvoice($s['t'], $s['f'], $s['a'], $account, [$c2]);

    $list = queryBilling($s['t'], $s['f'], new GetAccountInvoices($account));
    expect($list)->toHaveCount(2)
        ->and($list[0]->invoiceId)->toBe($i2)
        ->and($list[1]->invoiceId)->toBe($i1);
});

test('a REVERSED charge is not eligible for CreateInvoice', function () {
    $s = invoiceSetup();
    [$c1, $account, $d1] = invoiceCharge($s, '10');
    billingRelayDispensingReversed($s['t'], $s['f'], $s['patient'], $s['item'], '10', $d1, 'WRONG_ITEM');

    expect(fn () => billingCreateInvoice($s['t'], $s['f'], $s['a'], $account))
        ->toThrow(NoChargesToInvoice::class);
});

test('the invoice balance is derived — no bill_account_ledger_entries row is written across the whole lifecycle', function () {
    $s = invoiceSetup();
    [$c1, $account] = invoiceCharge($s, '10');
    [$c2] = invoiceCharge($s, '4');
    $ledgerBaseline = ledgerRowCount($s['t'], $account); // 2 CHARGE DEBITs from Bill.3

    $invoiceId = billingCreateInvoice($s['t'], $s['f'], $s['a'], $account);
    dispatchBilling($s['t'], $s['f'], new RemoveInvoiceLine(actorId: $s['a'], invoiceId: $invoiceId, chargeId: $c2));
    dispatchBilling($s['t'], $s['f'], new AddInvoiceLine(actorId: $s['a'], invoiceId: $invoiceId, chargeId: $c2));
    billingIssueInvoice($s['t'], $s['f'], $s['a'], $invoiceId);
    dispatchBilling($s['t'], $s['f'], new VoidInvoice(actorId: $s['a'], invoiceId: $invoiceId, reasonCode: 'DUPLICATE'));

    expect(ledgerRowCount($s['t'], $account))->toBe($ledgerBaseline);
    expect(queryBilling($s['t'], $s['f'], new GetOutstandingBalance($account))->outstanding)->toBe('7000.00');
});

test('a user without billing.invoices.manage cannot CreateInvoice', function () {
    $s = invoiceSetup();
    [, $account] = invoiceCharge($s, '10');

    $stranger = billingUserWithKeys($s['t'], $s['f'], ['billing.access']);
    test()->actingAs($stranger);

    expect(fn () => dispatchBilling($s['t'], $s['f'], new CreateInvoice(
        invoiceId: (string) Str::uuid7(), actorId: (string) $stranger->id, financialAccountId: $account,
    )))->toThrow(AuthorizationException::class);
});

test('CreateInvoice is idempotent on the command idempotency key', function () {
    $s = invoiceSetup();
    [$c1, $account] = invoiceCharge($s, '10');
    $invoiceId = (string) Str::uuid7();
    $key = (string) Str::uuid7();

    dispatchBilling($s['t'], $s['f'], new CreateInvoice(
        invoiceId: $invoiceId, actorId: $s['a'], financialAccountId: $account, chargeIds: null, idempotencyKey: $key,
    ));
    dispatchBilling($s['t'], $s['f'], new CreateInvoice(
        invoiceId: $invoiceId, actorId: $s['a'], financialAccountId: $account, chargeIds: null, idempotencyKey: $key,
    ));

    expect(Tenancy::run($s['t'], fn () => DB::table('bill_invoices')->where('id', $invoiceId)->count()))->toBe(1);
    expect(Tenancy::run($s['t'], fn () => DB::table('bill_invoice_lines')->where('invoice_id', $invoiceId)->count()))->toBe(1);
});

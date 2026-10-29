<?php

declare(strict_types=1);

use App\Domains\Billing\Contracts\BillingApi;
use App\Shared\Tenancy\TenantContextScope;
use App\Shared\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

test('handover stays blocked until the dispensing-specific invoice is issued and fully paid', function (): void {
    $fixture = billingFixture();
    $tenantId = (string) $fixture['tenant']->id;
    $facilityId = (string) $fixture['facility']->id;
    $actorId = (string) $fixture['user']->id;

    $serviceId = billingAddService($tenantId, $facilityId, $actorId, 'MED-'.Str::upper(Str::random(4)), 'MEDICATION');
    $itemId = (string) Str::uuid7();
    $patientId = billingPatient($tenantId);
    billingMapItem($tenantId, $facilityId, $actorId, $itemId, $serviceId);
    billingSetTariff($tenantId, $facilityId, $actorId, $serviceId, null, '500.00', '2020-01-01');

    ['dispensingId' => $dispensingId] = billingRelayDispensed(
        $tenantId,
        $facilityId,
        $patientId,
        $itemId,
        '10',
        $actorId,
    );

    $cleared = fn (): bool => TenantContextScope::run(
        $tenantId,
        $facilityId,
        fn (): bool => app(BillingApi::class)->dispensingClearedForHandover($dispensingId),
    );
    expect($cleared())->toBeFalse();

    $chargeId = (string) Tenancy::run($tenantId, fn () => DB::table('bill_billable_events')
        ->where('source_ref', $dispensingId)
        ->value('charge_id'));
    $accountId = (string) Tenancy::run($tenantId, fn () => DB::table('bill_charges')
        ->where('id', $chargeId)
        ->value('financial_account_id'));

    $invoiceId = billingCreateInvoice($tenantId, $facilityId, $actorId, $accountId, [$chargeId]);
    expect($cleared())->toBeFalse();
    billingIssueInvoice($tenantId, $facilityId, $actorId, $invoiceId);
    expect($cleared())->toBeFalse();

    $paymentId = billingRecordPayment($tenantId, $facilityId, $actorId, $accountId, '5000.00');
    billingAllocatePayment($tenantId, $facilityId, $actorId, $paymentId, $invoiceId, '5000.00');

    expect($cleared())->toBeTrue();
});

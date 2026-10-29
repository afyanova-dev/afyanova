<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Billing\Presentation;

use App\Domains\Billing\Application\Command\CaptureBillableEvent;
use App\Domains\Billing\Application\Command\OpenFinancialAccount;
use App\Domains\Patient\Domain\Model\Patient as PatientModel;
use App\Domains\Patient\Domain\Model\PatientRegistration;
use App\Models\User;
use App\Shared\Authorization\ScopeChecker;
use App\Shared\Tenancy\TenantContextScope;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

/**
 * Cashier workspace presentation (UI-SPECIFICATIONS.md §11.14, phase 1):
 * session → account → invoice → payment → receipt → allocate.
 *
 * @return array{t: string, f: string, user: mixed, patient: string, account: string}
 */
function cashierSetup(?array $permissions = null, array $extraPermissions = [], bool $openAccount = true): array
{
    $fixture = billingFixture($permissions);
    $t = (string) $fixture['tenant']->id;
    $f = (string) $fixture['facility']->id;
    $a = (string) $fixture['user']->id;

    foreach ($extraPermissions as $key) {
        TenantContextScope::run($t, null, function () use ($t, $fixture, $key): void {
            $permId = DB::table('idn_permissions')->where('key', $key)->value('id')
                ?? tap((string) Str::uuid7(), fn (string $id) => DB::table('idn_permissions')->insert([
                    'id' => $id, 'key' => $key, 'layer' => 'ACTION',
                    'created_at' => now(), 'updated_at' => now(),
                ]));
            $roleId = DB::table('idn_access_assignments')->where('user_id', $fixture['user']->id)->value('role_id');
            DB::table('idn_permission_role')->insert([
                'id' => (string) Str::uuid7(), 'tenant_id' => $t,
                'role_id' => $roleId, 'permission_id' => $permId, 'created_at' => now(), 'updated_at' => now(),
            ]);
        });
    }

    $patient = billingPatient($t);
    TenantContextScope::run($t, $f, function () use ($t, $f, $patient): void {
        PatientRegistration::factory()->forTenant($t)
            ->forPatient($patient)
            ->atFacility($f)
            ->create();
    });
    $account = (string) Str::uuid7();
    if ($openAccount) {
        dispatchBilling($t, $f, new OpenFinancialAccount(
            financialAccountId: $account, actorId: $a, patientId: $patient, facilityId: $f,
        ));
    }

    return ['t' => $t, 'f' => $f, 'user' => $fixture['user'], 'patient' => $patient, 'account' => $account];
}

/** Seed one POSTED charge on the account via the Bill.3 capture path. */
function cashierSeedCharge(string $t, string $f, string $a, string $patient): void
{
    $service = billingAddService($t, $f, $a, 'CONSULT', 'CONSULTATION');
    billingSetTariff($t, $f, $a, $service, null, '25000.00', '2026-01-01');
    $item = (string) Str::uuid7();
    billingMapItem($t, $f, $a, $item, $service);
    dispatchBilling($t, $f, new CaptureBillableEvent(
        sourceEventId: (string) Str::uuid7(),
        dispensingId: (string) Str::uuid7(),
        patientId: $patient,
        facilityId: $f,
        itemId: $item,
        quantity: '1',
        dispensedByUserId: $a,
        occurredAt: '2026-06-01T10:00:00+00:00',
    ));
}

// Slice pages unbuilt: disable missing-page enforcement so response shapes
// stay testable until the .tsx lands (then delete this block).
beforeEach(function (): void {
    config([
        'inertia.pages.ensure_pages_exist' => false,
        'inertia.testing.ensure_pages_exist' => false,
    ]);
});

it('redirects guests to login and forbids users without billing.access', function (): void {
    $this->get('/workspaces/billing')->assertRedirect('/login');

    $limited = makeTenantWithUser(['patient.patients.read']);
    $limitedFacility = (string) $limited['facility']->id;
    $this->actingAs($limited['user'])->withSession(['active_facility_id' => $limitedFacility])
        ->get('/workspaces/billing')->assertStatus(403);
});

it('grants the seeded cashier access and denies the seeded receptionist', function (): void {
    $this->seed(DatabaseSeeder::class);

    $cashier = User::where('email', 'cashier@afyanova.local')->firstOrFail();
    $receptionist = User::where('email', 'reception@afyanova.local')->firstOrFail();
    $scope = app(ScopeChecker::class);

    $facilityId = null;
    TenantContextScope::run($cashier->tenant_id, null, function () use ($cashier, $scope, &$facilityId): void {
        $facilityId = app(ScopeChecker::class)->selectableFacilities($cashier)[0];
        $perms = $scope->effectivePermissions($cashier, $facilityId);
        expect($perms)->toContain('billing.access')
            ->and($perms)->toContain('billing.payments.manage')
            ->and($perms)->toContain('billing.cash_sessions.manage')
            ->and($perms)->not->toContain('encounter.encounters.create');
    });

    $this->actingAs($cashier)->withSession(['active_facility_id' => $facilityId])
        ->get('/workspaces/billing')->assertOk();

    $this->actingAs($receptionist)->withSession(['active_facility_id' => $facilityId])
        ->get('/workspaces/billing')->assertStatus(403);
});

it('renders the cashier workspace for authorized users', function (): void {
    $fx = cashierSetup();
    $this->actingAs($fx['user'])->withSession(['active_facility_id' => $fx['f']])
        ->get('/workspaces/billing')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Billing/Index')
            ->where('facilityId', $fx['f'])
            ->where('mySession', null)
            ->where('accountId', null));
});

it('opens a cash session and refuses a second live one', function (): void {
    $fx = cashierSetup();

    $this->actingAs($fx['user'])->withSession(['active_facility_id' => $fx['f']])
        ->post('/workspaces/billing/cash-sessions/open', ['opening_float' => '50000.00'])
        ->assertRedirect('/workspaces/billing');

    TenantContextScope::run($fx['t'], $fx['f'], function () use ($fx): void {
        expect(DB::table('bill_cash_sessions')->where('tenant_id', $fx['t'])->count())->toBe(1);
    });

    $this->actingAs($fx['user'])->withSession(['active_facility_id' => $fx['f']])
        ->post('/workspaces/billing/cash-sessions/open', ['opening_float' => '0.00'])
        ->assertSessionHasErrors(['cash_session']);
});

it('leaves no account behind when CASH is refused with no session', function (): void {
    $fx = cashierSetup(null, [], false);

    $this->actingAs($fx['user'])->withSession(['active_facility_id' => $fx['f']])
        ->post('/workspaces/billing/payments', [
            'patient_id' => $fx['patient'],
            'amount' => '1000.00',
            'method' => 'CASH',
        ])
        ->assertSessionHasErrors(['payment']);

    TenantContextScope::run($fx['t'], $fx['f'], function () use ($fx): void {
        expect(DB::table('bill_financial_accounts')->where('tenant_id', $fx['t'])->count())->toBe(0);
        expect(DB::table('bill_payments')->where('tenant_id', $fx['t'])->count())->toBe(0);
    });
});

it('refuses a CASH payment with no open session', function (): void {
    $fx = cashierSetup();

    $this->actingAs($fx['user'])->withSession(['active_facility_id' => $fx['f']])
        ->post('/workspaces/billing/payments', [
            'patient_id' => $fx['patient'],
            'amount' => '10000.00',
            'method' => 'CASH',
        ])
        ->assertSessionHasErrors(['payment']);
});

it('records a mobile-money payment and mints a receipt', function (): void {
    $fx = cashierSetup();

    $response = $this->actingAs($fx['user'])->withSession(['active_facility_id' => $fx['f']])
        ->post('/workspaces/billing/payments', [
            'patient_id' => $fx['patient'],
            'amount' => '10000.00',
            'method' => 'MOBILE_MONEY',
            'payment_reference' => 'MPESA-123',
        ]);

    $response->assertRedirect();
    $response->assertSessionHas('status');
    $paymentId = session('recorded_payment_id');
    expect($paymentId)->not->toBeNull();

    TenantContextScope::run($fx['t'], $fx['f'], function () use ($fx, $paymentId): void {
        $payment = DB::table('bill_payments')->where('tenant_id', $fx['t'])->where('id', $paymentId)->first();
        expect($payment)->not->toBeNull();
        expect(bccomp((string) $payment->amount, '10000.00', 2))->toBe(0);

        $receipt = DB::table('bill_receipts')->where('tenant_id', $fx['t'])->where('payment_id', $paymentId)->first();
        expect($receipt)->not->toBeNull();
        expect((string) $receipt->receipt_number)->toStartWith('RCT-');
    });
});

it('creates and issues an invoice then allocates a payment to it', function (): void {
    $fx = cashierSetup();
    cashierSeedCharge($fx['t'], $fx['f'], (string) $fx['user']->id, $fx['patient']);

    $this->actingAs($fx['user'])->withSession(['active_facility_id' => $fx['f']])
        ->post('/workspaces/billing/invoices', ['financial_account_id' => $fx['account']])
        ->assertRedirect('/workspaces/billing');

    $invoiceId = TenantContextScope::run($fx['t'], $fx['f'], function () use ($fx): string {
        $invoice = DB::table('bill_invoices')->where('tenant_id', $fx['t'])->first();
        expect($invoice)->not->toBeNull();
        expect((string) $invoice->status)->toBe('ISSUED');
        expect((string) $invoice->invoice_number)->toStartWith('INV-');

        return (string) $invoice->id;
    });

    $paymentId = TenantContextScope::run($fx['t'], $fx['f'], function () use ($fx): string {
        return billingRecordPayment($fx['t'], $fx['f'], (string) $fx['user']->id, $fx['account'], '25000.00', 'MOBILE_MONEY');
    });

    $this->actingAs($fx['user'])->withSession(['active_facility_id' => $fx['f']])
        ->post('/workspaces/billing/payments/allocate', [
            'payment_id' => $paymentId,
            'invoice_id' => $invoiceId,
            'amount' => '25000.00',
        ])
        ->assertSessionHasNoErrors();

    TenantContextScope::run($fx['t'], $fx['f'], function () use ($fx, $paymentId, $invoiceId): void {
        expect(DB::table('bill_payment_allocations')
            ->where('tenant_id', $fx['t'])
            ->where('payment_id', $paymentId)
            ->where('invoice_id', $invoiceId)
            ->where('allocation_active', true)
            ->count())->toBe(1);
    });
});

it('closes a cash session with a counted drawer', function (): void {
    $fx = cashierSetup();
    $sessionId = TenantContextScope::run($fx['t'], $fx['f'], function () use ($fx): string {
        return billingOpenCashSession($fx['t'], $fx['f'], (string) $fx['user']->id, '50000.00');
    });

    $this->actingAs($fx['user'])->withSession(['active_facility_id' => $fx['f']])
        ->post("/workspaces/billing/cash-sessions/{$sessionId}/close", ['counted_cash' => '50000.00'])
        ->assertRedirect('/workspaces/billing');

    TenantContextScope::run($fx['t'], $fx['f'], function () use ($fx, $sessionId): void {
        $session = DB::table('bill_cash_sessions')->where('tenant_id', $fx['t'])->where('id', $sessionId)->first();
        expect((string) $session->status)->toBe('CLOSED');
        expect(bccomp((string) $session->variance, '0.00', 2))->toBe(0);
    });
});

it('validates money input and denies writers without the action key', function (): void {
    $fx = cashierSetup();

    $this->actingAs($fx['user'])->withSession(['active_facility_id' => $fx['f']])
        ->post('/workspaces/billing/payments', [
            'patient_id' => $fx['patient'],
            'amount' => '-5',
            'method' => 'CASH',
        ])
        ->assertSessionHasErrors(['amount']);

    $reader = makeTenantWithUser(['billing.access']);
    $readerFacility = (string) $reader['facility']->id;
    $this->actingAs($reader['user'])->withSession(['active_facility_id' => $readerFacility])
        ->post('/workspaces/billing/payments', [
            'patient_id' => $fx['patient'],
            'amount' => '100.00',
            'method' => 'CASH',
        ])
        ->assertStatus(403);
});

it('searches the MPI with the shared result shape', function (): void {
    // MPI search is Patient's ProtectedQuery: needs patient.patients.read
    // on top of the billing keys (the seeded cashier role carries both).
    $fx = cashierSetup(null, ['patient.patients.read']);
    $number = TenantContextScope::run($fx['t'], $fx['f'], function () use ($fx): string {
        $patient = PatientModel::query()->whereKey($fx['patient'])->firstOrFail();

        return (string) $patient->patient_number;
    });

    $this->actingAs($fx['user'])->withSession(['active_facility_id' => $fx['f']])
        ->get('/workspaces/billing?q='.urlencode($number))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Billing/Index')
            ->where('query', $number)
            ->has('searchResults', 1)
            ->where('searchResults.0.patientId', $fx['patient'])
            ->where('searchResults.0.patientNumber', $number)
            ->has('searchResults.0.displayName')
            ->has('searchResults.0.isProvisional')
            ->has('searchResults.0.hasAccessibleFacilityRegistration'));
});

it('does not leak another tenant account through patient lookup', function (): void {
    $a = cashierSetup();
    $b = cashierSetup();

    $this->actingAs($a['user'])->withSession(['active_facility_id' => $a['f']])
        ->get('/workspaces/billing?patient_id='.$b['patient'])
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Billing/Index')
            ->where('accountId', null)
            ->where('searchError', 'Patient was not found.'));
});

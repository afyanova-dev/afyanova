<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 10 (increment Bill.2) — the base tariff / price list
 * (20-billing-and-payments-domain.md §17, §19–§24, §232–§235; ADR-188 D-B).
 *
 * The base service tariff (ADR-112 — Insurance owns the payer rate, Procurement
 * the purchase price). Effective-dated. Mutable — a `SetTariff` closes the prior
 * open row ONCE (`effective_to` + `status`); a row's `amount` is never mutated
 * (§19 / §235 — historical pricing preserved). The `pcr_contracts` supersession
 * precedent.
 *
 * Pricing scope (ADR-188 D-B, F4): `facility_id` is NOT NULL — a real
 * `org_facilities` id for a facility override, or `= tenant_id` (a sentinel) for
 * the tenant-wide default. The sentinel makes "one open row per scope"
 * constrainable by a partial unique index (a `NULL` facility_id cannot be).
 *
 * No temporal overlap, by construction: within one scope the rows partition
 * `[first.effective_from, infinity)` — `effective_to` is EXCLUSIVE and
 * `SetTariff` rejects an `effective_from` not strictly after the current open
 * row's. `amount` is `Money::of()`-normalised (scale-2; trailing ...00) —
 * `SetTariff` rejects sub-cent input; `currency` is TZS-only (the Bill.1 rule).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bill_tariffs', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();

            $table->uuid('billing_service_id');
            $table->uuid('facility_id');                        // a real facility id, or = tenant_id (sentinel = tenant-wide default)

            $table->decimal('amount', 18, 4);                   // always Money::of() — scale-2, trailing ...00
            $table->string('currency')->default('TZS');

            $table->date('effective_from');
            $table->date('effective_to')->nullable();           // EXCLUSIVE upper bound; NULL = open/current
            $table->string('status')->default('CURRENT');       // CURRENT | SUPERSEDED | ENDED

            $table->timestampTz('occurred_at');
            $table->timestampTz('recorded_at');

            $table->unsignedBigInteger('version')->default(0);
            $table->timestampsTz();

            $table->index(['tenant_id', 'billing_service_id', 'facility_id', 'effective_from']);
        });

        // ADR-188 D-B / F1 — at most one open row per (tenant, service, scope).
        DB::statement(
            'create unique index bill_tariffs_one_open_per_scope
                 on bill_tariffs (tenant_id, billing_service_id, facility_id)
              where effective_to is null'
        );

        Rls::enable('bill_tariffs');
    }

    public function down(): void
    {
        Rls::disable('bill_tariffs');
        Schema::dropIfExists('bill_tariffs');
    }
};

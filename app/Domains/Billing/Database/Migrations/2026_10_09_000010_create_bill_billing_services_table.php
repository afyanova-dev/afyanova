<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 10 (increment Bill.2) — the billable-service catalog
 * (20-billing-and-payments-domain.md §13, §236, §237; ADR-188 D-A).
 *
 * Billing's own "what can be charged for" master. §13 — a billing admin authors
 * every service; nothing auto-creates one. The billing-service <-> operational-
 * service (event) mapping (§237) is Bill.3's decision. Mutable working record —
 * `Rls::enable` only. `code` is SET-ONCE (immutable after `AddBillingService`);
 * `category` / `status` are open-coded string constants (the `PharmacyOrderStatus`
 * precedent). `name` is not PHI — queryable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bill_billing_services', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();

            $table->string('code');                             // SET-ONCE — tenant-unique
            $table->string('name');
            $table->string('category');                         // CONSULTATION | PROCEDURE | LABORATORY | RADIOLOGY | MEDICATION | BED_DAY | OTHER
            $table->string('status')->default('ACTIVE');        // ACTIVE | INACTIVE

            $table->timestampTz('occurred_at');
            $table->timestampTz('recorded_at');

            $table->unsignedBigInteger('version')->default(0);
            $table->timestampsTz();

            $table->unique(['tenant_id', 'code']);              // ADR-188 D-A
            $table->index(['tenant_id', 'category', 'status']);
        });

        Rls::enable('bill_billing_services');
    }

    public function down(): void
    {
        Rls::disable('bill_billing_services');
        Schema::dropIfExists('bill_billing_services');
    }
};

<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 10 (increment Ins.1) — the payer master
 * (21-insurance-and-payer-domain.md §8, §9, §10; ADR-198 D-G).
 *
 * Insurance's own "who may be responsible for payment" master. §12 / §122 — a
 * payer has a governed lifecycle authored by an HTTP command, not seed data.
 * Tenant-scoped (§156; §158's platform payer master is a PHASE 12 concern).
 * Mutable working record — `Rls::enable` only. `code` is SET-ONCE (immutable
 * after `RegisterPayer`); `payer_type` / `status` are open-coded string
 * constants (the `bill_billing_services` precedent). `SELF_PAY` is NOT a valid
 * `payer_type` (§141). NO events — configuration; the audit log covers it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ins_payers', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();

            $table->string('code');                        // SET-ONCE — tenant-unique
            $table->string('name');
            $table->string('payer_type');                  // INSURANCE | GOVERNMENT_PROGRAM | CORPORATE | EMPLOYER | SELF_FUNDED | OTHER_CONTRACTED_PAYER
            $table->string('status')->default('ACTIVE');   // ACTIVE | INACTIVE
            $table->string('status_reason')->nullable();   // reason for the last status change

            $table->string('contact_name')->nullable();
            $table->string('contact_email')->nullable();
            $table->string('contact_phone')->nullable();

            $table->uuid('created_by_user_id')->nullable();

            $table->timestampTz('occurred_at');
            $table->timestampTz('recorded_at');

            $table->unsignedBigInteger('version')->default(0);
            $table->timestampsTz();

            $table->unique(['tenant_id', 'code']);          // ADR-198 D-G
            $table->index(['tenant_id', 'payer_type', 'status']);
        });

        Rls::enable('ins_payers');
    }

    public function down(): void
    {
        Rls::disable('ins_payers');
        Schema::dropIfExists('ins_payers');
    }
};

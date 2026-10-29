<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 10 (increment Ins.7) — claim line: snapshot of one covered, insured
 * billing charge on a claim (21-insurance-and-payer-domain.md §78; ADR-204).
 *
 * Invariant 7 (§272) — one live line per charge in a tenant:
 *   `unique (tenant_id, charge_id) WHERE line_active = true`.
 * A live claim line claims a charge. Cancelling a draft claim flips line_active to false,
 * releasing the charge. Superseding a claim atomically flips the old claim's lines to
 * line_active = false and inserts the replacement lines with line_active = true.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ins_claim_lines', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();

            $table->uuid('claim_id')->index();
            $table->uuid('charge_id'); // a bill_charges id — plain uuid, NO FK, NOT loaded via its repo

            $table->string('service_category', 32);
            $table->string('service_code', 64);
            $table->string('service_description');
            $table->date('service_date');

            $table->decimal('quantity', 18, 4);
            $table->decimal('unit_price', 18, 4);
            $table->decimal('claimed_amount', 18, 4); // verbatim invoice payer_responsibility

            $table->uuid('authorization_id')->nullable()->index();
            $table->string('diagnosis_code')->nullable();

            $table->unsignedInteger('line_number');
            $table->boolean('line_active')->default(true);

            $table->timestampTz('occurred_at');
            $table->timestampTz('recorded_at');
            $table->unsignedBigInteger('version')->default(0);
            $table->timestampsTz();

            $table->index(['tenant_id', 'claim_id']);
        });

        // Invariant 7 — one live claim line per charge in a tenant.
        DB::statement(
            'create unique index ins_claim_lines_one_live_per_charge
                 on ins_claim_lines (tenant_id, charge_id)
              where line_active = true'
        );

        Rls::enable('ins_claim_lines');
    }

    public function down(): void
    {
        Rls::disable('ins_claim_lines');
        Schema::dropIfExists('ins_claim_lines');
    }
};

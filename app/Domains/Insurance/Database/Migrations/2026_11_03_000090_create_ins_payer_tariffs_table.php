<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 10 (increment Ins.6) — payer tariffs / contracted rates
 * (21-insurance-and-payer-domain.md §51–§55, §114–§116; ADR-112; ADR-203).
 *
 * Payer-negotiated allowed price list for services under a contract.
 * Follows the Bill.2 effective-dated non-overlapping-by-construction idiom:
 * `effective_to` is an EXCLUSIVE upper bound, `SetPayerTariff` closes the prior
 * open row and rejects an `effective_from` not strictly after it, and a partial
 * unique on the open row (`effective_to is null`) caps it at one.
 *
 * Mutable working record — Rls::enable only. NO events (ADR-137).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ins_payer_tariffs', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();

            $table->uuid('contract_id');                                        // SET-ONCE
            $table->uuid('payer_id');                                           // SET-ONCE — copied from contract
            $table->string('service_category');                                 // CONSULTATION | PROCEDURE | LABORATORY | RADIOLOGY | PHARMACY | INPATIENT | OTHER

            $table->decimal('amount', 18, 4);                                   // Money::of() scale-2
            $table->string('currency')->default('TZS');

            $table->date('effective_from');
            $table->date('effective_to')->nullable();                           // EXCLUSIVE upper bound; NULL = open/current
            $table->string('status')->default('CURRENT');                       // CURRENT | SUPERSEDED | ENDED

            $table->uuid('created_by_user_id')->nullable();

            $table->timestampTz('occurred_at');
            $table->timestampTz('recorded_at');

            $table->unsignedBigInteger('version')->default(0);
            $table->timestampsTz();

            $table->index(['tenant_id', 'contract_id', 'service_category', 'effective_from']);
            $table->index(['tenant_id', 'payer_id', 'service_category']);
        });

        DB::statement(
            'create unique index ins_payer_tariffs_one_open_per_scope
                 on ins_payer_tariffs (tenant_id, contract_id, service_category)
              where effective_to is null'
        );

        Rls::enable('ins_payer_tariffs');
    }

    public function down(): void
    {
        Rls::disable('ins_payer_tariffs');
        Schema::dropIfExists('ins_payer_tariffs');
    }
};

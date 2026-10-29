<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 10 (increment Ins.6) — payer contracts
 * (21-insurance-and-payer-domain.md §49, §50, §112, §113, §159; ADR-203).
 *
 * Provider-Payer agreement defining the contractual relationship, effective
 * window, optional facility scope, and terms.
 *
 * Lifecycle: DRAFT → ACTIVE → TERMINATED | EXPIRED.
 * Mutable working record — Rls::enable only. NO events (ADR-137).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ins_payer_contracts', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();

            $table->string('contract_number');                                  // PCT-%08d
            $table->uuid('payer_id');                                           // SET-ONCE — no FK across tables
            $table->string('name');
            $table->string('reference')->nullable();                            // external reference / contract number (§49)
            $table->uuid('facility_id')->nullable();                            // NULL = all facilities in tenant; or specific facility (§49/§155)

            $table->string('status')->default('DRAFT');                         // DRAFT | ACTIVE | TERMINATED | EXPIRED
            $table->date('effective_from');
            $table->date('effective_to')->nullable();                           // NULL = indefinite

            $table->text('terms')->nullable();                                  // ROW-ONLY (ADR-136)
            $table->text('termination_reason')->nullable();                     // ROW-ONLY (ADR-136)

            $table->uuid('created_by_user_id');
            $table->uuid('activated_by_user_id')->nullable();
            $table->timestampTz('activated_at')->nullable();
            $table->uuid('terminated_by_user_id')->nullable();
            $table->timestampTz('terminated_at')->nullable();
            $table->timestampTz('expired_at')->nullable();

            $table->timestampTz('occurred_at');
            $table->timestampTz('recorded_at');

            $table->unsignedBigInteger('version')->default(0);
            $table->timestampsTz();

            $table->unique(['tenant_id', 'contract_number']);
            $table->index(['tenant_id', 'payer_id', 'status']);
        });

        Rls::enable('ins_payer_contracts');
    }

    public function down(): void
    {
        Rls::disable('ins_payer_contracts');
        Schema::dropIfExists('ins_payer_contracts');
    }
};

<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 10 (increment Ins.8) — claim adjudication line
 * (21-insurance-and-payer-domain.md §84–§87; ADR-205).
 *
 * Evaluated line item corresponding to a claim line on a submitted claim:
 *   - Status: APPROVED | DENIED | PENDED | REJECTED.
 *   - Enforces explicit breakdown of claimed vs approved vs patient responsibility
 *     vs contractual adjustment vs denied amount (Invariants 16, 17, 18, 19).
 *   - Arithmetic invariant: claimed = approved + patient_resp + contractual_adj + denied.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ins_adjudication_lines', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();

            $table->uuid('adjudication_id')->index();
            $table->uuid('claim_line_id')->index();
            $table->unsignedInteger('line_number');

            $table->string('status', 32);

            $table->decimal('claimed_amount', 18, 4);
            $table->decimal('approved_amount', 18, 4)->default(0);
            $table->decimal('denied_amount', 18, 4)->default(0);
            $table->decimal('patient_responsibility', 18, 4)->default(0);
            $table->decimal('contractual_adjustment', 18, 4)->default(0);

            $table->string('denial_reason_code', 64)->nullable();
            $table->string('remarks')->nullable();

            $table->timestampTz('occurred_at');
            $table->timestampTz('recorded_at');
            $table->unsignedBigInteger('version')->default(0);
            $table->timestampsTz();

            $table->unique(['tenant_id', 'adjudication_id', 'line_number']);
            $table->index(['tenant_id', 'claim_line_id']);
        });

        Rls::enable('ins_adjudication_lines');
    }

    public function down(): void
    {
        Rls::disable('ins_adjudication_lines');
        Schema::dropIfExists('ins_adjudication_lines');
    }
};

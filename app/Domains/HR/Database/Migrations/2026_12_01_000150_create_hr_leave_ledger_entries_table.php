<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 11 (increment HR.5) — append-only leave ledger entries table
 * (25-hr-and-workforce-domain.md §6, §7.9; ADR-206).
 *
 * The authoritative, immutable financial-grade ledger of all leave facts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_leave_ledger_entries', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();
            $table->uuid('staff_id')->index();
            $table->uuid('leave_type_id')->index();

            $table->string('kind', 32); // OPENING, ACCRUAL, TAKEN, ADJUSTMENT, CARRY_OVER, EXPIRY, ENCASHMENT
            $table->decimal('delta', 6, 2);
            $table->string('source_ref', 100)->nullable();
            $table->date('effective_date')->index();
            $table->text('reason')->nullable();
            $table->uuid('posted_by')->index();

            $table->unsignedBigInteger('version')->default(0);
            $table->timestampsTz();

            $table->foreign('staff_id')->references('id')->on('hr_staff')->cascadeOnDelete();
            $table->foreign('leave_type_id')->references('id')->on('hr_leave_types')->cascadeOnDelete();

            $table->index(
                ['tenant_id', 'staff_id', 'leave_type_id', 'effective_date'],
                'hr_leave_ledger_staff_type_date_idx'
            );
        });

        Rls::enable('hr_leave_ledger_entries');
    }

    public function down(): void
    {
        Rls::disable('hr_leave_ledger_entries');
        Schema::dropIfExists('hr_leave_ledger_entries');
    }
};

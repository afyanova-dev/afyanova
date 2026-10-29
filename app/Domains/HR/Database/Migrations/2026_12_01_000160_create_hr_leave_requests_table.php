<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 11 (increment HR.5) — leave requests table
 * (25-hr-and-workforce-domain.md §6, §7.9, §7.12; ADR-206).
 *
 * Operational lifecycle: REQUESTED -> APPROVED -> TAKEN (or REJECTED / CANCELLED).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_leave_requests', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();
            $table->uuid('facility_id')->index();
            $table->uuid('staff_id')->index();
            $table->uuid('leave_type_id')->index();

            $table->date('start_date')->index();
            $table->date('end_date')->index();
            $table->decimal('days', 6, 2);
            $table->string('status', 20)->default('REQUESTED'); // REQUESTED, APPROVED, REJECTED, CANCELLED, TAKEN

            $table->text('reason')->nullable();
            $table->uuid('reviewer_id')->nullable()->index();
            $table->timestampTz('reviewed_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->text('cancellation_reason')->nullable();

            $table->unsignedBigInteger('version')->default(0);
            $table->timestampsTz();

            $table->foreign('staff_id')->references('id')->on('hr_staff')->cascadeOnDelete();
            $table->foreign('leave_type_id')->references('id')->on('hr_leave_types')->cascadeOnDelete();

            $table->index(
                ['tenant_id', 'staff_id', 'start_date', 'end_date'],
                'hr_leave_requests_staff_date_idx'
            );
        });

        Rls::enable('hr_leave_requests');
    }

    public function down(): void
    {
        Rls::disable('hr_leave_requests');
        Schema::dropIfExists('hr_leave_requests');
    }
};

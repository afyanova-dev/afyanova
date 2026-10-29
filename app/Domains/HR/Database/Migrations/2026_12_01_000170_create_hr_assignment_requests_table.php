<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_assignment_requests', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();
            $table->uuid('staff_id')->index();
            $table->uuid('facility_id')->index();
            $table->uuid('department_id')->nullable()->index();
            $table->uuid('unit_id')->nullable()->index();
            $table->string('scope', 32);
            $table->date('effective_from');
            $table->uuid('requested_by');
            $table->string('status', 32)->default('PENDING');
            $table->uuid('decided_by')->nullable();
            $table->timestampTz('decided_at')->nullable();
            $table->text('decision_reason')->nullable();
            $table->timestampsTz();

            $table->foreign('staff_id')->references('id')->on('hr_staff')->cascadeOnDelete();
        });

        DB::statement(
            "CREATE UNIQUE INDEX hr_assignment_requests_one_pending_scope
             ON hr_assignment_requests (tenant_id, staff_id, facility_id)
             WHERE status = 'PENDING'"
        );

        Rls::enable('hr_assignment_requests');
    }

    public function down(): void
    {
        Rls::disable('hr_assignment_requests');
        DB::statement('DROP INDEX IF EXISTS hr_assignment_requests_one_pending_scope');
        Schema::dropIfExists('hr_assignment_requests');
    }
};

<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 11 (increment HR.1) — tenant-scoped staff-number counter
 * (ADR-115; ADR-206; domain 25 §2). `STF-%08d`.
 *
 * One row per tenant (`UNIQUE (tenant_id)`). Allocated by
 * App\Domains\HR\Infrastructure\Sequence\StaffNumberSequence
 * inside the `CreateStaff` command transaction.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_staff_number_counters', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();
            $table->bigInteger('next_value')->default(1);
            $table->unsignedBigInteger('version')->default(0);
            $table->timestampsTz();

            $table->unique('tenant_id');
        });

        Rls::enable('hr_staff_number_counters');
    }

    public function down(): void
    {
        Rls::disable('hr_staff_number_counters');
        Schema::dropIfExists('hr_staff_number_counters');
    }
};

<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 11 (increment HR.3) — shift patterns aggregate root
 * (25-hr-and-workforce-domain.md §6, §7; ADR-206).
 *
 * Defines repeatable shift templates (e.g. MORNING, NIGHT) scoped to a facility.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_shift_patterns', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();
            $table->uuid('facility_id')->index();

            $table->string('code', 50);
            $table->string('name', 150);
            $table->time('start_time');
            $table->time('end_time');
            $table->unsignedInteger('break_minutes')->default(0);
            $table->boolean('is_overnight')->default(false);
            $table->boolean('is_active')->default(true);

            $table->unsignedBigInteger('version')->default(0);
            $table->timestampsTz();

            $table->unique(['tenant_id', 'facility_id', 'code']);
        });

        Rls::enable('hr_shift_patterns');
    }

    public function down(): void
    {
        Rls::disable('hr_shift_patterns');
        Schema::dropIfExists('hr_shift_patterns');
    }
};

<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-scoped surgical-case-number counter (ADR-150 D-C — `SURG-%08d`, the
 * ADR-115 precedent, NOT the domain doc's own year-embedded example, which
 * §11 itself says is facility-configurable, not mandated).
 *
 * Same IC §10 tenant-only shape as clin_procedure_number_counters /
 * enc_encounter_number_counters — not part of the SurgicalCase aggregate.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('thr_case_number_counters', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();
            $table->bigInteger('next_value')->default(1);
            $table->unsignedBigInteger('version')->default(0);
            $table->timestampsTz();

            $table->unique('tenant_id');
        });

        Rls::enable('thr_case_number_counters');
    }

    public function down(): void
    {
        Rls::disable('thr_case_number_counters');
        Schema::dropIfExists('thr_case_number_counters');
    }
};

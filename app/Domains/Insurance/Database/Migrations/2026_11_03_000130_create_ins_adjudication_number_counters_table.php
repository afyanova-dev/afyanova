<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 10 (increment Ins.8) — tenant-scoped adjudication-number counter
 * (ADR-115; ADR-205; domain 21 §84). `ADJ-%08d`.
 *
 * One row per tenant (`UNIQUE (tenant_id)`). Allocated by
 * App\Domains\Insurance\Infrastructure\Sequence\AdjudicationNumberSequence
 * inside the `RecordClaimAdjudication` command transaction.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ins_adjudication_number_counters', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();
            $table->bigInteger('next_value')->default(1);
            $table->unsignedBigInteger('version')->default(0);
            $table->timestampsTz();

            $table->unique('tenant_id');
        });

        Rls::enable('ins_adjudication_number_counters');
    }

    public function down(): void
    {
        Rls::disable('ins_adjudication_number_counters');
        Schema::dropIfExists('ins_adjudication_number_counters');
    }
};

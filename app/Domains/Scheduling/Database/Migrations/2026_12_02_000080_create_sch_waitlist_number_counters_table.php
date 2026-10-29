<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 11 (increment SCH.4) — tenant-scoped waitlist number counter
 * (ADR-115; 22-scheduling-and-appointments-domain.md §24). `WTL-%08d`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sch_waitlist_number_counters', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();
            $table->bigInteger('next_value')->default(1);
            $table->unsignedBigInteger('version')->default(0);
            $table->timestampsTz();

            $table->unique('tenant_id');
        });

        Rls::enable('sch_waitlist_number_counters');
    }

    public function down(): void
    {
        Rls::disable('sch_waitlist_number_counters');
        Schema::dropIfExists('sch_waitlist_number_counters');
    }
};

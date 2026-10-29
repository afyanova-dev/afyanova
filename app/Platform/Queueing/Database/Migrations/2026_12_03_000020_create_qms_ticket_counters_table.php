<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 12 (Increment P12.1) — Queueing & Patient Flow Platform Service
 * ADR-115 compliant atomic daily ticket counters scoped by tenant, facility, queue code and date.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('qms_ticket_counters', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();
            $table->uuid('facility_id')->index();

            $table->string('queue_code', 50);
            $table->date('counter_date');
            $table->bigInteger('last_number')->default(0);

            $table->timestampsTz();

            $table->unique(
                ['tenant_id', 'facility_id', 'queue_code', 'counter_date'],
                'qms_ticket_counters_scope_unique'
            );
        });

        Rls::enable('qms_ticket_counters');
    }

    public function down(): void
    {
        Rls::disable('qms_ticket_counters');
        Schema::dropIfExists('qms_ticket_counters');
    }
};

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
        Schema::create('qms_procedure_queue_mappings', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();
            $table->uuid('facility_id')->index();
            $table->uuid('procedure_definition_id')->index();
            $table->uuid('queue_id')->index();
            $table->timestampsTz();

            $table->foreign('queue_id')->references('id')->on('qms_queues')->cascadeOnDelete();
            $table->unique(
                ['tenant_id', 'facility_id', 'procedure_definition_id'],
                'qms_proc_queue_map_facility_definition_unique',
            );
        });

        DB::statement(
            'create unique index qms_queue_entries_procedure_order_unique on qms_queue_entries (tenant_id, source_order_ref) '.
            "where order_type = 'PROCEDURE_BOOKING' and source_order_ref is not null"
        );

        Rls::enable('qms_procedure_queue_mappings');
    }

    public function down(): void
    {
        Rls::disable('qms_procedure_queue_mappings');
        Schema::dropIfExists('qms_procedure_queue_mappings');
        DB::statement('drop index if exists qms_queue_entries_procedure_order_unique');
    }
};

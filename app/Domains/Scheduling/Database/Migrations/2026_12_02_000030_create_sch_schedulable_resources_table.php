<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 11 (increment SCH.1) — schedulable resources table (ADR-111; 22-scheduling-and-appointments-domain.md §28).
 *
 * References external master records by ID (Provider -> HR staff, Room -> Organization room, Equipment -> Inventory).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sch_schedulable_resources', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();
            $table->uuid('facility_id')->index();

            $table->string('resource_type', 32); // PROVIDER, ROOM, EQUIPMENT
            $table->uuid('resource_ref')->index();
            $table->string('name', 255);
            $table->boolean('is_active')->default(true);

            $table->unsignedBigInteger('version')->default(0);
            $table->timestampsTz();

            $table->unique(['tenant_id', 'facility_id', 'resource_type', 'resource_ref'], 'sch_resources_tenant_ref_unique');
        });

        Rls::enable('sch_schedulable_resources');
    }

    public function down(): void
    {
        Rls::disable('sch_schedulable_resources');
        Schema::dropIfExists('sch_schedulable_resources');
    }
};

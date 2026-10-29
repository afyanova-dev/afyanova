<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Structural beds within wards/rooms (24-bed-and-ward-management-domain.md §6;
 * ADR-147 D-B, ADR-148 D-B).
 *
 * Tier 1 structural reference data: ward_id is denormalized directly onto the
 * bed to allow GetWardCensus and GetAvailableBeds queries without joining bw_rooms.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bw_beds', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');
            $table->uuid('ward_id');
            $table->uuid('room_id')->nullable();

            $table->string('label');
            $table->string('bed_class');
            $table->jsonb('features')->default('[]');
            $table->string('status')->default('AVAILABLE');

            $table->unsignedBigInteger('version')->default(0);
            $table->timestampsTz();

            $table->unique(['tenant_id', 'facility_id', 'ward_id', 'label']);
            $table->index(['tenant_id', 'facility_id', 'status']);
            $table->index(['tenant_id', 'ward_id', 'status']);
            $table->index(['tenant_id', 'room_id']);
        });

        Rls::enable('bw_beds');
    }

    public function down(): void
    {
        Rls::disable('bw_beds');
        Schema::dropIfExists('bw_beds');
    }
};

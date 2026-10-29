<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Structural rooms within wards (24-bed-and-ward-management-domain.md §6;
 * ADR-147 D-B, ADR-148 D-B).
 *
 * Tier 1 structural reference data: child of Ward, plain Eloquent model.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bw_rooms', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');
            $table->uuid('ward_id');

            $table->string('name');
            $table->string('room_type');
            $table->boolean('isolation_capable')->default(false);

            $table->unsignedBigInteger('version')->default(0);
            $table->timestampsTz();

            $table->index(['tenant_id', 'facility_id', 'ward_id']);
        });

        Rls::enable('bw_rooms');
    }

    public function down(): void
    {
        Rls::disable('bw_rooms');
        Schema::dropIfExists('bw_rooms');
    }
};

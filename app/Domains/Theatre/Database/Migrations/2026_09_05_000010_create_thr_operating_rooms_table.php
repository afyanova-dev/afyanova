<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Operating rooms as an operational resource (17-theatre-domain.md §16-19;
 * ADR-150 D-C).
 *
 * `org_room_id` references the EXISTING Organization room master by plain
 * uuid (ADR-111, ADR-150 D-C) — Theatre references, it does not duplicate
 * (the deliberate difference from Bed & Ward's own independent structure).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('thr_operating_rooms', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');
            $table->uuid('org_room_id');

            $table->string('label');
            $table->string('status')->default('AVAILABLE');

            $table->unsignedBigInteger('version')->default(0);
            $table->timestampsTz();

            $table->unique(['tenant_id', 'facility_id', 'label']);
            $table->index(['tenant_id', 'facility_id', 'status']);
            $table->index(['tenant_id', 'org_room_id']);
        });

        Rls::enable('thr_operating_rooms');
    }

    public function down(): void
    {
        Rls::disable('thr_operating_rooms');
        Schema::dropIfExists('thr_operating_rooms');
    }
};

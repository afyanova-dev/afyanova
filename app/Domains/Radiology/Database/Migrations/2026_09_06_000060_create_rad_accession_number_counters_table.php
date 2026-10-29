<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-scoped imaging-accession-number counter (ADR-166 D-C — `RADAC-%08d`,
 * the ADR-115 / `rad_order_number_counters` / `lab_accession_number_counters`
 * precedent, same class shape).
 *
 * The accession number is the radiology STUDY processing identity, distinct
 * from the `RAD-%08d` order number (§29). Same IC §10 tenant-only shape — not
 * part of the `ImagingStudy` aggregate. Written only by
 * `Infrastructure/Sequence/AccessionNumberSequence`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rad_accession_number_counters', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();
            $table->bigInteger('next_value')->default(1);
            $table->unsignedBigInteger('version')->default(0);
            $table->timestampsTz();

            $table->unique('tenant_id');
        });

        Rls::enable('rad_accession_number_counters');
    }

    public function down(): void
    {
        Rls::disable('rad_accession_number_counters');
        Schema::dropIfExists('rad_accession_number_counters');
    }
};

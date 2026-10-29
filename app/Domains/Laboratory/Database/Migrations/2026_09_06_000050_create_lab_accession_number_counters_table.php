<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-scoped laboratory-accession-number counter (ADR-158 D-D — `LABSP-%08d`,
 * the `lab_order_number_counters` / ADR-115 precedent, same class shape).
 *
 * The accession number is the laboratory PROCESSING identity, distinct from the
 * order number which is the REQUEST identity (§32–§33). Written only by
 * `Infrastructure/Sequence/AccessionNumberSequence`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lab_accession_number_counters', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();
            $table->bigInteger('next_value')->default(1);
            $table->unsignedBigInteger('version')->default(0);
            $table->timestampsTz();

            $table->unique('tenant_id');
        });

        Rls::enable('lab_accession_number_counters');
    }

    public function down(): void
    {
        Rls::disable('lab_accession_number_counters');
        Schema::dropIfExists('lab_accession_number_counters');
    }
};

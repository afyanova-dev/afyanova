<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lab_qc_runs', function (Blueprint $table): void {
            $table->foreignUuid('instrument_id')
                ->nullable()
                ->constrained('lab_facility_instruments')
                ->restrictOnDelete();
            $table->index(['tenant_id', 'facility_id', 'instrument_id'], 'lab_qc_runs_instrument_index');
        });
    }

    public function down(): void
    {
        Schema::table('lab_qc_runs', function (Blueprint $table): void {
            $table->dropIndex('lab_qc_runs_instrument_index');
            $table->dropConstrainedForeignId('instrument_id');
        });
    }
};

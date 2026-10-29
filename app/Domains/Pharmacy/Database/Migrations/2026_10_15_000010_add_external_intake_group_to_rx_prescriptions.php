<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rx_prescriptions', function (Blueprint $table): void {
            $table->uuid('external_intake_id')->nullable();
            $table->index(['tenant_id', 'facility_id', 'external_intake_id'], 'rx_prescriptions_external_intake_idx');
        });
    }

    public function down(): void
    {
        Schema::table('rx_prescriptions', function (Blueprint $table): void {
            $table->dropIndex('rx_prescriptions_external_intake_idx');
            $table->dropColumn('external_intake_id');
        });
    }
};

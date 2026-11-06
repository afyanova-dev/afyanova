<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('qms_patient_movements', function (Blueprint $table): void {
            $table->timestampTz('occurred_at', precision: 6)->change();
        });
    }

    public function down(): void
    {
        Schema::table('qms_patient_movements', function (Blueprint $table): void {
            $table->timestampTz('occurred_at', precision: 0)->change();
        });
    }
};

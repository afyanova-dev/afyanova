<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pcr_suppliers', function (Blueprint $table): void {
            $table->string('licence_number', 100)->nullable();
            $table->string('licence_authority', 200)->nullable();
            $table->date('licence_expiry_date')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('pcr_suppliers', function (Blueprint $table): void {
            $table->dropColumn(['licence_number', 'licence_authority', 'licence_expiry_date']);
        });
    }
};

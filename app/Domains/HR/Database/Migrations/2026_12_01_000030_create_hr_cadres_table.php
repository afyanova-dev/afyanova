<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 11 (increment HR.1) — professional cadre classifications
 * (25-hr-and-workforce-domain.md §4, §6; ADR-206).
 *
 * Defines professional categories: CLINICAL, NURSING, ALLIED, PHARMACY,
 * LABORATORY, ADMIN, SUPPORT.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_cadres', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();

            $table->string('code', 50);
            $table->string('name', 150);
            $table->string('category', 50);
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);

            $table->unsignedBigInteger('version')->default(0);
            $table->timestampsTz();

            $table->unique(['tenant_id', 'code']);
        });

        Rls::enable('hr_cadres');
    }

    public function down(): void
    {
        Rls::disable('hr_cadres');
        Schema::dropIfExists('hr_cadres');
    }
};

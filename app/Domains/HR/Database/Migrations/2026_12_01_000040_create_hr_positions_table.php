<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 11 (increment HR.1) — organisational job positions
 * (25-hr-and-workforce-domain.md §4, §6; ADR-206).
 *
 * Distinct from Cadre: Position defines the organizational job slot referencing a Cadre.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_positions', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();

            $table->string('code', 50);
            $table->string('title', 150);
            $table->uuid('cadre_id')->index();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);

            $table->unsignedBigInteger('version')->default(0);
            $table->timestampsTz();

            $table->unique(['tenant_id', 'code']);
            $table->foreign('cadre_id')->references('id')->on('hr_cadres')->cascadeOnDelete();
        });

        Rls::enable('hr_positions');
    }

    public function down(): void
    {
        Rls::disable('hr_positions');
        Schema::dropIfExists('hr_positions');
    }
};

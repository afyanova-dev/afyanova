<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Laboratory test panels (14-laboratory-domain.md §14–§15, §192; ADR-162 D-C).
 * A panel is a named bundle of tests ordered as one unit ("CBC Panel", "Liver
 * Function Panel"). Distinct from an individual test (§15 — billing / specimen
 * requirements may differ).
 *
 * Same shape as `lab_test_definitions` — tenant-scoped, mutable,
 * integer-versioned catalog reference data, authored ONLY by the
 * `laboratory:define-panel` console command (ADR-162 D-C — no HTTP command, no
 * permission key; full catalog governance deferred to "Lab.0"). Members live on
 * `lab_panel_members`.
 *
 * `code` is tenant-unique among ACTIVE rows only (a retired code may be reused).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lab_panels', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();

            $table->string('code');
            $table->string('name');
            $table->string('category')->nullable();
            $table->text('description')->nullable();

            $table->boolean('active')->default(true);
            $table->unsignedInteger('version')->default(1);

            $table->timestampsTz();
        });

        DB::statement(
            'create unique index lab_panels_tenant_code_active_unique '.
            'on lab_panels (tenant_id, code) where active'
        );

        Rls::enable('lab_panels');
    }

    public function down(): void
    {
        Rls::disable('lab_panels');
        Schema::dropIfExists('lab_panels');
    }
};

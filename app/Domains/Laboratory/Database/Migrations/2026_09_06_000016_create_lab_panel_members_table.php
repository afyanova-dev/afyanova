<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The tests that make up a laboratory panel (14-laboratory-domain.md §14;
 * ADR-162 D-C). Plain child of `lab_panels` — NOT an AggregateRoot (no event;
 * the `lab_order_items` precedent). Both FKs are intra-`lab_`.
 *
 * `version` is shape/conformance-only (ConformanceCommand check 3b) — stays 0
 * (the standing `governance-rulings` interpretation).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lab_panel_members', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');

            $table->foreignUuid('panel_id')->constrained('lab_panels');
            $table->foreignUuid('test_definition_id')->constrained('lab_test_definitions');
            $table->unsignedInteger('sequence');

            $table->unsignedBigInteger('version')->default(0);
            $table->timestampsTz();

            $table->unique(['tenant_id', 'panel_id', 'test_definition_id']);
            $table->index(['tenant_id', 'panel_id']);
        });

        Rls::enable('lab_panel_members');
    }

    public function down(): void
    {
        Rls::disable('lab_panel_members');
        Schema::dropIfExists('lab_panel_members');
    }
};

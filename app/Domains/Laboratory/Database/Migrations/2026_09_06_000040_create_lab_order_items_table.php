<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The individual tests requested on a laboratory order
 * (14-laboratory-domain.md §13, §21, §148–§149; ADR-157 D-D).
 *
 * Plain Eloquent child model — NOT an AggregateRoot (the domain doc names no
 * order-item event; §149 says the Lab Order aggregate "should not contain all
 * specimen/result internals" — the item is the ordered-test line, the
 * `thr_case_team_assignments` precedent). Written directly by
 * `CreateLabOrderHandler` in the order's transaction.
 *
 * `test_code` / `test_name` / `test_definition_version` are SNAPSHOTS taken from
 * the `lab_test_definitions` row at order time (§17 — a historical order line
 * must not silently change when the catalog entry is later revised).
 * `test_definition_id` is an intra-`lab_` FK (`->constrained('lab_test_definitions')`).
 *
 * `version` is present for schema/conformance shape only (ConformanceCommand
 * check 3b — every tenant-scoped table carries `version` unless SHAPE_EXEMPT);
 * it stays 0 for the row's life and is NOT optimistic concurrency on the row
 * (the standing `governance-rulings` interpretation — `clin_note_amendments` /
 * `thr_case_team_assignments` precedent).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lab_order_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');

            $table->foreignUuid('order_id')->constrained('lab_orders');
            $table->foreignUuid('test_definition_id')->constrained('lab_test_definitions');

            $table->string('test_code');                  // snapshot of lab_test_definitions.code at order time
            $table->string('test_name');                  // snapshot of lab_test_definitions.name at order time
            $table->unsignedInteger('test_definition_version'); // snapshot of lab_test_definitions.version (§17)

            $table->string('status')->default('ORDERED'); // Lab.1: ORDERED | CANCELLED; specimen/result increments extend
            $table->uuid('ordered_via_panel_id')->nullable(); // ADR-162 (Lab.4) — set when the item came from a panel expansion; no FK

            $table->unsignedBigInteger('version')->default(0); // shape/conformance-only (governance-rulings)
            $table->timestampsTz();

            $table->index(['tenant_id', 'order_id']);
        });

        Rls::enable('lab_order_items');
    }

    public function down(): void
    {
        Rls::disable('lab_order_items');
        Schema::dropIfExists('lab_order_items');
    }
};

<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The individual imaging services requested on an imaging order
 * (15-radiology-domain.md §18–§19, §106, §181; ADR-165 D-E).
 *
 * Plain Eloquent child model — NOT an AggregateRoot (the domain doc names no
 * order-item event; §181 — the `lab_order_items` / `thr_case_team_assignments`
 * precedent). Written directly by `CreateImagingOrderHandler` in the order's
 * transaction.
 *
 * `service_code` / `service_name` / `service_definition_version` /
 * `modality_code` are SNAPSHOTS taken from the `rad_imaging_services` (and its
 * parent `rad_modalities`) rows at order time (§17 analogue — a historical order
 * line must not silently change when the catalog entry is later revised).
 * `body_site` / `laterality` snapshot the service defaults; per-line overrides
 * are a documented Rad.2 refinement (documentation-only in Rad.1).
 * `imaging_service_id` is an intra-`rad_` FK.
 *
 * `version` is present for schema/conformance shape only (ConformanceCommand
 * check 3b); it stays 0 for the row's life and is NOT optimistic concurrency
 * (the standing `governance-rulings` interpretation — `lab_order_items` /
 * `clin_note_amendments` precedent).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rad_imaging_order_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');

            $table->foreignUuid('order_id')->constrained('rad_imaging_orders');
            $table->foreignUuid('imaging_service_id')->constrained('rad_imaging_services');

            $table->string('service_code');                     // snapshot of rad_imaging_services.code at order time
            $table->string('service_name');                     // snapshot of rad_imaging_services.name at order time
            $table->unsignedInteger('service_definition_version'); // snapshot of rad_imaging_services.version
            $table->string('modality_code');                    // snapshot of the parent rad_modalities.code

            $table->string('body_site')->nullable();            // snapshot of the service default (§14)
            $table->string('laterality')->nullable();           // snapshot of the service default (§15)

            $table->string('status')->default('ORDERED'); // Rad.1: ORDERED | CANCELLED; study/report increments extend

            $table->unsignedBigInteger('version')->default(0); // shape/conformance-only (governance-rulings)
            $table->timestampsTz();

            $table->index(['tenant_id', 'order_id']);
        });

        Rls::enable('rad_imaging_order_items');
    }

    public function down(): void
    {
        Rls::disable('rad_imaging_order_items');
        Schema::dropIfExists('rad_imaging_order_items');
    }
};

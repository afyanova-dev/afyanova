<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 9 (increment Proc.1a) — the append-only purchase-order amendment log
 * (19-procurement-domain.md §67, §68, §231, §232; ADR-171 D-I).
 *
 * One row per controlled post-issuance change (§67 "changes after issuance
 * must be controlled") — the `lab_result_amendments` /
 * `thr_operative_note_amendments` / `rad_report_amendments` pattern EXACTLY:
 * a plain append-only child, NOT an AggregateRoot (the `PurchaseOrder`
 * aggregate records `PurchaseOrderAmended`; this table records the before/after).
 * `Rls::enable` + `Rls::enableAppendOnly` (RESTRICTIVE — no UPDATE / DELETE;
 * a correction is a further amendment). `version` is nominal — stays 0 (the
 * standing `governance-rulings` interpretation).
 *
 * `reason` is free text — ROW-ONLY + on the audit row, NEVER in the event
 * (the ADR-136 deny-list).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pcr_po_amendments', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();
            $table->uuid('facility_id');

            $table->foreignUuid('po_id')->constrained('pcr_purchase_orders');
            $table->unsignedInteger('sequence');
            $table->string('amendment_type');                          // §67 — open-coded
            $table->foreignUuid('po_line_id')->nullable()->constrained('pcr_po_lines'); // line-level changes

            $table->string('field_changed');
            $table->text('previous_value')->nullable();
            $table->text('new_value')->nullable();
            $table->text('reason');                                    // ROW-ONLY + audit (ADR-136)

            $table->uuid('amended_by_user_id');
            $table->timestampTz('amended_at');

            $table->unsignedBigInteger('version')->default(0);         // nominal, stays 0
            $table->timestampTz('created_at')->nullable();

            $table->unique(['tenant_id', 'po_id', 'sequence']);
            $table->index(['tenant_id', 'po_id']);
        });

        Rls::enable('pcr_po_amendments');
        Rls::enableAppendOnly('pcr_po_amendments');
    }

    public function down(): void
    {
        Rls::disable('pcr_po_amendments');
        Schema::dropIfExists('pcr_po_amendments');
    }
};

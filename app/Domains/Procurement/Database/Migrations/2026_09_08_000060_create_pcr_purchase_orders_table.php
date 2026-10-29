<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 9 (increment Proc.1a) — the Purchase Order
 * (19-procurement-domain.md §43–§51, §67, §73, §247; ADR-171 D-G). A formal
 * procurement commitment — NOT proof of physical receipt (Invariant 10).
 * AggregateRoot, the ADR-132 context+time shape.
 *
 * §44 lifecycle FRONT only: DRAFT → SUBMITTED → APPROVED → ISSUED (+ CANCELLED).
 * ACKNOWLEDGED + the receipt tail (PARTIALLY_RECEIVED → FULLY_RECEIVED → CLOSED)
 * arrive with Proc.1b's `OnStockReceived` listener. DRAFT is immutable
 * (cancel + recreate — no edit command).
 *
 * `requisition_id` is OPTIONAL (§17 / §32 / §43 — a direct PO is valid); when
 * set the requisition must be APPROVED + same facility. Single currency (TZS);
 * `facility_id` = the one delivery facility (§48 multi-facility central PO
 * deferred). `notes` / `cancellation_reason` / `emergency_reason` are ROW-ONLY.
 * `received_qty` on the lines is declared for Proc.1b and stays 0 here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pcr_purchase_orders', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();
            $table->uuid('facility_id')->index();               // the delivery facility

            $table->string('po_number');                        // PO-%08d
            $table->foreignUuid('supplier_id')->constrained('pcr_suppliers');
            $table->foreignUuid('requisition_id')->nullable()->constrained('pcr_purchase_requisitions');

            $table->string('currency')->default('TZS');         // single-currency in Proc.1a
            $table->string('priority')->default('ROUTINE');     // §79 — open-coded
            $table->boolean('emergency')->default(false);
            $table->text('emergency_reason')->nullable();       // ROW-ONLY, required when emergency

            $table->string('status')->default('DRAFT');         // §44 — open-coded
            $table->text('notes')->nullable();                  // ROW-ONLY

            $table->uuid('approved_by_user_id')->nullable();
            $table->timestampTz('approved_at')->nullable();
            $table->uuid('issued_by_user_id')->nullable();
            $table->timestampTz('issued_at')->nullable();
            $table->text('cancellation_reason')->nullable();    // ROW-ONLY
            $table->unsignedInteger('amendment_count')->default(0); // dirty-the-row so Amend bumps version (T.2)

            $table->uuid('created_by_user_id');
            $table->timestampTz('occurred_at');
            $table->timestampTz('recorded_at');

            $table->unsignedBigInteger('version')->default(0);
            $table->timestampsTz();

            $table->unique(['tenant_id', 'po_number']);
            $table->index(['tenant_id', 'facility_id', 'status']);
            $table->index(['tenant_id', 'supplier_id']);
        });

        Rls::enable('pcr_purchase_orders');
    }

    public function down(): void
    {
        Rls::disable('pcr_purchase_orders');
        Schema::dropIfExists('pcr_purchase_orders');
    }
};

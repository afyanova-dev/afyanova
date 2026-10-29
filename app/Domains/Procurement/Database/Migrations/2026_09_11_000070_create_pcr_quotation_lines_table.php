<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 9 (increment Proc.2a) — one line on a supplier quotation
 * (19-procurement-domain.md §32, §34, §39; ADR-173 D-C).
 *
 * Plain Eloquent child — NOT an AggregateRoot. Each line cites an `rfq_line_id`
 * (a supplier need not quote every line). `unit_price` is `Money` (scale 2);
 * `offered_qty` is `Quantity` (scale 4) — MAY be < the RFQ line quantity → §39
 * partial. **NO stored `line_total`** — `unit_price * offered_qty (+ tax −
 * discount)` is computed in the reader / comparison (the Inv.1 `available_qty`
 * / ADR-171 F13 lesson). `line_note` is ROW-ONLY. `version` shape-only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pcr_quotation_lines', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();
            $table->uuid('facility_id');

            $table->foreignUuid('quotation_id')->constrained('pcr_quotations');
            $table->foreignUuid('rfq_line_id')->constrained('pcr_rfq_lines');
            $table->unsignedInteger('line_no');

            $table->decimal('unit_price', 18, 2);              // Money
            $table->decimal('offered_qty', 18, 4);             // Quantity — may be partial (§39)
            $table->decimal('tax_amount', 18, 2)->nullable();
            $table->decimal('discount_amount', 18, 2)->nullable();
            $table->text('line_note')->nullable();             // ROW-ONLY

            $table->unsignedBigInteger('version')->default(0); // shape/conformance-only
            $table->timestampsTz();

            $table->unique(['tenant_id', 'quotation_id', 'rfq_line_id']);
            $table->index(['tenant_id', 'quotation_id']);
        });

        DB::statement('alter table pcr_quotation_lines add constraint pcr_quotation_lines_unit_price_nonneg check (unit_price >= 0)');
        DB::statement('alter table pcr_quotation_lines add constraint pcr_quotation_lines_offered_qty_positive check (offered_qty > 0)');

        Rls::enable('pcr_quotation_lines');
    }

    public function down(): void
    {
        Rls::disable('pcr_quotation_lines');
        Schema::dropIfExists('pcr_quotation_lines');
    }
};

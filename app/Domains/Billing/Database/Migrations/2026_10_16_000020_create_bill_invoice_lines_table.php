<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 10 (increment Bill.4) — an invoice line: one POSTED charge on one
 * invoice (20-billing-and-payments-domain.md §35, §88, §235; ADR-190 D-B).
 *
 * ADR-190 INV-3: one LIVE line per charge — `unique (tenant_id, charge_id) WHERE
 * line_active = true`. A live line CLAIMS a charge; voiding an invoice (or
 * `RemoveInvoiceLine` on a DRAFT) sets `line_active = false`, releasing the
 * charge for future invoicing. The historical row is PRESERVED (INV-7 / §136) —
 * NOT DB-append-only (void must be able to flip `line_active`). ADR-190 INV-2:
 * `bill_charges` is never touched; the line SNAPSHOTS the charge's
 * description / quantity / unit price / amount and keeps `charge_id`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bill_invoice_lines', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();

            $table->uuid('invoice_id');
            $table->uuid('charge_id');                          // a bill_charges id — plain uuid, NO FK, NOT loaded via its repo

            $table->string('description');                      // the billing-service name, re-read at line creation
            $table->string('billing_service_code');             // snapshot from the ChargeSummary
            $table->decimal('quantity', 18, 4);
            $table->decimal('unit_price', 18, 4);
            $table->decimal('amount', 18, 4);

            $table->boolean('line_active')->default(true);

            $table->timestampTz('occurred_at');
            $table->timestampTz('recorded_at');
            $table->unsignedBigInteger('version')->default(0);
            $table->timestampsTz();

            $table->index(['tenant_id', 'invoice_id']);
        });

        // ADR-190 INV-3 — one live line per charge.
        DB::statement(
            'create unique index bill_invoice_lines_one_live_per_charge
                 on bill_invoice_lines (tenant_id, charge_id)
              where line_active = true'
        );

        Rls::enable('bill_invoice_lines');
    }

    public function down(): void
    {
        Rls::disable('bill_invoice_lines');
        Schema::dropIfExists('bill_invoice_lines');
    }
};

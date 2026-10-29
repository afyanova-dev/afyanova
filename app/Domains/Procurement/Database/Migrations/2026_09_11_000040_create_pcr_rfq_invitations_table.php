<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 9 (increment Proc.2a) — one supplier invited to quote on an RFQ
 * (19-procurement-domain.md §30, §33, §245; ADR-173 D-B).
 *
 * Plain Eloquent child — NOT an AggregateRoot. Written by `CreateRfqHandler`
 * (invitations are fixed at RFQ create in Proc.2a — no `InviteSupplier` command;
 * cancel + recreate to change the supplier list). The invited supplier must be
 * ACTIVE (`Supplier::isOrderable()`) at create time. A quotation is accepted
 * ONLY from an invited supplier (`SupplierNotInvited`). `version` shape-only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pcr_rfq_invitations', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();
            $table->uuid('facility_id');

            $table->foreignUuid('rfq_id')->constrained('pcr_rfqs');
            $table->foreignUuid('supplier_id')->constrained('pcr_suppliers');
            $table->timestampTz('invited_at');

            $table->unsignedBigInteger('version')->default(0);  // shape/conformance-only
            $table->timestampsTz();

            $table->unique(['tenant_id', 'rfq_id', 'supplier_id']);
            $table->index(['tenant_id', 'rfq_id']);
        });

        Rls::enable('pcr_rfq_invitations');
    }

    public function down(): void
    {
        Rls::disable('pcr_rfq_invitations');
        Schema::dropIfExists('pcr_rfq_invitations');
    }
};

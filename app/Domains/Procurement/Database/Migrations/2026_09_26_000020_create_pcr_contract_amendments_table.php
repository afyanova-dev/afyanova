<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 9 (increment Proc.3c) — the append-only contract-amendment log
 * (19-procurement-domain.md §195, §248; ADR-178 D-B).
 *
 * One row per controlled in-place change to an ACTIVE contract (`AmendContract`)
 * — the `pcr_po_amendments` / `rad_report_amendments` pattern EXACTLY: a plain
 * append-only child, NOT an AggregateRoot (the `Contract` aggregate bumps
 * `amendment_count` / `version`; this table records the before/after).
 * `Rls::enable` + `Rls::enableAppendOnly` (RESTRICTIVE — no UPDATE / DELETE; a
 * correction is a further amendment). `version` is nominal — stays 0.
 *
 * `reason` is free text — ROW-ONLY + on the audit row, NEVER in an event
 * (the ADR-136 deny-list). Tenant-wide (the `pcr_contracts` model — no
 * `facility_id`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pcr_contract_amendments', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();

            $table->foreignUuid('contract_id')->constrained('pcr_contracts');
            $table->unsignedInteger('sequence');
            $table->string('amendment_type');                                    // CEILING | EXPIRY | TERMS | TITLE | LINE_ADDED
            $table->foreignUuid('contract_line_id')->nullable()->constrained('pcr_contract_lines');

            $table->string('field_changed');
            $table->text('previous_value')->nullable();
            $table->text('new_value')->nullable();
            $table->text('reason');                                              // ROW-ONLY + audit (ADR-136)

            $table->uuid('amended_by_user_id');
            $table->timestampTz('amended_at');

            $table->unsignedBigInteger('version')->default(0);                   // nominal, stays 0
            $table->timestampTz('created_at')->nullable();

            $table->unique(['tenant_id', 'contract_id', 'sequence']);
            $table->index(['tenant_id', 'contract_id']);
        });

        Rls::enable('pcr_contract_amendments');
        Rls::enableAppendOnly('pcr_contract_amendments');
    }

    public function down(): void
    {
        Rls::disable('pcr_contract_amendments');
        Schema::dropIfExists('pcr_contract_amendments');
    }
};

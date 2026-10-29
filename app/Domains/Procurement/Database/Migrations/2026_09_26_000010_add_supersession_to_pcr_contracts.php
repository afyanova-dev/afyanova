<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 9 (increment Proc.3c) — the contract version chain
 * (19-procurement-domain.md §195, §248, §303 Invariant 31; ADR-178 D-B — **amends
 * ADR-176 D-B by pointer**).
 *
 * `RenewContract` mints a new `pcr_contracts` row whose `supersedes_contract_id`
 * points at the contract it replaces; the old row flips to `SUPERSEDED`
 * (`superseded_at` / `superseded_by_user_id`). `amendment_count` mirrors
 * `pcr_purchase_orders.amendment_count` — `AmendContract` bumps it so the row is
 * always dirty and `version` advances (the T.2 lesson). All nullable / default —
 * NULL on every pre-Proc.3c row, no backfill.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pcr_contracts', function (Blueprint $table): void {
            $table->foreignUuid('supersedes_contract_id')->nullable()->after('status')->constrained('pcr_contracts');
            $table->timestampTz('superseded_at')->nullable()->after('supersedes_contract_id');
            $table->uuid('superseded_by_user_id')->nullable()->after('superseded_at');
            $table->unsignedInteger('amendment_count')->default(0)->after('superseded_by_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('pcr_contracts', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('supersedes_contract_id');
            $table->dropColumn(['superseded_at', 'superseded_by_user_id', 'amendment_count']);
        });
    }
};

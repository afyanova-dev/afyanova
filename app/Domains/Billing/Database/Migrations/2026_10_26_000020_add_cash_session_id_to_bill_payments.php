<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 10 (increment Bill.6) — attribute a payment to the cash session it was
 * collected in (20-billing-and-payments-domain.md §151 / §153; ADR-191 C6-D /
 * G-4; amends ADR-193 D-A by pointer — additive).
 *
 * Nullable: a non-cash payment recorded without an open session, or any
 * Bill.5b payment, carries no session. `method = CASH` REQUIRES an open
 * session (the `NoCashSessionOpen` handler rule — C6-D). A cash session's
 * `expected_cash` sums the CASH payments carrying its id; non-cash payments
 * are attributed for the per-method panel but excluded from the cash count.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bill_payments', function (Blueprint $table): void {
            $table->uuid('cash_session_id')->nullable()->after('collected_by_user_id');
            $table->index(['tenant_id', 'cash_session_id']);
        });
    }

    public function down(): void
    {
        Schema::table('bill_payments', function (Blueprint $table): void {
            $table->dropIndex(['tenant_id', 'cash_session_id']);
            $table->dropColumn('cash_session_id');
        });
    }
};

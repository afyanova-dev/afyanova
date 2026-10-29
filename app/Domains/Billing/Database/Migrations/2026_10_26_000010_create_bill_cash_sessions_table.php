<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 10 (increment Bill.6) — a cashier's till session
 * (20-billing-and-payments-domain.md §153–§157; ADR-191 C6-A…C6-D / G-4).
 *
 * A cash session answers "how much physical cash is in this drawer" — it posts
 * NOTHING to `bill_account_ledger_entries` (G-4). `OPEN → ACTIVE → CLOSED →
 * RECONCILED`. `expected_cash` / `counted_cash` / `variance` are FROZEN at
 * close (§155 / §156). The variance + its §157 approval live here, NEVER on
 * the account ledger (C6-C). ONE live session per (cashier, facility) — the
 * partial unique index (C6-A). Mutable — `Rls::enable`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bill_cash_sessions', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();
            $table->uuid('facility_id');                        // the till's facility (the collection point)
            $table->uuid('cashier_user_id');

            $table->decimal('opening_float', 18, 4);
            $table->string('currency')->default('TZS');
            $table->string('status')->default('OPEN');          // OPEN | ACTIVE | CLOSED | RECONCILED

            $table->decimal('expected_cash', 18, 4)->nullable();  // FROZEN at close
            $table->decimal('counted_cash', 18, 4)->nullable();   // entered at close
            $table->decimal('variance', 18, 4)->nullable();       // counted − expected; FROZEN at close

            $table->timestampTz('opened_at');
            $table->uuid('opened_by_user_id');
            $table->timestampTz('closed_at')->nullable();
            $table->uuid('closed_by_user_id')->nullable();
            $table->string('close_note')->nullable();
            $table->timestampTz('reconciled_at')->nullable();
            $table->uuid('reconciled_by_user_id')->nullable();
            $table->uuid('variance_approved_by_user_id')->nullable();  // §157 — distinct from the cashier
            $table->string('variance_note')->nullable();

            $table->timestampTz('occurred_at');
            $table->timestampTz('recorded_at');
            $table->unsignedBigInteger('version')->default(0);
            $table->timestampsTz();

            $table->index(['tenant_id', 'facility_id', 'status']);
        });

        // ADR-191 C6-A — one live session per (cashier, facility).
        DB::statement(
            "create unique index bill_cash_sessions_one_live_per_cashier
                 on bill_cash_sessions (tenant_id, cashier_user_id, facility_id)
              where status in ('OPEN', 'ACTIVE')"
        );

        Rls::enable('bill_cash_sessions');
    }

    public function down(): void
    {
        Rls::disable('bill_cash_sessions');
        Schema::dropIfExists('bill_cash_sessions');
    }
};

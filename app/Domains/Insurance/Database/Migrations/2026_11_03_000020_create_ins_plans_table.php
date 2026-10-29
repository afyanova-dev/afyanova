<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 10 (increment Ins.1) — the payer-plan master
 * (21-insurance-and-payer-domain.md §11, §12, §160; ADR-198 D-H).
 *
 * A specific coverage product offered by a payer — distinct from the payer
 * (§11) and from a patient's membership (§13 — Ins.2). `payer_id` is a plain
 * reference (NO DB foreign key — the Billing precedent; verified in the
 * handler). `code` is SET-ONCE and unique per payer. `effective_from` /
 * `effective_to` are STORED but not enforced in Ins.1 — Ins.2 gates member
 * coverage to the window. Benefit configuration / coverage rules (§12) are
 * Ins.3. Mutable working record — `Rls::enable` only. NO events.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ins_plans', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();
            $table->uuid('payer_id');                       // the owning ins_payers row (same tenant) — no FK

            $table->string('code');                        // SET-ONCE — unique per payer
            $table->string('name');
            $table->string('status')->default('ACTIVE');   // ACTIVE | INACTIVE
            $table->string('status_reason')->nullable();

            $table->date('effective_from')->nullable();    // §12 — stored, not enforced in Ins.1
            $table->date('effective_to')->nullable();

            $table->uuid('created_by_user_id')->nullable();

            $table->timestampTz('occurred_at');
            $table->timestampTz('recorded_at');

            $table->unsignedBigInteger('version')->default(0);
            $table->timestampsTz();

            $table->unique(['tenant_id', 'payer_id', 'code']);   // ADR-198 D-H
            $table->index(['tenant_id', 'payer_id', 'status']);
        });

        Rls::enable('ins_plans');
    }

    public function down(): void
    {
        Rls::disable('ins_plans');
        Schema::dropIfExists('ins_plans');
    }
};

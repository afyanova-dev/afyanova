<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 6 (increment C.10 — ADR-183) — Structured Prescribed Quantity on
 * PrescribingDecision (12-clinical-domain.md §34–§39, §46; 16-pharmacy-domain.md
 * §46, §111–§113; ADR-183 D-B — **amends ADR-143 D-I / D-L by pointer**).
 *
 * ADR-143 D-I stored the prescribed quantity as free text only (`quantity`).
 * §111 Invariant 5 / §112 need a machine-usable number and its arithmetic, and
 * ADR-143 D-I assigns ownership of the prescribed quantity to Clinical — so
 * Clinical exposes it in structured form. `quantity_value` is the exact total
 * quantity the prescriber authorised for this prescribing act, in `quantity_unit`
 * (an OPEN string — ADR-143 D-L, no catalogue). Both NULLABLE — NULL on every
 * pre-C.10 row and every legacy / "quantity sufficient" row; NO backfill
 * (ADR-183 D-D forbids parsing "21 capsules" → 21 / capsule). The
 * "required-iff-present" rule (unit iff value; never alongside the free-text
 * `quantity`) is enforced in the command constructor (ADR-183 D-B / D-E), not a
 * DB CHECK — the ADR-143 D-I `medication_code` / `medication_code_system`
 * precedent.
 *
 * `decimal(18, 4)` — the shipped Inventory\Quantity / Procurement\Quantity
 * precision precedent; non-integer values permitted (split tablets, fractional
 * mL). No RLS change — the table already carries tenant FORCE RLS (C.7).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clin_prescribing_decisions', function (Blueprint $table): void {
            $table->decimal('quantity_value', 18, 4)->nullable()->after('quantity');
            $table->string('quantity_unit')->nullable()->after('quantity_value');
        });
    }

    public function down(): void
    {
        Schema::table('clin_prescribing_decisions', function (Blueprint $table): void {
            $table->dropColumn(['quantity_value', 'quantity_unit']);
        });
    }
};

<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 10 (increment Ins.5) — tenant-scoped authorization-number counter
 * (ADR-115; ADR-202 D-A). `AUT-%08d`.
 *
 * One row per tenant (`UNIQUE (tenant_id)`). Allocated by
 * App\Domains\Insurance\Infrastructure\Sequence\AuthorizationNumberSequence
 * inside the `RequestAuthorization` command transaction — the
 * `bill_financial_account_number_counters` shape verbatim.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ins_authorization_number_counters', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();
            $table->bigInteger('next_value')->default(1);
            $table->unsignedBigInteger('version')->default(0);
            $table->timestampsTz();

            $table->unique('tenant_id'); // ADR-115 — one counter row per tenant
        });

        Rls::enable('ins_authorization_number_counters');
    }

    public function down(): void
    {
        Rls::disable('ins_authorization_number_counters');
        Schema::dropIfExists('ins_authorization_number_counters');
    }
};

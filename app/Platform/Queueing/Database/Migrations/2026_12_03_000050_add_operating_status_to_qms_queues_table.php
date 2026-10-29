<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 12 (Increment P12.1) — Queueing & Patient Flow Platform Service
 * Add operating_status and session tracking to qms_queues table (23-queueing-and-patient-flow.md §69–§73, §205–§208, §320).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('qms_queues', function (Blueprint $table): void {
            $table->string('operating_status', 32)->default('ACTIVE')->index(); // ACTIVE, PAUSED, CLOSED
            $table->string('pause_reason')->nullable();
            $table->timestampTz('paused_at')->nullable();
            $table->timestampTz('closed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('qms_queues', function (Blueprint $table): void {
            $table->dropColumn(['operating_status', 'pause_reason', 'paused_at', 'closed_at']);
        });
    }
};

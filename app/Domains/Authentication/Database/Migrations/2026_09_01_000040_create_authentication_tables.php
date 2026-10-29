<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 2 — Authentication support tables (docs/00-foundation/04-authentication.md
 * §22–§23 device / concurrent-session awareness; §37–§38 auth telemetry).
 *
 * `aut_sessions` is tenant-scoped (a session belongs to an authenticated user of
 * one tenant). `aut_auth_events` may record events with no tenant yet (a failed
 * login before the account is known), so it is app-scoped, not RLS'd — it never
 * contains PHI and its rows are only ever read by platform security tooling.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('aut_sessions', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id');
            $table->uuid('user_id');
            $table->string('session_id')->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('last_active_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'session_id']);
        });
        Rls::enable('aut_sessions');

        Schema::create('aut_auth_events', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->nullable();
            $table->uuid('user_id')->nullable();
            $table->string('event')->index(); // login | login_failed | logout | locked | password_reset | mfa_enabled | mfa_reset | step_up
            $table->string('ip_address', 45)->nullable();
            $table->jsonb('metadata')->default('{}');
            $table->timestamp('created_at')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('aut_auth_events');
        Rls::disable('aut_sessions');
        Schema::dropIfExists('aut_sessions');
    }
};

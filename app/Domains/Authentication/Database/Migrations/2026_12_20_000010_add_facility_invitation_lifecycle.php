<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE HR.P0 — first-class facility invitation lifecycle.
 *
 * `aut_admin_bootstrap_tokens` remains the credential row (one row per send).
 * A resend now creates a new row and supersedes the previous one instead of
 * rotating the token hash in place, so the earlier send, expiry and role
 * snapshot survive. `aut_facility_invitation_events` is the append-only
 * timeline used by the staff profile and the account-invitations worklist.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('aut_admin_bootstrap_tokens', function (Blueprint $table): void {
            $table->string('status', 20)->default('PENDING')->after('request_reason');
            $table->timestampTz('sent_at')->nullable()->after('status');
            $table->string('delivery_status', 20)->default('PENDING')->after('sent_at');
            $table->timestampTz('superseded_at')->nullable()->after('delivery_status');
            $table->timestampTz('revoked_at')->nullable()->after('superseded_at');
            $table->uuid('revoked_by')->nullable()->after('revoked_at');
            $table->string('revocation_reason', 500)->nullable()->after('revoked_by');
            $table->timestampTz('expiry_notified_at')->nullable()->after('revocation_reason');

            $table->index(['tenant_id', 'facility_id', 'status'], 'aut_bootstrap_tenant_facility_status_idx');
            $table->index(['tenant_id', 'user_id', 'facility_id', 'created_at'], 'aut_bootstrap_tenant_user_facility_created_idx');
        });

        // Existing rows predate the explicit state machine. Preserve their
        // observable state rather than marking every historical row PENDING.
        DB::table('aut_admin_bootstrap_tokens')
            ->whereNotNull('consumed_at')
            ->update(['status' => 'CONSUMED', 'delivery_status' => 'SENT']);
        DB::table('aut_admin_bootstrap_tokens')
            ->whereNull('consumed_at')
            ->where('expires_at', '<=', now())
            ->update(['status' => 'EXPIRED', 'delivery_status' => 'SENT']);
        DB::table('aut_admin_bootstrap_tokens')
            ->whereNull('sent_at')
            ->update(['sent_at' => DB::raw('created_at')]);
        DB::table('aut_admin_bootstrap_tokens')
            ->where('delivery_status', 'PENDING')
            ->whereNotNull('sent_at')
            ->update(['delivery_status' => 'SENT']);

        Schema::create('aut_facility_invitation_events', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();
            $table->uuid('facility_id')->nullable()->index();
            $table->uuid('invitation_id')->index();
            $table->uuid('user_id')->index();
            $table->uuid('staff_id')->nullable()->index();
            $table->string('event_type', 40);
            $table->uuid('actor_id')->nullable();
            $table->string('recipient_email', 255)->nullable();
            $table->json('roles')->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->string('reason', 500)->nullable();
            $table->json('metadata')->nullable();
            $table->timestampTz('occurred_at')->index();
            $table->timestampsTz();

            $table->index(
                ['tenant_id', 'facility_id', 'occurred_at'],
                'aut_invitation_event_tenant_facility_occurred_idx',
            );
            $table->index(
                ['tenant_id', 'staff_id', 'occurred_at'],
                'aut_invitation_event_tenant_staff_occurred_idx',
            );
        });

        Rls::enable('aut_facility_invitation_events');
        Rls::enableAppendOnly('aut_facility_invitation_events');
    }

    public function down(): void
    {
        Rls::disable('aut_facility_invitation_events');
        Schema::dropIfExists('aut_facility_invitation_events');

        Schema::table('aut_admin_bootstrap_tokens', function (Blueprint $table): void {
            $table->dropIndex('aut_bootstrap_tenant_facility_status_idx');
            $table->dropIndex('aut_bootstrap_tenant_user_facility_created_idx');
            $table->dropColumn([
                'status',
                'sent_at',
                'delivery_status',
                'superseded_at',
                'revoked_at',
                'revoked_by',
                'revocation_reason',
                'expiry_notified_at',
            ]);
        });
    }
};

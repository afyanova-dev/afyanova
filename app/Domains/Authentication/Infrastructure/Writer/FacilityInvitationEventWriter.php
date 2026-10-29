<?php

declare(strict_types=1);

namespace App\Domains\Authentication\Infrastructure\Writer;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Append-only invitation timeline writer.
 *
 * Invitation state lives on `aut_admin_bootstrap_tokens`; this table records
 * the facts around each transition (issued / resent / expired / revoked /
 * consumed). It deliberately has no update or delete path.
 */
final class FacilityInvitationEventWriter
{
    /**
     * @param  list<string>  $roles
     * @param  array<string, mixed>  $metadata
     */
    public function record(
        string $tenantId,
        ?string $facilityId,
        string $invitationId,
        string $userId,
        ?string $staffId,
        string $eventType,
        ?string $actorId = null,
        ?string $recipientEmail = null,
        array $roles = [],
        ?string $expiresAt = null,
        ?string $reason = null,
        array $metadata = [],
    ): void {
        DB::table('aut_facility_invitation_events')->insert([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantId,
            'facility_id' => $facilityId,
            'invitation_id' => $invitationId,
            'user_id' => $userId,
            'staff_id' => $staffId,
            'event_type' => $eventType,
            'actor_id' => $actorId,
            'recipient_email' => $recipientEmail,
            'roles' => $roles === [] ? null : json_encode($roles, JSON_THROW_ON_ERROR),
            'expires_at' => $expiresAt,
            'reason' => $reason,
            'metadata' => $metadata === [] ? null : json_encode($metadata, JSON_THROW_ON_ERROR),
            'occurred_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}

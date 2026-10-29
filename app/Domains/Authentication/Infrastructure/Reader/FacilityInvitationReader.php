<?php

declare(strict_types=1);

namespace App\Domains\Authentication\Infrastructure\Reader;

use App\Domains\Authentication\Application\Dto\FacilityInvitationForResend;
use App\Domains\Authentication\Support\FacilityInvitationEventType;
use App\Domains\Authentication\Support\FacilityInvitationStatus;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class FacilityInvitationReader
{
    public function forResend(string $tenantId, string $invitationId): ?FacilityInvitationForResend
    {
        $invitation = $this->baseInvitationQuery($tenantId)
            ->where('invitation.id', $invitationId)
            ->whereIn('invitation.purpose', ['FACILITY_ROLE', 'FACILITY_ADMIN'])
            ->first($this->invitationColumns());

        return $invitation === null ? null : $this->toDto($invitation);
    }

    /**
     * Latest invitation row for an account at a facility. It intentionally
     * includes consumed, expired, revoked and superseded rows so the staff
     * profile can explain the current state instead of silently hiding it.
     */
    public function pendingForFacilityAccount(
        string $tenantId,
        string $facilityId,
        string $userId,
    ): ?FacilityInvitationForResend {
        $invitation = $this->baseInvitationQuery($tenantId)
            ->where('invitation.facility_id', $facilityId)
            ->where('invitation.user_id', $userId)
            ->whereIn('invitation.purpose', ['FACILITY_ROLE', 'FACILITY_ADMIN'])
            ->orderByDesc('invitation.created_at')
            ->orderByDesc('invitation.id')
            ->first($this->invitationColumns());

        return $invitation === null ? null : $this->toDto($invitation);
    }

    /**
     * One row per send for the account-invitations worklist. Resends are new
     * rows, so the page can show pending, expired, accepted, revoked and
     * replaced invitations without losing the earlier send.
     *
     * @return list<array{
     *     invitationId: string,
     *     userId: string,
     *     staffId: ?string,
     *     staffNumber: ?string,
     *     name: string,
     *     email: string,
     *     positionTitle: ?string,
     *     roles: list<string>,
     *     status: string,
     *     statusLabel: string,
     *     sentAt: ?string,
     *     expiresAt: string,
     *     expired: bool,
     *     deliveryStatus: string,
     *     resendCount: int,
     *     lastSentByName: ?string,
     *     canResend: bool,
     *     canRevoke: bool,
     *     consumedAt: ?string,
     *     revokedAt: ?string,
     *     revocationReason: ?string
     * }>
     */
    public function forFacility(string $tenantId, string $facilityId, int $limit = 250): array
    {
        $rows = $this->baseInvitationQuery($tenantId)
            ->leftJoin('hr_staff_user_links as staff_link', function ($join) use ($tenantId): void {
                $join->on('staff_link.user_id', '=', 'account.id')
                    ->where('staff_link.tenant_id', '=', $tenantId);
            })
            ->leftJoin('hr_staff as staff', function ($join) use ($tenantId): void {
                $join->on('staff.id', '=', 'staff_link.staff_id')
                    ->where('staff.tenant_id', '=', $tenantId);
            })
            ->leftJoin('hr_persons as person', 'person.id', '=', 'staff.person_id')
            ->leftJoin('hr_positions as position', 'position.id', '=', 'staff.primary_position_id')
            ->where('invitation.facility_id', $facilityId)
            ->where('invitation.purpose', 'FACILITY_ROLE')
            ->orderByDesc('invitation.created_at')
            ->orderByDesc('invitation.id')
            ->limit($limit)
            ->select($this->invitationColumns())
            ->addSelect([
                'staff.id as staff_id',
                'staff.staff_number',
                'person.first_name',
                'person.middle_name',
                'person.last_name',
                'position.title as position_title',
            ])
            ->selectSub(
                DB::table('aut_admin_bootstrap_tokens as previous')
                    ->selectRaw('GREATEST(COUNT(*) - 1, 0)')
                    ->whereColumn('previous.user_id', 'account.id')
                    ->where('previous.tenant_id', $tenantId)
                    ->where('previous.facility_id', $facilityId)
                    ->where('previous.purpose', 'FACILITY_ROLE'),
                'resend_count',
            )
            ->get();

        return array_values($rows->map(function (object $row): array {
            $status = (string) $row->invitation_status;
            $expired = $status === FacilityInvitationStatus::EXPIRED
                || ($status === FacilityInvitationStatus::PENDING && now()->greaterThanOrEqualTo($row->expires_at));
            $roleKeys = $this->decodeRoleKeys($row);
            $canAct = FacilityInvitationStatus::isOpen($status)
                && $row->consumed_at === null
                && $row->email_verified_at === null
                && (string) $row->account_status === 'ACTIVE';

            return [
                'invitationId' => (string) $row->invitation_id,
                'userId' => (string) $row->user_id,
                'staffId' => $row->staff_id === null ? null : (string) $row->staff_id,
                'staffNumber' => $row->staff_number === null ? null : (string) $row->staff_number,
                'name' => trim(implode(' ', array_filter([
                    $row->first_name,
                    $row->middle_name,
                    $row->last_name,
                ]))) ?: (string) $row->name,
                'email' => (string) $row->email,
                'positionTitle' => $row->position_title === null ? null : (string) $row->position_title,
                'roles' => $roleKeys,
                'status' => $status,
                'statusLabel' => FacilityInvitationStatus::label($expired ? FacilityInvitationStatus::EXPIRED : $status),
                'sentAt' => $row->sent_at === null ? null : (string) $row->sent_at,
                'expiresAt' => (string) $row->expires_at,
                'expired' => $expired,
                'deliveryStatus' => (string) $row->delivery_status,
                'resendCount' => (int) $row->resend_count,
                'lastSentByName' => $row->last_sent_by_name === null ? null : (string) $row->last_sent_by_name,
                'canResend' => $canAct,
                'canRevoke' => $canAct,
                'consumedAt' => $row->consumed_at === null ? null : (string) $row->consumed_at,
                'revokedAt' => $row->revoked_at === null ? null : (string) $row->revoked_at,
                'revocationReason' => $row->revocation_reason === null ? null : (string) $row->revocation_reason,
            ];
        })->all());
    }

    /**
     * Append-only invitation timeline for a staff profile.
     *
     * @return list<array{
     *     id: string,
     *     eventType: string,
     *     eventLabel: string,
     *     actorName: string,
     *     reason: ?string,
     *     occurredAt: string,
     *     recipientEmail: ?string,
     *     roles: list<string>,
     *     metadata: array<string, mixed>
     * }>
     */
    public function historyForStaff(
        string $tenantId,
        string $facilityId,
        string $staffId,
        string $userId,
        int $limit = 50,
    ): array {
        $rows = DB::table('aut_facility_invitation_events as event')
            ->leftJoin('users as actor', 'actor.id', '=', 'event.actor_id')
            ->where('event.tenant_id', $tenantId)
            ->where('event.facility_id', $facilityId)
            ->where(function ($query) use ($staffId, $userId): void {
                $query->where('event.staff_id', $staffId)
                    ->orWhere('event.user_id', $userId);
            })
            ->orderByDesc('event.occurred_at')
            ->orderByDesc('event.id')
            ->limit($limit)
            ->get([
                'event.id',
                'event.event_type',
                'event.actor_id',
                'event.reason',
                'event.occurred_at',
                'event.recipient_email',
                'event.roles',
                'event.metadata',
                'actor.name as actor_name',
            ]);

        return array_values($rows->map(static function (object $row): array {
            $roles = is_string($row->roles) ? json_decode($row->roles, true) : [];
            $metadata = is_string($row->metadata) ? json_decode($row->metadata, true) : [];

            return [
                'id' => (string) $row->id,
                'eventType' => (string) $row->event_type,
                'eventLabel' => FacilityInvitationEventType::label((string) $row->event_type),
                'actorName' => $row->actor_name === null ? 'System' : (string) $row->actor_name,
                'reason' => $row->reason === null ? null : (string) $row->reason,
                'occurredAt' => (string) $row->occurred_at,
                'recipientEmail' => $row->recipient_email === null ? null : (string) $row->recipient_email,
                'roles' => is_array($roles) ? array_values(array_filter($roles, 'is_string')) : [],
                'metadata' => is_array($metadata) ? $metadata : [],
            ];
        })->all());
    }

    private function baseInvitationQuery(string $tenantId): Builder
    {
        return DB::table('aut_admin_bootstrap_tokens as invitation')
            ->join('users as account', function ($join) use ($tenantId): void {
                $join->on('account.id', '=', 'invitation.user_id')
                    ->where('account.tenant_id', '=', $tenantId);
            })
            ->leftJoin('users as sender', 'sender.id', '=', 'invitation.requested_by')
            ->where('invitation.tenant_id', $tenantId);
    }

    /** @return list<string> */
    private function invitationColumns(): array
    {
        return [
            'invitation.facility_id',
            'invitation.id as invitation_id',
            'invitation.user_id',
            'invitation.role_key',
            'invitation.role_keys',
            'invitation.purpose',
            'invitation.expires_at',
            'invitation.consumed_at',
            'invitation.status as invitation_status',
            'invitation.sent_at',
            'invitation.delivery_status',
            'invitation.superseded_at',
            'invitation.revoked_at',
            'invitation.revocation_reason',
            'account.name',
            'account.email',
            'account.email_verified_at',
            'account.status as account_status',
            'sender.name as last_sent_by_name',
        ];
    }

    private function toDto(object $invitation): FacilityInvitationForResend
    {
        $values = get_object_vars($invitation);
        $invitationStatus = (string) ($values['invitation_status'] ?? FacilityInvitationStatus::PENDING);
        if ($invitationStatus === FacilityInvitationStatus::PENDING
            && now()->greaterThanOrEqualTo($values['expires_at'])) {
            $invitationStatus = FacilityInvitationStatus::EXPIRED;
        }

        return new FacilityInvitationForResend(
            invitationId: (string) $values['invitation_id'],
            userId: (string) $values['user_id'],
            facilityId: (string) $values['facility_id'],
            roleKey: $values['role_key'] === null ? null : (string) $values['role_key'],
            roleKeys: $values['role_keys'] === null ? null : (string) $values['role_keys'],
            purpose: (string) $values['purpose'],
            expiresAt: (string) $values['expires_at'],
            consumedAt: $values['consumed_at'] === null ? null : (string) $values['consumed_at'],
            name: (string) $values['name'],
            email: (string) $values['email'],
            emailVerifiedAt: $values['email_verified_at'] === null ? null : (string) $values['email_verified_at'],
            status: (string) $values['account_status'],
            invitationStatus: $invitationStatus,
            sentAt: $values['sent_at'] === null ? null : (string) $values['sent_at'],
            deliveryStatus: (string) $values['delivery_status'],
            supersededAt: $values['superseded_at'] === null ? null : (string) $values['superseded_at'],
            revokedAt: $values['revoked_at'] === null ? null : (string) $values['revoked_at'],
            revocationReason: $values['revocation_reason'] === null ? null : (string) $values['revocation_reason'],
            lastSentByName: $values['last_sent_by_name'] === null ? null : (string) $values['last_sent_by_name'],
        );
    }

    /** @return list<string> */
    private function decodeRoleKeys(object $row): array
    {
        $decoded = is_string($row->role_keys ?? null)
            ? json_decode($row->role_keys, true)
            : null;
        if (is_array($decoded)) {
            return array_values(array_filter($decoded, 'is_string'));
        }

        return is_string($row->role_key ?? null) && $row->role_key !== ''
            ? [(string) $row->role_key]
            : [];
    }
}

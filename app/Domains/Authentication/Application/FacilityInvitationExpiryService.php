<?php

declare(strict_types=1);

namespace App\Domains\Authentication\Application;

use App\Domains\Authentication\Infrastructure\Writer\FacilityInvitationEventWriter;
use App\Domains\Authentication\Support\FacilityInvitationEventType;
use App\Domains\Authentication\Support\FacilityInvitationStatus;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Periodic facility-invitation expiry sweep.
 *
 * Expiry is a lifecycle transition, not just a read-time comparison: the
 * token is marked EXPIRED, the append-only timeline gets an EXPIRED event, and
 * facility HR/identity managers receive one queued alert. A token is processed
 * once for expiry and once for notification (tracked by expiry_notified_at),
 * so a transient mail failure is retried on the next sweep.
 */
final class FacilityInvitationExpiryService
{
    public function __construct(
        private readonly FacilityInvitationEventWriter $events,
        private readonly FacilityInvitationExpiryNotifier $notifier,
    ) {}

    /**
     * @return array{expired: int, notified: int}
     */
    public function run(): array
    {
        $tenantIds = DB::table('shared_tenants')
            ->orderBy('id')
            ->pluck('id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all();

        $expired = 0;
        $notified = 0;

        foreach ($tenantIds as $tenantId) {
            $invitationIds = TenantContextScope::run($tenantId, null, static fn (): array => DB::table('aut_admin_bootstrap_tokens')
                ->where('tenant_id', $tenantId)
                ->where('purpose', 'FACILITY_ROLE')
                ->whereNotNull('facility_id')
                ->where(function ($query): void {
                    $query->where(function ($pending): void {
                        $pending->where('status', FacilityInvitationStatus::PENDING)
                            ->where('expires_at', '<=', now());
                    })->orWhere(function ($unnotified): void {
                        $unnotified->where('status', FacilityInvitationStatus::EXPIRED)
                            ->whereNull('expiry_notified_at');
                    });
                })
                ->orderBy('facility_id')
                ->orderBy('created_at')
                ->pluck('id')
                ->map(static fn (mixed $id): string => (string) $id)
                ->all());

            foreach ($invitationIds as $invitationId) {
                try {
                    $result = TenantContextScope::run(
                        $tenantId,
                        null,
                        fn (): array => $this->processInvitation($tenantId, $invitationId),
                    );
                    $expired += $result['expired'];
                    $notified += $result['notified'];
                } catch (Throwable $exception) {
                    // One malformed invitation or mail transport hiccup must not
                    // stop the sweep for the rest of the tenant.
                    Log::warning('Facility invitation expiry sweep failed for invitation '.$invitationId, [
                        'tenant_id' => $tenantId,
                        'invitation_id' => $invitationId,
                        'exception' => $exception,
                    ]);
                }
            }
        }

        return ['expired' => $expired, 'notified' => $notified];
    }

    /** @return array{expired: int, notified: int} */
    private function processInvitation(string $tenantId, string $invitationId): array
    {
        // Lock only the credential row. PostgreSQL rejects FOR UPDATE when the
        // query has nullable outer-joined tables, so the display data is read
        // separately after the row lock is held.
        $invitation = DB::table('aut_admin_bootstrap_tokens as invitation')
            ->where('invitation.tenant_id', $tenantId)
            ->where('invitation.id', $invitationId)
            ->lockForUpdate()
            ->first([
                'invitation.id',
                'invitation.facility_id',
                'invitation.user_id',
                'invitation.status',
                'invitation.expires_at',
                'invitation.expiry_notified_at',
                'invitation.role_key',
                'invitation.role_keys',
            ]);

        if ($invitation === null || $invitation->facility_id === null) {
            return ['expired' => 0, 'notified' => 0];
        }

        $account = DB::table('users')
            ->where('tenant_id', $tenantId)
            ->where('id', $invitation->user_id)
            ->first(['name', 'email']);
        if ($account === null) {
            return ['expired' => 0, 'notified' => 0];
        }

        $staff = DB::table('hr_staff_user_links as staff_link')
            ->leftJoin('hr_staff as staff', function ($join) use ($tenantId): void {
                $join->on('staff.id', '=', 'staff_link.staff_id')
                    ->where('staff.tenant_id', '=', $tenantId);
            })
            ->leftJoin('hr_persons as person', 'person.id', '=', 'staff.person_id')
            ->where('staff_link.tenant_id', $tenantId)
            ->where('staff_link.user_id', $invitation->user_id)
            ->first([
                'staff.id as staff_id',
                'person.first_name',
                'person.middle_name',
                'person.last_name',
            ]);

        $expired = 0;
        if ((string) $invitation->status === FacilityInvitationStatus::PENDING
            && now()->greaterThanOrEqualTo($invitation->expires_at)) {
            $changed = DB::table('aut_admin_bootstrap_tokens')
                ->where('tenant_id', $tenantId)
                ->where('id', $invitationId)
                ->where('status', FacilityInvitationStatus::PENDING)
                ->where('expires_at', '<=', now())
                ->update([
                    'status' => FacilityInvitationStatus::EXPIRED,
                    'updated_at' => now(),
                ]);
            if ($changed !== 1) {
                return ['expired' => 0, 'notified' => 0];
            }
            $expired = 1;
            $this->events->record(
                tenantId: $tenantId,
                facilityId: (string) $invitation->facility_id,
                invitationId: $invitationId,
                userId: (string) $invitation->user_id,
                staffId: $staff?->staff_id === null ? null : (string) $staff->staff_id,
                eventType: FacilityInvitationEventType::EXPIRED,
                recipientEmail: (string) $account->email,
                roles: $this->decodeRoles($invitation->role_keys, $invitation->role_key),
                expiresAt: (string) $invitation->expires_at,
                reason: 'The setup link reached its 60-minute expiry before the account was activated.',
            );
        }

        if ($invitation->expiry_notified_at !== null) {
            return ['expired' => $expired, 'notified' => 0];
        }

        $staffName = trim(implode(' ', array_filter([
            $staff?->first_name,
            $staff?->middle_name,
            $staff?->last_name,
        ])));
        $recipients = $this->notifier->notify(
            tenantId: $tenantId,
            facilityId: (string) $invitation->facility_id,
            invitationId: $invitationId,
            inviteeName: $staffName !== '' ? $staffName : (string) $account->name,
            inviteeEmail: (string) $account->email,
            expiresAt: (string) $invitation->expires_at,
            staffId: $staff?->staff_id === null ? null : (string) $staff->staff_id,
        );

        DB::table('aut_admin_bootstrap_tokens')
            ->where('tenant_id', $tenantId)
            ->where('id', $invitationId)
            ->whereNull('expiry_notified_at')
            ->update(['expiry_notified_at' => now(), 'updated_at' => now()]);

        return ['expired' => $expired, 'notified' => $recipients > 0 ? 1 : 0];
    }

    /** @return list<string> */
    private function decodeRoles(mixed $roleKeys, mixed $roleKey): array
    {
        $decoded = is_string($roleKeys) ? json_decode($roleKeys, true) : null;
        if (is_array($decoded)) {
            return array_values(array_filter($decoded, 'is_string'));
        }

        return is_string($roleKey) && $roleKey !== '' ? [$roleKey] : [];
    }
}

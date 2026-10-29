<?php

declare(strict_types=1);

namespace App\Domains\Authentication\Application;

use App\Domains\Authentication\Mail\FacilityInvitationExpired;
use App\Domains\Identity\Contracts\IdentityApi;
use App\Domains\Organization\Contracts\OrganizationAdminApi;
use App\Models\User;
use App\Shared\Authorization\ScopeChecker;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Resolves the facility's HR and identity managers and sends one expiry alert
 * per recipient. The alert is intentionally a direct queued mail (the
 * notification platform's USER channel does not resolve a user email address);
 * the in-app signal remains the dashboard "Expired invitations" widget.
 */
final class FacilityInvitationExpiryNotifier
{
    public function __construct(
        private readonly IdentityApi $identity,
        private readonly ScopeChecker $scopeChecker,
        private readonly OrganizationAdminApi $organization,
    ) {}

    /**
     * @return int number of recipients queued
     */
    public function notify(
        string $tenantId,
        string $facilityId,
        string $invitationId,
        string $inviteeName,
        string $inviteeEmail,
        string $expiresAt,
        ?string $staffId = null,
    ): int {
        $facility = $this->organization->activeFacilitySummary($tenantId, $facilityId);
        if ($facility === null) {
            Log::warning('Skipping invitation expiry alert for an inactive facility.', [
                'tenant_id' => $tenantId,
                'facility_id' => $facilityId,
                'invitation_id' => $invitationId,
            ]);

            return 0;
        }

        $accountIds = $this->identity->facilityRelatedAccountIds(
            $tenantId,
            $facilityId,
            (string) $facility['organizationId'],
        );
        $inviterId = DB::table('aut_admin_bootstrap_tokens')
            ->where('tenant_id', $tenantId)
            ->where('id', $invitationId)
            ->value('requested_by');
        if (is_string($inviterId)) {
            $accountIds[] = $inviterId;
        }
        $accountIds = array_values(array_unique($accountIds));
        if ($accountIds === []) {
            return 0;
        }

        $recipients = User::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('id', $accountIds)
            ->where('status', 'ACTIVE')
            ->whereNotNull('email_verified_at')
            ->whereNotNull('email')
            ->orderBy('name')
            ->get(['id', 'name', 'email']);

        $actionUrl = $staffId === null
            ? route('hr.staff.account-invitations')
            : route('hr.staff.show', ['staff' => $staffId, 'section' => 'access']);
        $queued = 0;
        $sentTo = [];

        foreach ($recipients as $recipient) {
            if (! $this->scopeChecker->withinFacilityScope($recipient, $facilityId)) {
                continue;
            }
            $permissions = $this->scopeChecker->effectivePermissions($recipient, $facilityId);
            $isHRManager = in_array('hr.staff.manage', $permissions, true);
            $isIdentityManager = in_array('admin.workspace.access', $permissions, true)
                && in_array('identity.assignments.manage', $permissions, true);
            if (! $isHRManager && ! $isIdentityManager) {
                continue;
            }
            if (isset($sentTo[(string) $recipient->email])) {
                continue;
            }

            Mail::to((string) $recipient->email)->queue(new FacilityInvitationExpired(
                inviteeName: $inviteeName,
                inviteeEmail: $inviteeEmail,
                facilityName: (string) $facility['name'],
                expiredAt: $expiresAt,
                actionUrl: $actionUrl,
            ));
            $sentTo[(string) $recipient->email] = true;
            $queued++;
        }

        Log::info('Facility invitation expiry alert queued.', [
            'tenant_id' => $tenantId,
            'facility_id' => $facilityId,
            'invitation_id' => $invitationId,
            'recipients' => $queued,
        ]);

        return $queued;
    }
}

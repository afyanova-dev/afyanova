import { Button, TableToolbarSearch, Tabs, Tag } from '@carbon/react';
import { Link } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import { routes } from '@/app/routes';
import { CarbonDataTable, ContainedTabList, EmptyState, IconTab, PageHeader } from '@/components/ui';
import { HRWorkflowFeedback } from '@/features/hr/components/HRWorkflowFeedback';
import type { FacilityInvitationRow, FacilitySummary } from '@/features/hr/types';
import { ArrowsHorizontal, Checkmark, Close, Document, Pending, WarningAlt } from '@/icons';
import { AppLayout } from '@/layouts/AppLayout/AppLayout';

const INVITATION_HEADERS = [
  { key: 'staff', header: 'Staff member' },
  { key: 'email', header: 'Account email' },
  { key: 'roles', header: 'Roles' },
  { key: 'sent', header: 'Sent / resent' },
  { key: 'expires', header: 'Expires' },
  { key: 'status', header: 'Invitation status' },
  { key: 'action', header: 'Action' },
];

const INVITATION_TABS = [
  { key: 'ALL', label: 'All', icon: Document },
  { key: 'PENDING', label: 'Pending', icon: Pending },
  { key: 'EXPIRED', label: 'Expired', icon: WarningAlt },
  { key: 'CONSUMED', label: 'Accepted', icon: Checkmark },
  { key: 'REVOKED', label: 'Revoked', icon: Close },
  { key: 'SUPERSEDED', label: 'Replaced', icon: ArrowsHorizontal },
] as const;

type InvitationTab = typeof INVITATION_TABS[number]['key'];

function effectiveStatus(invitation: FacilityInvitationRow): Exclude<InvitationTab, 'ALL'> {
  if (invitation.status === 'PENDING' && invitation.expired) return 'EXPIRED';
  return invitation.status as Exclude<InvitationTab, 'ALL'>;
}

function statusTag(invitation: FacilityInvitationRow): React.JSX.Element {
  const status = effectiveStatus(invitation);
  const type = status === 'PENDING'
    ? 'blue'
    : status === 'EXPIRED'
      ? 'red'
      : status === 'CONSUMED'
        ? 'green'
        : status === 'REVOKED'
          ? 'magenta'
          : 'gray';
  return <Tag type={type}>{status === 'PENDING' ? 'Pending' : invitation.statusLabel}</Tag>;
}

function roleLabel(role: string): string {
  return role
    .toLowerCase()
    .split(/[_\s]+/)
    .filter(Boolean)
    .map((part) => part[0]!.toUpperCase() + part.slice(1))
    .join(' ');
}

interface AccountInvitationsProps {
  facility: FacilitySummary;
  invitations?: FacilityInvitationRow[];
  canResendInvitations?: boolean;
  canRevokeInvitations?: boolean;
  pendingAccessRequestCount?: number;
  errors?: Record<string, string>;
}

export default function AccountInvitationsPage({
  facility,
  invitations = [],
  canResendInvitations = false,
  errors,
}: AccountInvitationsProps): React.JSX.Element {
  const [activeTab, setActiveTab] = useState<InvitationTab>('ALL');
  const counts = useMemo(() => {
    const result: Record<InvitationTab, number> = {
      ALL: invitations.length,
      PENDING: 0,
      EXPIRED: 0,
      CONSUMED: 0,
      REVOKED: 0,
      SUPERSEDED: 0,
    };
    for (const invitation of invitations) {
      const status = effectiveStatus(invitation);
      if (status in result) result[status] += 1;
    }
    return result;
  }, [invitations]);
  const visibleInvitations = activeTab === 'ALL'
    ? invitations
    : invitations.filter((invitation) => effectiveStatus(invitation) === activeTab);
  const selectedTabIndex = Math.max(0, INVITATION_TABS.findIndex((tab) => tab.key === activeTab));

  const rows = visibleInvitations.map((invitation) => {
    const profileUrl = invitation.staffId === null
      ? null
      : `${routes.workspaces.hrStaffDetail(invitation.staffId)}?section=access`;
    return {
      id: invitation.invitationId,
      staff: profileUrl === null
        ? invitation.name
        : <Link href={profileUrl}>{invitation.name}</Link>,
      email: invitation.email,
      roles: invitation.roles.length === 0 ? 'Not recorded' : invitation.roles.map(roleLabel).join(', '),
      sent: invitation.sentAt === null
        ? 'Not recorded'
        : `${new Date(invitation.sentAt).toLocaleString()}${invitation.resendCount > 0 ? ` · ${invitation.resendCount} resend${invitation.resendCount === 1 ? '' : 's'}` : ''}`,
      expires: new Date(invitation.expiresAt).toLocaleString(),
      status: statusTag(invitation),
      action: profileUrl === null ? (
        <span>Staff record unavailable</span>
      ) : (
        <Button
          as={Link}
          href={`${profileUrl}${canResendInvitations && invitation.canResend ? '&resend=1' : ''}`}
          kind="tertiary"
          size="sm"
        >
          {canResendInvitations && invitation.canResend ? 'Review & resend' : 'Review invitation'}
        </Button>
      ),
    };
  });

  return (
    <AppLayout>
      <main className="afyanova-page facility-staff">
        <PageHeader
          title="Invitation history"
          description={`Invitation history for ${facility.name}. Resends create a new link and replace the previous one; every send remains visible here.`}
          actions={(
            <Button as={Link} kind="ghost" size="sm" href={routes.workspaces.hrStaffOnboarding}>
              Back to onboarding
            </Button>
          )}
        />
        <HRWorkflowFeedback errors={errors} />
        <section className="facility-staff__directory" aria-label="Account invitation history">
          <Tabs
            selectedIndex={selectedTabIndex}
            onChange={({ selectedIndex }) => {
              const next = INVITATION_TABS[selectedIndex];
              if (next !== undefined) setActiveTab(next.key);
            }}
          >
            <ContainedTabList aria-label="Invitation lifecycle views">
              {INVITATION_TABS.map((tab) => (
                <IconTab key={tab.key} icon={tab.icon}>
                  {tab.label} ({counts[tab.key]})
                </IconTab>
              ))}
            </ContainedTabList>
          </Tabs>
          <CarbonDataTable
            rows={rows}
            headers={INVITATION_HEADERS}
            title={`${INVITATION_TABS[selectedTabIndex]?.label ?? 'All'} invitations (${visibleInvitations.length} of ${invitations.length})`}
            ariaLabel="Facility account invitation history"
            emptyState={(
              <EmptyState
                title={counts.ALL === 0 ? 'No account invitations yet' : `No ${activeTab.toLocaleLowerCase()} invitations`}
                description={counts.ALL === 0
                  ? 'Invite an account from a staff profile to start the setup lifecycle.'
                  : 'Switch to another lifecycle tab to see the rest of the invitation history.'}
              />
            )}
            toolbar={(onInputChange) => (
              <TableToolbarSearch
                persistent
                onChange={onInputChange}
                placeholder="Search invitations"
                aria-label="Search invitations"
              />
            )}
          />
        </section>
      </main>
    </AppLayout>
  );
}

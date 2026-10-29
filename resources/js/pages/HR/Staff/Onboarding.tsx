import { Button, TableToolbarSearch, Tabs, Tag } from '@carbon/react';
import { Link, router } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';
import { routes } from '@/app/routes';
import { CarbonDataTable, ContainedTabList, EmptyState, IconTab, PageHeader, StatisticsCards } from '@/components/ui';
import { Checkmark, Pending, Send, Task, UserMultiple, UserSettings, WarningAlt } from '@/icons';
import type {
  FacilitySummary,
  OnboardingActionKind,
  StaffOnboardingFocus,
  StaffOnboardingRow,
  StaffOnboardingSummary,
} from '@/features/hr/types';
import { staffStatusLabel, staffStatusTagType } from '@/lib/staffStatus';
import { AppLayout } from '@/layouts/AppLayout/AppLayout';

const ONBOARDING_HEADERS = [
  { key: 'staff', header: 'Staff member' },
  { key: 'placement', header: 'Placement' },
  { key: 'account', header: 'Account' },
  { key: 'access', header: 'Access' },
  { key: 'onboarding', header: 'Onboarding' },
  { key: 'action', header: 'Next action' },
];

const TABS = [
  { key: 'NEEDS_ACTION', label: 'Needs action', icon: WarningAlt },
  { key: 'IN_PROGRESS', label: 'In progress', icon: Pending },
  { key: 'ALL', label: 'All', icon: UserMultiple },
] as const;

type OnboardingTab = typeof TABS[number]['key'];

const FOCUS_LABELS: Partial<Record<StaffOnboardingFocus, string>> = {
  placements: 'Placements',
  access: 'Role approvals',
  invitations: 'Invitations',
  onboarding: 'Onboarding',
  ready: 'Ready to onboard',
  'in-progress': 'In progress',
  all: 'All onboarding',
};

interface StaffOnboardingProps {
  facility: FacilitySummary;
  rows?: StaffOnboardingRow[];
  summary?: StaffOnboardingSummary;
  focus?: StaffOnboardingFocus;
  permissions?: {
    canReviewPlacements: boolean;
    canManageStaff: boolean;
    canManageAccess: boolean;
  };
  errors?: Record<string, string>;
}

function matchesFocus(row: StaffOnboardingRow, focus: StaffOnboardingFocus): boolean {
  if (focus === 'placements') {
    return row.placement.state === 'PENDING' || row.placement.state === 'REJECTED';
  }
  if (focus === 'access') {
    return row.access.pendingCount > 0;
  }
  if (focus === 'invitations') {
    return ['INVITED', 'INVITATION_EXPIRED', 'INVITATION_REVOKED', 'INVITATION_REPLACED', 'SETUP_PENDING']
      .includes(row.accountState);
  }
  if (focus === 'onboarding') {
    return row.staffStatus === 'PROSPECT' || row.staffStatus === 'ONBOARDING';
  }
  if (focus === 'ready') {
    return row.staffStatus === 'PROSPECT' && row.placement.state === 'CONFIRMED';
  }

  return true;
}

function focusHref(focus: StaffOnboardingFocus): string {
  return focus === 'needs-action'
    ? routes.workspaces.hrStaffOnboarding
    : `${routes.workspaces.hrStaffOnboarding}?focus=${focus}`;
}

function actionHref(kind: OnboardingActionKind, staffId: string): string {
  switch (kind) {
    case 'CONFIRM_PLACEMENT':
    case 'UPDATE_PLACEMENT':
      return routes.workspaces.hrStaffAssignments;
    case 'RESEND_INVITATION':
      return `${routes.workspaces.hrStaffDetail(staffId)}?section=access&resend=1`;
    case 'REVIEW_INVITATION':
      return `${routes.workspaces.hrStaffDetail(staffId)}?section=access`;
    case 'INVITE_ACCOUNT':
      return `${routes.workspaces.hrStaffDetail(staffId)}?section=access`;
    case 'APPROVE_ACCESS':
      return routes.workspaces.hrStaffAccessRequests;
    case 'COMPLETE_ONBOARDING':
    case 'START_EMPLOYMENT':
      return `${routes.workspaces.hrStaffDetail(staffId)}?section=employment`;
    default:
      return routes.workspaces.hrStaffDetail(staffId);
  }
}

function placementTagType(state: StaffOnboardingRow['placement']['state']): 'green' | 'blue' | 'red' | 'gray' {
  if (state === 'CONFIRMED') return 'green';
  if (state === 'PENDING') return 'blue';
  if (state === 'REJECTED') return 'red';
  return 'gray';
}

function accountTagType(state: string): 'green' | 'blue' | 'red' | 'gray' {
  if (state === 'ACTIVE') return 'green';
  if (state === 'INVITED') return 'blue';
  if (state === 'INVITATION_EXPIRED') return 'red';
  return 'gray';
}

function accessTagType(state: string): 'green' | 'blue' | 'gray' {
  if (state === 'ACTIVE') return 'green';
  if (state === 'PENDING') return 'blue';
  return 'gray';
}

function placementDetail(row: StaffOnboardingRow): string {
  const placement = [row.placement.unitName, row.placement.departmentName]
    .filter((value): value is string => Boolean(value))
    .join(' · ');

  if (placement !== '') return placement;
  if (row.placement.state === 'CONFIRMED') return 'Facility-wide';

  return row.placement.effectiveFrom === null ? 'Not confirmed' : `Effective ${row.placement.effectiveFrom}`;
}

function isWaitingAction(kind: OnboardingActionKind): boolean {
  return kind === 'WAIT_PLACEMENT' || kind === 'WAIT_ACCOUNT';
}

export default function StaffOnboardingPage({
  facility,
  rows = [],
  summary = {
    total: rows.length,
    needsAction: rows.filter((row) => row.needsAction).length,
    placements: rows.filter((row) => row.placement.state === 'PENDING').length,
    invitations: 0,
    expiredInvitations: 0,
    access: rows.filter((row) => row.access.pendingCount > 0).length,
    readyToOnboard: rows.filter((row) => row.staffStatus === 'PROSPECT' && row.placement.state === 'CONFIRMED').length,
  },
  focus = 'needs-action',
}: StaffOnboardingProps): React.JSX.Element {
  const [search, setSearch] = useState('');
  const initialTab: OnboardingTab = focus === 'needs-action'
    ? 'NEEDS_ACTION'
    : focus === 'in-progress'
      ? 'IN_PROGRESS'
      : 'ALL';
  const [activeTab, setActiveTab] = useState<OnboardingTab>(initialTab);
  useEffect(() => {
    setActiveTab(initialTab);
  }, [initialTab]);
  const focusRows = useMemo(() => rows.filter((row) => matchesFocus(row, focus)), [rows, focus]);
  const counts = useMemo(() => ({
    NEEDS_ACTION: focusRows.filter((row) => row.needsAction).length,
    IN_PROGRESS: focusRows.filter((row) => !row.needsAction).length,
    ALL: focusRows.length,
  }), [focusRows]);
  const selectedTabIndex = Math.max(0, TABS.findIndex((tab) => tab.key === activeTab));
  const searchTerm = search.trim().toLocaleLowerCase();
  const visibleRows = focusRows
    .filter((row) => activeTab === 'ALL'
      || (activeTab === 'NEEDS_ACTION' ? row.needsAction : !row.needsAction))
    .filter((row) => {
      if (searchTerm === '') return true;

      return [
        row.name,
        row.staffNumber,
        row.positionTitle,
        row.account.email,
        row.placement.departmentName,
        row.placement.unitName,
        ...row.access.roleLabels,
      ].some((value) => value?.toLocaleLowerCase().includes(searchTerm));
    });

  const tableRows = visibleRows.map((row) => ({
    id: row.id,
    staff: (
      <div className="facility-staff__table-primary-cell">
        <Link href={routes.workspaces.hrStaffDetail(row.id)}>
          <strong>{row.name}</strong>
        </Link>
        <span>{[row.staffNumber, row.positionTitle].filter(Boolean).join(' · ')}</span>
      </div>
    ),
    placement: (
      <div className="facility-staff__table-primary-cell">
        <Tag type={placementTagType(row.placement.state)}>{row.placement.label}</Tag>
        <span>{placementDetail(row)}</span>
        {row.placement.decisionReason ? <span>{row.placement.decisionReason}</span> : null}
      </div>
    ),
    account: (
      <div className="facility-staff__table-primary-cell">
        <Tag type={accountTagType(row.accountState)}>{row.account.label}</Tag>
        <span>{row.account.email ?? 'No linked account'}</span>
      </div>
    ),
    access: (
      <div className="facility-staff__table-primary-cell">
        <Tag type={accessTagType(row.access.state)}>{row.access.label}</Tag>
        <span>{row.access.roleLabels.length > 0 ? row.access.roleLabels.join(', ') : 'No pending role requests'}</span>
      </div>
    ),
    onboarding: (
      <div className="facility-staff__table-primary-cell">
        <Tag type={staffStatusTagType(row.staffStatus)}>{staffStatusLabel(row.staffStatus)}</Tag>
        <span>{row.employment.label}</span>
      </div>
    ),
    action: row.nextAction === null ? (
      <span>View profile</span>
    ) : isWaitingAction(row.nextAction.kind) ? (
      <span className="facility-staff__onboarding-waiting">{row.nextAction.label}</span>
    ) : (
      <Button
        as={Link}
        kind={row.nextAction.kind === 'VIEW_PROFILE' ? 'ghost' : 'tertiary'}
        size="sm"
        href={actionHref(row.nextAction.kind, row.id)}
      >
        {row.nextAction.label}
      </Button>
    ),
  }));

  const activeTabLabel = TABS.find((tab) => tab.key === activeTab)?.label ?? 'All';
  const focusLabel = FOCUS_LABELS[focus];

  return (
    <AppLayout>
      <main className="afyanova-page facility-staff">
        <PageHeader
          title="Staff onboarding"
          description={`One queue for placement, account setup, role approval and activation at ${facility.name}.`}
        />

        <StatisticsCards
          label="Onboarding summary"
          cards={[
            {
              id: 'needs-action',
              label: 'Needs action',
              value: summary.needsAction,
              icon: <Pending />,
              detail: `Across ${summary.total} ${summary.total === 1 ? 'staff member' : 'staff members'}`,
              borderTone: summary.needsAction > 0 ? 'caution' : 'positive',
              href: focusHref('needs-action'),
              selected: focus === 'needs-action',
            },
            {
              id: 'placements',
              label: 'Placements',
              value: summary.placements,
              icon: <Task />,
              detail: summary.placements > 0 ? 'Awaiting confirmation' : 'No pending requests',
              borderTone: summary.placements > 0 ? 'caution' : 'positive',
              href: focusHref('placements'),
              selected: focus === 'placements',
            },
            {
              id: 'invitations',
              label: 'Invitations',
              value: summary.invitations,
              icon: <Send />,
              detail: summary.invitations === 0
                ? 'No open invitations'
                : summary.expiredInvitations > 0
                  ? `${summary.expiredInvitations} expired`
                  : 'Awaiting account setup',
              borderTone: summary.expiredInvitations > 0
                ? 'negative'
                : summary.invitations > 0 ? 'caution' : 'positive',
              href: focusHref('invitations'),
              selected: focus === 'invitations',
            },
            {
              id: 'access',
              label: 'Role approvals',
              value: summary.access,
              icon: <UserSettings />,
              detail: summary.access > 0 ? 'Pending role requests' : 'No pending requests',
              borderTone: summary.access > 0 ? 'information' : 'positive',
              href: focusHref('access'),
              selected: focus === 'access',
            },
            {
              id: 'ready-to-onboard',
              label: 'Ready to onboard',
              value: summary.readyToOnboard,
              icon: <Checkmark />,
              detail: summary.readyToOnboard > 0 ? 'Placement confirmed' : 'No one waiting',
              borderTone: summary.readyToOnboard > 0 ? 'positive' : 'information',
              href: focusHref('ready'),
              selected: focus === 'ready',
            },
          ]}
        />

        {focus !== 'needs-action' && focusLabel ? (
          <div className="facility-staff__onboarding-focus">
            <Tag type="blue">Filtered: {focusLabel}</Tag>
            <Button
              kind="ghost"
              size="sm"
              onClick={() => router.get(routes.workspaces.hrStaffOnboarding)}
            >
              Clear filter
            </Button>
          </div>
        ) : null}

        <section className="facility-staff__directory" aria-label="Staff onboarding queue">
          <Tabs
            selectedIndex={selectedTabIndex}
            onChange={({ selectedIndex }) => {
              const next = TABS[selectedIndex];
              if (next !== undefined) setActiveTab(next.key);
            }}
          >
            <ContainedTabList aria-label="Onboarding queue views">
              {TABS.map((tab) => (
                <IconTab key={tab.key} icon={tab.icon}>
                  {tab.label} ({counts[tab.key]})
                </IconTab>
              ))}
            </ContainedTabList>
          </Tabs>
          <CarbonDataTable
            rows={tableRows}
            headers={ONBOARDING_HEADERS}
            title={`${activeTabLabel} (${visibleRows.length} of ${focusRows.length})`}
            ariaLabel="Staff onboarding queue"
            className="facility-staff__onboarding-table"
            emptyState={(
              <EmptyState
                title={focusRows.length === 0
                  ? 'No staff in onboarding'
                  : searchTerm !== ''
                    ? 'No staff match your search'
                    : activeTab === 'ALL'
                      ? 'No staff in this view'
                      : `No staff ${activeTabLabel.toLocaleLowerCase()}`}
                description={focusRows.length === 0
                  ? 'New staff will appear here after they are added with a facility placement.'
                  : searchTerm !== ''
                    ? 'Try a different name, staff number, email or role.'
                    : 'Switch to another view or clear the current filter.'}
              />
            )}
            toolbar={(
              <TableToolbarSearch
                persistent
                onChange={(event) => {
                  setSearch(typeof event === 'string' ? event : event.currentTarget.value);
                }}
                placeholder="Search onboarding staff"
                aria-label="Search onboarding staff"
              />
            )}
          />
        </section>
      </main>
    </AppLayout>
  );
}

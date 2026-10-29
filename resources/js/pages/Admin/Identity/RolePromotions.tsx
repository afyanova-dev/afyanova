import {
  Button,
  ComboBox,
  InlineNotification,
  Modal,
  Pagination,
  Select,
  SelectItem,
  Stack,
  TabPanel,
  TabPanels,
  Tabs,
  TableToolbarMenu,
  TableToolbarSearch,
  OverflowMenuItem,
  Tag,
  TextArea,
  TextInput,
} from '@carbon/react';
import { Close, Filter, Information, Send, UserFollow, UserSettings } from '@/icons';
import { router, useForm } from '@inertiajs/react';
import { useEffect, useMemo, useState, type FormEvent, type ReactNode } from 'react';
import { routes } from '@/app/routes';
import { CarbonDataTable, ContainedTabList, EmptyState, FormErrors, IconTab } from '@/components/ui';
import { getJson } from '@/lib/http/json';
import { DockedPanel } from '@/components/panels';
import { AppLayout } from '@/layouts/AppLayout/AppLayout';
import { usePersistentTab } from '@/hooks/usePersistentTab';

interface FacilityOption {
  id: string;
  organizationId: string;
  name: string;
  capabilities: string[];
}

interface OrganizationOption {
  id: string;
  name: string;
}

interface StaffManagerAssignment {
  id: string;
  facilityId: string;
  facilityName: string;
  userName: string;
  userEmail: string;
}

interface FacilityRoleOption {
  key: string;
  label: string;
  requiredCapability?: string;
}

interface OrdinaryAssignment {
  id: string;
  facilityId: string;
  facilityName: string;
  userName: string;
  userEmail: string;
  roleKey: string;
  roleLabel: string;
  status: 'ACTIVE' | 'SUSPENDED';
}

interface FacilityRoleAssignment extends OrdinaryAssignment {
  source: 'ordinary' | 'staff-manager';
}

interface OrdinaryGrantAccount {
  id: string;
  name: string;
  email: string;
}

interface FacilityInvitation {
  id: string;
  userName: string;
  userEmail: string;
  roles: string[];
  facilityName: string;
  status: 'AWAITING_SETUP' | 'EXPIRED' | 'SETUP_COMPLETE';
  createdAt: string;
  canResend: boolean;
}

interface Promotion {
  id: string;
  facilityId: string | null;
  targetUserId: string;
  targetName: string | null;
  targetEmail: string | null;
  roleKey: string;
  scopeType: 'ORG' | 'FACILITY';
  scopeName: string | null;
  action: 'PROMOTE' | 'REVOKE';
  status: 'PENDING' | 'APPROVED' | 'REJECTED' | 'CANCELLED';
  reason: string;
  decisionReason: string | null;
  requestedBy: string;
  requestedByName: string | null;
  requestedByEmail: string | null;
  requestOrigin: string | null;
  decidedBy: string | null;
  decidedByName: string | null;
  createdAt: string;
}

interface RolePromotionsProps {
  facilities: FacilityOption[];
  organizations: OrganizationOption[];
  promotions: Promotion[];
  canRequest: boolean;
  total: number;
  statusCounts: Record<'ALL' | Promotion['status'], number>;
  statusFilter: 'ALL' | Promotion['status'];
  currentPage: number;
  lastPage: number;
  currentUserId: string;
  errors?: Record<string, string>;
  canInviteFacilityAdmins: boolean;
  canInviteFacilityUsers: boolean;
  invitableFacilityRoles: FacilityRoleOption[];
  facilityAdminInviteFacilities: FacilityOption[];
  staffAccessFacilities: FacilityOption[];
  staffManagerAssignments: StaffManagerAssignment[];
  facilityInvitations?: FacilityInvitation[];
  ordinaryRoleOptions?: FacilityRoleOption[];
  ordinaryAssignments?: OrdinaryAssignment[];
}

type PromotionPanel =
  | { mode: 'invite' }
  | { mode: 'resend-invitation'; invitation: FacilityInvitation }
  | { mode: 'request' }
  | { mode: 'staff-access'; assignment?: StaffManagerAssignment }
  | { mode: 'ordinary-grant' }
  | { mode: 'ordinary-revoke'; assignment: OrdinaryAssignment }
  | { mode: 'review'; promotion: Promotion };

const REQUEST_HEADERS = [
  { key: 'request', header: 'Access change' },
  { key: 'category', header: 'Category' },
  { key: 'target', header: 'User' },
  { key: 'scope', header: 'Scope' },
  { key: 'requestedBy', header: 'Requested by' },
  { key: 'submitted', header: 'Submitted' },
  { key: 'status', header: 'Status' },
  { key: 'action', header: '' },
];

type FacilityAssignmentStatusFilter = 'ALL' | OrdinaryAssignment['status'];

const INVITATION_HEADERS = [
  { key: 'invitee', header: 'Account' },
  { key: 'roles', header: 'Facility role' },
  { key: 'facility', header: 'Facility' },
  { key: 'invited', header: 'Invited' },
  { key: 'status', header: 'Setup status' },
  { key: 'action', header: '' },
];

const FACILITY_ASSIGNMENT_HEADERS = [
  { key: 'account', header: 'User account' },
  { key: 'role', header: 'Facility role' },
  { key: 'facility', header: 'Facility' },
  { key: 'status', header: 'Status' },
  { key: 'action', header: '' },
];

const ROLE_APPROVAL_TABS = ['requests', 'invitations', 'assignments'] as const;

/**
 * A decision changes only the access requests table and its counts. Keeping
 * the reload partial avoids replacing unrelated page props (invitations,
 * assignments, facility options) and makes the table update in place.
 */
const ROLE_APPROVAL_TABLE_PROPS = [
  'promotions',
  'total',
  'statusCounts',
  'statusFilter',
  'currentPage',
  'lastPage',
  'flash',
];

function roleName(roleKey: string): string {
  const labels: Record<string, string> = {
    tenant_admin: 'Tenant Admin',
    facility_admin: 'Facility Admin',
    facility_staff_manager: 'HR / Staff Manager',
    nurse: 'Nurse',
    doctor: 'Clinician',
    pharmacist: 'Pharmacist',
    laboratory_technician: 'Laboratory Technician',
    receptionist: 'Receptionist',
    cashier: 'Cashier',
    billing_admin: 'Billing',
  };
  return labels[roleKey] ?? roleKey.replaceAll('_', ' ');
}

function roleCategory(roleKey: string): string {
  if (roleKey === 'tenant_admin' || roleKey === 'facility_admin') return 'Admin role';
  if (roleKey === 'facility_staff_manager') return 'HR management';
  return 'Staff role';
}

function roleLabel(promotion: Promotion): string {
  const action = promotion.action === 'REVOKE' ? 'Revoke' : 'Grant';
  return `${action} ${roleName(promotion.roleKey)}`;
}

function personLabel(name: string | null, email: string | null): string {
  if (name && email) return `${name} · ${email}`;
  return name || email || 'User details unavailable';
}

function displayDate(value: string): string {
  return new Intl.DateTimeFormat(undefined, {
    dateStyle: 'medium',
    timeStyle: 'short',
  }).format(new Date(value));
}

function facilitySupportsRole(role: FacilityRoleOption, facility: FacilityOption | undefined): boolean {
  return facility !== undefined
    && (!role.requiredCapability || facility.capabilities.includes(role.requiredCapability));
}

function statusTagType(status: Promotion['status']): 'green' | 'red' | 'blue' {
  if (status === 'APPROVED') return 'green';
  if (status === 'REJECTED') return 'red';
  return 'blue';
}

function statusLabel(status: string): string {
  return status.charAt(0) + status.slice(1).toLowerCase();
}

function isOrdinaryGrantAccount(value: unknown): value is OrdinaryGrantAccount {
  return typeof value === 'object'
    && value !== null
    && 'id' in value && typeof value.id === 'string'
    && 'name' in value && typeof value.name === 'string'
    && 'email' in value && typeof value.email === 'string';
}

export default function AdminRolePromotions({
  facilities,
  organizations,
  promotions,
  canRequest,
  total,
  statusCounts,
  statusFilter,
  currentPage,
  lastPage,
  currentUserId,
  errors = {},
  canInviteFacilityAdmins,
  canInviteFacilityUsers,
  invitableFacilityRoles,
  facilityAdminInviteFacilities,
  staffAccessFacilities,
  staffManagerAssignments,
  facilityInvitations = [],
  ordinaryRoleOptions = [],
  ordinaryAssignments = [],
}: RolePromotionsProps): ReactNode {
  const tenantAdminRoleAvailable = organizations.length === 1;
  const [roleKey, setRoleKey] = useState<'tenant_admin' | 'facility_admin'>(
    facilities.length > 0 ? 'facility_admin' : 'tenant_admin',
  );
  const [targetEmail, setTargetEmail] = useState('');
  const [organizationId, setOrganizationId] = useState(organizations[0]?.id ?? '');
  const [facilityId, setFacilityId] = useState(facilities[0]?.id ?? '');
  const [reason, setReason] = useState('');
  const [panel, setPanel] = useState<PromotionPanel | null>(null);
  const [decisionReason, setDecisionReason] = useState('');
  const [decisionInFlight, setDecisionInFlight] = useState<'approve' | 'reject' | null>(null);
  const [copyFeedback, setCopyFeedback] = useState('');
  const [staffEmail, setStaffEmail] = useState('');
  const [staffFacilityId, setStaffFacilityId] = useState(staffAccessFacilities[0]?.id ?? '');
  const [ordinaryFacilityId, setOrdinaryFacilityId] = useState(staffAccessFacilities[0]?.id ?? '');
  const [ordinaryRoleKey, setOrdinaryRoleKey] = useState(ordinaryRoleOptions[0]?.key ?? '');
  const [ordinaryReason, setOrdinaryReason] = useState('');
  const [ordinaryAccountSearch, setOrdinaryAccountSearch] = useState('');
  const [ordinaryGrantAccounts, setOrdinaryGrantAccounts] = useState<OrdinaryGrantAccount[]>([]);
  const [selectedOrdinaryGrantAccount, setSelectedOrdinaryGrantAccount] = useState<OrdinaryGrantAccount | null>(null);
  const [ordinaryAccountSearchError, setOrdinaryAccountSearchError] = useState('');
  const [ordinaryAccountSearchLoading, setOrdinaryAccountSearchLoading] = useState(false);
  const [revokeReason, setRevokeReason] = useState('');
  const [selectedTab, setSelectedTab] = usePersistentTab('tab', ROLE_APPROVAL_TABS);
  const selectedRoleApprovalTab = ROLE_APPROVAL_TABS[selectedTab] ?? ROLE_APPROVAL_TABS[0];
  const [facilityAssignmentStatusFilter, setFacilityAssignmentStatusFilter] =
    useState<FacilityAssignmentStatusFilter>('ALL');
  const inviteForm = useForm({
    role_key: canInviteFacilityAdmins
      ? 'facility_admin'
      : invitableFacilityRoles[0]?.key ?? 'facility_staff_manager',
    facility_id: (canInviteFacilityAdmins
      ? facilityAdminInviteFacilities
      : staffAccessFacilities)[0]?.id ?? '',
    name: '',
    email: '',
    change_reference: '',
    reason: '',
  });
  const resendForm = useForm({
    change_reference: '',
    reason: '',
  });

  useEffect(() => {
    const search = ordinaryAccountSearch.trim();
    if (selectedOrdinaryGrantAccount !== null) {
      setOrdinaryAccountSearchLoading(false);
      return;
    }
    if (panel?.mode !== 'ordinary-grant' || search.length < 2 || ordinaryFacilityId === '') {
      setOrdinaryGrantAccounts([]);
      setOrdinaryAccountSearchLoading(false);
      setOrdinaryAccountSearchError('');
      return;
    }

    let isCurrent = true;
    setOrdinaryGrantAccounts([]);
    setOrdinaryAccountSearchLoading(true);
    setOrdinaryAccountSearchError('');
    const timeout = window.setTimeout(() => {
      const query = new URLSearchParams({ facility_id: ordinaryFacilityId, search });
      void getJson<unknown>(`${routes.workspaces.adminFacilityAssignmentTargets}?${query}`).then((payload) => {
        if (
          typeof payload !== 'object'
          || payload === null
          || !('data' in payload)
          || !Array.isArray(payload.data)
          || !payload.data.every(isOrdinaryGrantAccount)
        ) {
          throw new Error('The account search returned an unexpected response.');
        }
        if (isCurrent) setOrdinaryGrantAccounts(payload.data);
      }).catch((error: unknown) => {
        if (isCurrent) {
          setOrdinaryAccountSearchError(error instanceof Error
            ? error.message
            : 'Could not search existing tenant accounts. Try again.');
        }
      }).finally(() => {
        if (isCurrent) setOrdinaryAccountSearchLoading(false);
      });
    }, 250);

    return () => {
      isCurrent = false;
      window.clearTimeout(timeout);
    };
  }, [ordinaryAccountSearch, ordinaryFacilityId, panel?.mode, selectedOrdinaryGrantAccount]);

  const ordinaryRoleChoices = useMemo(() => ordinaryRoleOptions.filter((role) => facilitySupportsRole(
    role,
    staffAccessFacilities.find((facility) => facility.id === ordinaryFacilityId),
  )), [ordinaryRoleOptions, staffAccessFacilities, ordinaryFacilityId]);
  useEffect(() => {
    if (!ordinaryRoleChoices.some((role) => role.key === ordinaryRoleKey)) {
      setOrdinaryRoleKey(ordinaryRoleChoices[0]?.key ?? '');
    }
  }, [ordinaryFacilityId, ordinaryRoleChoices, ordinaryRoleKey]);

  const visibleFacilities = facilities.filter((facility) => (
    organizations.length === 0 || facility.organizationId === organizationId
  ));
  const rows = promotions.map((promotion) => ({
    id: promotion.id,
    request: roleLabel(promotion),
    category: roleCategory(promotion.roleKey),
    target: personLabel(promotion.targetName, promotion.targetEmail),
    scope: promotion.scopeName ?? (promotion.scopeType === 'ORG' ? 'Organization' : 'Facility'),
    requestedBy: personLabel(promotion.requestedByName, promotion.requestedByEmail),
    submitted: displayDate(promotion.createdAt),
    status: promotion.status,
    action: 'Review',
  }));
  const invitationRows = facilityInvitations.map((invitation) => ({
    id: invitation.id,
    invitee: personLabel(invitation.userName, invitation.userEmail),
    roles: invitation.roles.map(roleName).join(', ') || 'Facility role',
    facility: invitation.facilityName,
    invited: displayDate(invitation.createdAt),
    status: invitation.status,
    action: invitation.canResend ? 'Resend setup link' : '',
  }));
  const facilityRoleAssignments: FacilityRoleAssignment[] = [
    ...ordinaryAssignments.map((assignment) => ({ ...assignment, source: 'ordinary' as const })),
    ...staffManagerAssignments.map((assignment) => ({
      ...assignment,
      roleKey: 'facility_staff_manager',
      roleLabel: 'HR / Staff Manager',
      status: 'ACTIVE' as const,
      source: 'staff-manager' as const,
    })),
  ];
  const ordinaryAssignmentRows = facilityRoleAssignments.map((assignment) => ({
    id: assignment.id,
    account: personLabel(assignment.userName, assignment.userEmail),
    role: assignment.roleLabel,
    facility: assignment.facilityName,
    status: assignment.status,
    action: 'Revoke access',
  }));
  const filteredOrdinaryAssignmentRows = ordinaryAssignmentRows.filter((assignment) => (
    facilityAssignmentStatusFilter === 'ALL' || assignment.status === facilityAssignmentStatusFilter
  ));

  function closePanel(): void {
    setPanel(null);
    setDecisionReason('');
    setCopyFeedback('');
    setRevokeReason('');
  }

  async function copyIdentifier(identifier: string): Promise<void> {
    try {
      if (!navigator.clipboard?.writeText) {
        setCopyFeedback('Clipboard access is unavailable. Select and copy the identifier manually.');
        return;
      }
      await navigator.clipboard.writeText(identifier);
      setCopyFeedback('Identifier copied.');
    } catch {
      setCopyFeedback('Could not copy the identifier. Select and copy it manually.');
    }
  }

  function submitRequest(event: FormEvent): void {
    event.preventDefault();
    router.post(routes.workspaces.adminRolePromotions, {
      role_key: roleKey,
      target_email: targetEmail,
      ...(roleKey === 'tenant_admin' ? { organization_id: organizationId } : { facility_id: facilityId }),
      reason,
    }, {
      preserveScroll: true,
      onSuccess: () => {
        setTargetEmail('');
        setReason('');
        closePanel();
      },
    });
  }

  function submitInvite(event: FormEvent): void {
    event.preventDefault();
    inviteForm.post(routes.workspaces.adminFacilityUserInvitations, {
      preserveScroll: true,
      onSuccess: () => {
        inviteForm.reset('name', 'email', 'change_reference', 'reason');
        closePanel();
      },
    });
  }

  function submitResendInvitation(event: FormEvent): void {
    event.preventDefault();
    if (panel?.mode !== 'resend-invitation') return;

    resendForm.post(routes.workspaces.adminFacilityUserInvitationResend(panel.invitation.id), {
      preserveScroll: true,
      onSuccess: () => {
        resendForm.reset();
        closePanel();
      },
    });
  }

  function submitStaffAccess(event: FormEvent): void {
    event.preventDefault();
    router.post(routes.workspaces.adminFacilityStaffManagers, {
      facility_id: staffFacilityId,
      email: staffEmail,
    }, {
      preserveScroll: true,
      onSuccess: () => {
        setStaffEmail('');
        closePanel();
      },
    });
  }

  function revokeStaffAccess(assignmentId: string): void {
    router.delete(routes.workspaces.adminFacilityStaffManager(assignmentId), {
      preserveScroll: true,
      onSuccess: closePanel,
    });
  }

  function submitOrdinaryGrant(event: FormEvent): void {
    event.preventDefault();
    if (selectedOrdinaryGrantAccount === null) return;
    router.post(routes.workspaces.adminFacilityAssignments, {
      facility_id: ordinaryFacilityId,
      email: selectedOrdinaryGrantAccount.email,
      role_key: ordinaryRoleKey,
      reason: ordinaryReason,
    }, {
      preserveScroll: true,
      onSuccess: () => {
        setOrdinaryAccountSearch('');
        setSelectedOrdinaryGrantAccount(null);
        setOrdinaryReason('');
        closePanel();
      },
    });
  }

  function showOrdinaryGrantPanel(): void {
    setOrdinaryAccountSearch('');
    setSelectedOrdinaryGrantAccount(null);
    setOrdinaryGrantAccounts([]);
    setOrdinaryAccountSearchError('');
    setPanel({ mode: 'ordinary-grant' });
  }

  function submitOrdinaryRevoke(assignmentId: string): void {
    router.delete(routes.workspaces.adminFacilityAssignment(assignmentId), {
      data: { reason: revokeReason },
      preserveScroll: true,
      onSuccess: () => {
        setRevokeReason('');
        closePanel();
      },
    });
  }

  function approve(promotion: Promotion): void {
    setDecisionInFlight('approve');
    router.post(
      routes.workspaces.adminRolePromotionApprove(promotion.id),
      {},
      {
        preserveScroll: true,
        preserveState: true,
        only: ROLE_APPROVAL_TABLE_PROPS,
        onSuccess: closePanel,
        onFinish: () => setDecisionInFlight(null),
      },
    );
  }

  function reject(promotion: Promotion): void {
    setDecisionInFlight('reject');
    router.post(
      routes.workspaces.adminRolePromotionReject(promotion.id),
      { decision_reason: decisionReason },
      {
        preserveScroll: true,
        preserveState: true,
        only: ROLE_APPROVAL_TABLE_PROPS,
        onSuccess: closePanel,
        onFinish: () => setDecisionInFlight(null),
      },
    );
  }

  function showFacilityAdminRoleRequest(): void {
    setRoleKey('facility_admin');
    setPanel({ mode: 'request' });
  }

  function showTenantAdminRoleRequest(): void {
    setRoleKey('tenant_admin');
    setPanel({ mode: 'request' });
  }

  const requestPrimaryDisabled = targetEmail.trim() === ''
    || reason.trim().length < 8
    || (roleKey === 'tenant_admin' && organizationId === '')
    || (roleKey === 'facility_admin' && facilityId === '');
  const selectedPromotion = panel?.mode === 'review' ? panel.promotion : null;
  const roleInviteFacilities = inviteForm.data.role_key === 'facility_admin'
    ? facilityAdminInviteFacilities
    : staffAccessFacilities.filter((facility) => facilitySupportsRole(
      invitableFacilityRoles.find((role) => role.key === inviteForm.data.role_key) ?? { key: '', label: '' },
      facility,
    ));
  const selectedInviteFacility = roleInviteFacilities.find((facility) => facility.id === inviteForm.data.facility_id);
  const inviteRoleChoices = invitableFacilityRoles.filter((role) => {
    const candidateFacilities = role.key === 'facility_admin'
      ? facilityAdminInviteFacilities
      : staffAccessFacilities;
    return candidateFacilities.some((facility) => facility.id === inviteForm.data.facility_id)
      && facilitySupportsRole(role, selectedInviteFacility);
  });
  const canSelfApprove = canRequest
    && selectedPromotion !== null
    && selectedPromotion.status === 'PENDING'
    && selectedPromotion.requestedBy === currentUserId
    && selectedPromotion.targetUserId !== currentUserId
    && selectedPromotion.action === 'PROMOTE'
    && selectedPromotion.requestOrigin === 'FACILITY_INVITATION';
  const canDecide = selectedPromotion !== null
    && canRequest
    && selectedPromotion.status === 'PENDING'
    && selectedPromotion.targetUserId !== currentUserId
    && (selectedPromotion.requestedBy !== currentUserId || canSelfApprove);
  const technicalIdentifiers: [string, string][] = selectedPromotion ? [
    ['Target user', selectedPromotion.targetUserId],
    ['Requester', selectedPromotion.requestedBy],
    ...(selectedPromotion.decidedBy ? [['Approver', selectedPromotion.decidedBy] as [string, string]] : []),
  ] : [];

  return (
    <AppLayout>
      <div className="afyanova-page admin-role-promotions">
        <div className="admin-role-promotions__page-head">
          <h1>Role approvals</h1>
          <p className="admin-role-promotions__page-head-description">
            Manage access requests and facility roles.
          </p>
        </div>

        {Object.entries(errors).filter(([key]) => key !== 'email').length > 0 ? (
          <p role="alert" className="admin-role-promotions__page-error">{Object.entries(errors).filter(([key]) => key !== 'email').map(([, value]) => value).join(' ')}</p>
        ) : null}

        <Tabs
          selectedIndex={selectedTab}
          onChange={({ selectedIndex }) => setSelectedTab(selectedIndex)}
        >
          <ContainedTabList aria-label="Role approvals and access management">
            <IconTab icon={UserFollow}>Access requests</IconTab>
            <IconTab icon={Send}>Facility invitations ({facilityInvitations.length})</IconTab>
            <IconTab icon={UserSettings}>User role assignments ({facilityRoleAssignments.length})</IconTab>
          </ContainedTabList>
          <TabPanels>
            <TabPanel>
              <section aria-label="Access requests" className="admin-role-promotions__requests">
                <CarbonDataTable
                  rows={rows}
                  headers={REQUEST_HEADERS}
                  title={(
                    <div className="admin-role-promotions__table-title">
                      <span className="admin-role-promotions__table-heading">
                        Access requests
                      </span>
                      <span>{total} {total === 1 ? 'request' : 'requests'}</span>
                    </div>
                  )}
                  ariaLabel="Access requests"
                  toolbarLabel="Access requests table toolbar"
                  toolbar={(onInputChange) => (
                    <>
                      <TableToolbarSearch
                        persistent
                        onChange={onInputChange}
                        placeholder="Search access requests"
                        aria-label="Search access requests"
                      />
                      <TableToolbarMenu
                        renderIcon={Filter}
                        iconDescription={`Filter by status: ${statusFilter === 'ALL' ? 'All statuses' : statusLabel(statusFilter)}`}
                      >
                        <OverflowMenuItem itemText={`All statuses (${statusCounts.ALL})`} onClick={() => router.get(
                          routes.workspaces.adminRolePromotions,
                          { status: 'ALL', tab: selectedRoleApprovalTab },
                          { preserveScroll: true, preserveState: true, replace: true },
                        )} />
                        <OverflowMenuItem itemText={`Pending (${statusCounts.PENDING})`} onClick={() => router.get(
                          routes.workspaces.adminRolePromotions,
                          { status: 'PENDING', tab: selectedRoleApprovalTab },
                          { preserveScroll: true, preserveState: true, replace: true },
                        )} />
                        <OverflowMenuItem itemText={`Approved (${statusCounts.APPROVED})`} onClick={() => router.get(
                          routes.workspaces.adminRolePromotions,
                          { status: 'APPROVED', tab: selectedRoleApprovalTab },
                          { preserveScroll: true, preserveState: true, replace: true },
                        )} />
                        <OverflowMenuItem itemText={`Rejected (${statusCounts.REJECTED})`} onClick={() => router.get(
                          routes.workspaces.adminRolePromotions,
                          { status: 'REJECTED', tab: selectedRoleApprovalTab },
                          { preserveScroll: true, preserveState: true, replace: true },
                        )} />
                        <OverflowMenuItem itemText={`Cancelled (${statusCounts.CANCELLED})`} onClick={() => router.get(
                          routes.workspaces.adminRolePromotions,
                          { status: 'CANCELLED', tab: selectedRoleApprovalTab },
                          { preserveScroll: true, preserveState: true, replace: true },
                        )} />
                      </TableToolbarMenu>
                      {((canRequest && facilities.length > 0) || (canRequest && tenantAdminRoleAvailable)) ? (
                        <div className="admin-role-promotions__request-actions" aria-label="Request admin access">
                          {canRequest && facilities.length > 0 ? (
                            <Button size="lg" renderIcon={UserSettings} kind="primary" onClick={showFacilityAdminRoleRequest}>
                              Request Facility Admin assignment
                            </Button>
                          ) : null}
                          {canRequest && tenantAdminRoleAvailable ? (
                            <Button size="lg" renderIcon={UserSettings} kind="tertiary" onClick={showTenantAdminRoleRequest}>
                              Request Tenant Admin assignment
                            </Button>
                          ) : null}
                        </div>
                      ) : null}
                    </>
                  )}
                  emptyState={(
                    <EmptyState
                      title={promotions.length > 0
                        ? 'No requests match your search'
                        : statusFilter === 'ALL'
                          ? 'No access requests'
                          : `No ${statusLabel(statusFilter).toLowerCase()} requests`}
                      description={promotions.length > 0
                        ? 'Try a different search.'
                        : 'Requests with this status will appear here.'}
                    />
                  )}
                  renderCell={(cell, row) => {
                    const promotion = promotions.find((item) => item.id === row.id);
                    if (cell.info.header === 'status' && promotion) {
                      return <Tag type={statusTagType(promotion.status)}>{statusLabel(promotion.status)}</Tag>;
                    }
                    if (cell.info.header === 'action') {
                      return promotion?.status === 'PENDING' ? (
                        <Button
                          kind="ghost"
                          size="sm"
                          aria-label={`Review ${roleLabel(promotion)}`}
                          onClick={() => {
                            setDecisionReason('');
                            setPanel({ mode: 'review', promotion });
                          }}
                        >
                          Review
                        </Button>
                      ) : null;
                    }
                    return cell.value;
                  }}
                />
                {total > 0 ? (
                  <Pagination
                    id="admin-role-promotions-pagination"
                    aria-label="Access request pages"
                    page={currentPage}
                    pageSize={25}
                    pageSizes={[25]}
                    totalItems={total}
                    isLastPage={currentPage >= lastPage}
                    size="lg"
                    onChange={({ page }) => router.get(
                      routes.workspaces.adminRolePromotions,
                      { page, status: statusFilter, tab: selectedRoleApprovalTab },
                      { preserveScroll: true, preserveState: true, replace: true },
                    )}
                  />
                ) : null}
              </section>
            </TabPanel>
            <TabPanel>
              <section aria-labelledby="facility-invitations-heading" className="admin-role-promotions__requests">
                <CarbonDataTable
                  rows={invitationRows}
                  headers={INVITATION_HEADERS}
                  title={(
                    <div className="admin-role-promotions__table-title">
                      <span id="facility-invitations-heading" className="admin-role-promotions__table-heading">
                        Facility account invitations
                      </span>
                      <span>{facilityInvitations.length} {facilityInvitations.length === 1 ? 'invitation' : 'invitations'}</span>
                    </div>
                  )}
                  ariaLabel="Facility account invitations"
                  toolbarLabel="Facility invitation table toolbar"
                  toolbar={(onInputChange) => (
                    <>
                      <TableToolbarSearch
                        persistent
                        onChange={onInputChange}
                        placeholder="Search invitations"
                        aria-label="Search invitations"
                      />
                      {canInviteFacilityUsers ? (
                        <Button size="lg" renderIcon={UserFollow} onClick={() => setPanel({ mode: 'invite' })}>Invite facility account</Button>
                      ) : null}
                    </>
                  )}
                  emptyState={(
                    <EmptyState
                      title="No facility invitations"
                      description={canInviteFacilityUsers
                        ? 'Invitations for facility accounts will appear here.'
                        : 'No invitations are available in your facility scope.'}
                    />
                  )}
                  renderCell={(cell, row) => {
                    const invitation = facilityInvitations.find((item) => item.id === row.id);
                    if (cell.info.header === 'action') {
                      return invitation?.canResend ? (
                        <Button
                          kind="ghost"
                          size="sm"
                          aria-label={`Resend setup link to ${invitation.userEmail}`}
                          onClick={() => {
                            resendForm.reset();
                            setPanel({ mode: 'resend-invitation', invitation });
                          }}
                        >
                          Resend setup link
                        </Button>
                      ) : null;
                    }
                    if (cell.info.header !== 'status') return cell.value;

                    const status = invitation?.status ?? 'AWAITING_SETUP';
                    const label = status === 'SETUP_COMPLETE'
                      ? 'Setup complete'
                      : status === 'EXPIRED'
                        ? 'Expired'
                        : 'Awaiting setup';
                    const tagType = status === 'SETUP_COMPLETE' ? 'green' : status === 'EXPIRED' ? 'red' : 'blue';

                    return <Tag type={tagType}>{label}</Tag>;
                  }}
                />
              </section>
            </TabPanel>
            <TabPanel>
              {staffAccessFacilities.length > 0 ? (
                <section aria-labelledby="facility-ordinary-access-heading">
                  <CarbonDataTable
                    rows={filteredOrdinaryAssignmentRows}
                    headers={FACILITY_ASSIGNMENT_HEADERS}
                    title={(
                      <div className="admin-role-promotions__table-title">
                        <span id="facility-ordinary-access-heading" className="admin-role-promotions__table-heading">
                          Facility user role assignments
                        </span>
                        <span>{facilityRoleAssignments.length} {facilityRoleAssignments.length === 1 ? 'assignment' : 'assignments'}</span>
                      </div>
                    )}
                    ariaLabel="Facility user role assignments"
                    toolbarLabel="Facility user role assignments toolbar"
                    toolbar={(onInputChange) => (
                      <>
                        <TableToolbarSearch
                          persistent
                          onChange={onInputChange}
                          placeholder="Search role assignments"
                          aria-label="Search role assignments"
                        />
                        <TableToolbarMenu
                          renderIcon={Filter}
                          iconDescription={`Filter assignments: ${facilityAssignmentStatusFilter === 'ALL' ? 'All statuses' : statusLabel(facilityAssignmentStatusFilter)}`}
                        >
                          <OverflowMenuItem
                            itemText={`All statuses (${facilityRoleAssignments.length})`}
                            onClick={() => setFacilityAssignmentStatusFilter('ALL')}
                          />
                          <OverflowMenuItem
                            itemText={`Active (${facilityRoleAssignments.filter((assignment) => assignment.status === 'ACTIVE').length})`}
                            onClick={() => setFacilityAssignmentStatusFilter('ACTIVE')}
                          />
                          <OverflowMenuItem
                            itemText={`Suspended (${facilityRoleAssignments.filter((assignment) => assignment.status === 'SUSPENDED').length})`}
                            onClick={() => setFacilityAssignmentStatusFilter('SUSPENDED')}
                          />
                        </TableToolbarMenu>
                        {ordinaryRoleOptions.length > 0 ? (
                          <Button size="lg" renderIcon={UserSettings} onClick={showOrdinaryGrantPanel}>Grant facility role</Button>
                        ) : null}
                        {staffAccessFacilities.length > 0 ? (
                          <Button size="lg" renderIcon={UserSettings} kind="tertiary" onClick={() => setPanel({ mode: 'staff-access' })}>
                            Grant HR / Staff Manager role
                          </Button>
                        ) : null}
                      </>
                    )}
                    emptyState={(
                      <EmptyState
                        title={facilityRoleAssignments.length > 0
                          ? 'No role assignments match your search or status filter'
                          : 'No facility role assignments'}
                        description={facilityRoleAssignments.length > 0
                          ? 'Change the search or status filter.'
                          : 'Facility roles assigned to users will appear here.'}
                      />
                    )}
                    renderCell={(cell, row) => {
                      const assignment = facilityRoleAssignments.find((item) => item.id === row.id);
                      if (cell.info.header === 'status' && assignment) {
                        return (
                          <Tag type={assignment.status === 'ACTIVE' ? 'green' : 'blue'}>
                            {statusLabel(assignment.status)}
                          </Tag>
                        );
                      }
                      if (cell.info.header === 'action' && assignment) {
                        if (assignment.source === 'staff-manager') {
                          return (
                            <Button
                              kind="danger--tertiary"
                              size="sm"
                              aria-label={`Revoke ${assignment.roleLabel} for ${assignment.userName}`}
                              onClick={() => setPanel({ mode: 'staff-access', assignment })}
                            >
                              Revoke access
                            </Button>
                          );
                        }
                        return (
                          <Button
                            kind="danger--tertiary"
                            size="sm"
                            aria-label={`Revoke ${assignment.roleLabel} for ${assignment.userName}`}
                            onClick={() => {
                              setRevokeReason('');
                              setPanel({ mode: 'ordinary-revoke', assignment });
                            }}
                          >
                            Revoke access
                          </Button>
                        );
                      }
                      return cell.value;
                    }}
                  />
                </section>
              ) : (
                <p className="admin-role-promotions__empty-tab">
                  No facility scope is available for ordinary role management.
                </p>
              )}
            </TabPanel>
          </TabPanels>
        </Tabs>
      </div>

      {panel?.mode === 'invite' ? (
        <DockedPanel
          heading="Invite a facility account"
          onClose={closePanel}
          onSubmit={submitInvite}
          primaryLabel={inviteForm.processing ? 'Sending invitation…' : 'Send setup invitation'}
          primaryDisabled={inviteForm.processing || inviteForm.data.facility_id === ''}
        >
          <Stack gap={5}>
            <p className="admin-role-promotions__panel-intro">
              Create a facility account invitation and assign its facility role. Some roles require approval.
            </p>
            <FormErrors errors={inviteForm.errors} fields={['role_key', 'facility_id', 'name', 'email', 'change_reference', 'reason']} />
            <Select
              id="facility-user-invite-role"
              labelText="Facility role"
              value={inviteForm.data.role_key}
              onChange={(event) => {
                const role = event.target.value;
                const roleOption = invitableFacilityRoles.find((item) => item.key === role);
                const candidates = role === 'facility_admin'
                  ? facilityAdminInviteFacilities
                  : staffAccessFacilities;
                const roleFacilities = candidates.filter((facility) => (
                  roleOption === undefined || facilitySupportsRole(roleOption, facility)
                ));
                inviteForm.setData('role_key', role);
                inviteForm.setData(
                  'facility_id',
                  roleFacilities.some((facility) => facility.id === inviteForm.data.facility_id)
                    ? inviteForm.data.facility_id
                    : roleFacilities[0]?.id ?? '',
                );
              }}
              invalid={Boolean(inviteForm.errors.role_key)}
              invalidText={inviteForm.errors.role_key}
            >
              {inviteRoleChoices.map((role) => (
                <SelectItem key={role.key} value={role.key} text={role.label} />
              ))}
            </Select>
            <Select
              id="facility-user-invite-facility"
              labelText="Facility"
              value={inviteForm.data.facility_id}
              onChange={(event) => {
                const selectedFacilityId = event.target.value;
                const currentRole = invitableFacilityRoles.find((role) => role.key === inviteForm.data.role_key);
                const currentRoleFacilities = inviteForm.data.role_key === 'facility_admin'
                  ? facilityAdminInviteFacilities
                  : staffAccessFacilities;
                const currentRoleAllowed = currentRoleFacilities.some((facility) => (
                  facility.id === selectedFacilityId
                  && (currentRole === undefined || facilitySupportsRole(currentRole, facility))
                ));
                const availableRole = invitableFacilityRoles.find((role) => {
                  const candidates = role.key === 'facility_admin'
                    ? facilityAdminInviteFacilities
                    : staffAccessFacilities;
                  return candidates.some((facility) => facility.id === selectedFacilityId)
                    && facilitySupportsRole(
                      role,
                      candidates.find((facility) => facility.id === selectedFacilityId),
                    );
                });
                inviteForm.setData({
                  facility_id: selectedFacilityId,
                  role_key: currentRoleAllowed
                    ? inviteForm.data.role_key
                    : availableRole?.key ?? inviteForm.data.role_key,
                });
              }}
              invalid={Boolean(inviteForm.errors.facility_id)}
              invalidText={inviteForm.errors.facility_id}
            >
              {roleInviteFacilities.map((facility) => (
                <SelectItem key={facility.id} value={facility.id} text={facility.name} />
              ))}
            </Select>
            <TextInput
              id="facility-user-invite-name"
              labelText="Full name"
              value={inviteForm.data.name}
              onChange={(event) => inviteForm.setData('name', event.target.value)}
              invalid={Boolean(inviteForm.errors.name)}
              invalidText={inviteForm.errors.name}
              required
            />
            <TextInput
              id="facility-user-invite-email"
              type="email"
              labelText="Email address"
              value={inviteForm.data.email}
              onChange={(event) => inviteForm.setData('email', event.target.value)}
              invalid={Boolean(inviteForm.errors.email)}
              invalidText={inviteForm.errors.email}
              required
            />
            <TextInput
              id="facility-user-invite-reference"
              labelText="Non-secret change/ticket reference"
              value={inviteForm.data.change_reference}
              onChange={(event) => inviteForm.setData('change_reference', event.target.value)}
              invalid={Boolean(inviteForm.errors.change_reference)}
              invalidText={inviteForm.errors.change_reference}
              required
            />
            <TextArea
              id="facility-user-invite-reason"
              labelText="Reason for the access request"
              value={inviteForm.data.reason}
              onChange={(event) => inviteForm.setData('reason', event.target.value)}
              rows={3}
              maxLength={500}
              invalid={Boolean(inviteForm.errors.reason)}
              invalidText={inviteForm.errors.reason}
              required
            />
          </Stack>
        </DockedPanel>
      ) : null}
      {panel?.mode === 'resend-invitation' ? (
        <DockedPanel
          heading="Resend setup link"
          onClose={closePanel}
          onSubmit={submitResendInvitation}
          primaryLabel={resendForm.processing ? 'Resending…' : 'Resend invitation'}
          primaryDisabled={resendForm.processing}
        >
          <Stack gap={5}>
            <p className="admin-role-promotions__panel-intro">
              Send a new 60-minute setup link to {panel.invitation.userEmail}. The expired link will stop working.
            </p>
            <p>{panel.invitation.roles.map(roleName).join(', ')} · {panel.invitation.facilityName}</p>
            <FormErrors errors={resendForm.errors} fields={['invitation', 'change_reference', 'reason']} />
            <TextInput
              id="facility-invitation-resend-reference"
              labelText="Non-secret change/ticket reference"
              value={resendForm.data.change_reference}
              onChange={(event) => resendForm.setData('change_reference', event.target.value)}
              invalid={Boolean(resendForm.errors.change_reference)}
              invalidText={resendForm.errors.change_reference}
              required
            />
            <TextArea
              id="facility-invitation-resend-reason"
              labelText="Reason for resending"
              value={resendForm.data.reason}
              onChange={(event) => resendForm.setData('reason', event.target.value)}
              invalid={Boolean(resendForm.errors.reason)}
              invalidText={resendForm.errors.reason}
              rows={3}
              required
            />
          </Stack>
        </DockedPanel>
      ) : null}

      <Modal
        open={panel?.mode === 'staff-access' && panel.assignment !== undefined}
        danger
        modalHeading="Revoke HR / Staff Manager role?"
        primaryButtonText="Revoke access"
        secondaryButtonText="Cancel"
        onRequestClose={closePanel}
        onRequestSubmit={() => {
          if (panel?.mode === 'staff-access' && panel.assignment) {
            revokeStaffAccess(panel.assignment.id);
          }
        }}
      >
        {panel?.mode === 'staff-access' && panel.assignment ? (
          <>
            <p>
              Revoke HR / Staff Manager access for <strong>{panel.assignment.userName}</strong> at{' '}
              <strong>{panel.assignment.facilityName}</strong>?
            </p>
            <p className="admin-role-promotions__panel-intro">
              They will lose access to this facility&apos;s HR workspace. Their other facility roles will remain unchanged.
            </p>
          </>
        ) : null}
      </Modal>

      {panel?.mode === 'request' ? (
        <DockedPanel
          heading={roleKey === 'tenant_admin' ? 'Request Tenant Admin assignment' : 'Request Facility Admin assignment'}
          onClose={closePanel}
          onSubmit={submitRequest}
          primaryLabel="Send for approval"
          primaryDisabled={requestPrimaryDisabled}
        >
          <Stack gap={5}>
            <p className="admin-role-promotions__panel-intro">
              Assign this role to another account. A different administrator must approve.
            </p>
            <TextInput
              id="promotion-target-email"
              type="email"
              labelText="Existing account email"
              value={targetEmail}
              onChange={(event) => setTargetEmail(event.target.value)}
              required
            />
            {roleKey === 'tenant_admin' ? (
              <Select
                id="promotion-organization"
                labelText="Organization"
                value={organizationId}
                onChange={(event) => setOrganizationId(event.target.value)}
              >
                {organizations.map((organization) => (
                  <SelectItem key={organization.id} value={organization.id} text={organization.name} />
                ))}
              </Select>
            ) : (
              <Select
                id="promotion-facility"
                labelText="Facility"
                value={facilityId}
                onChange={(event) => setFacilityId(event.target.value)}
              >
                {visibleFacilities.map((facility) => (
                  <SelectItem key={facility.id} value={facility.id} text={facility.name} />
                ))}
              </Select>
            )}
            <TextArea
              id="promotion-reason"
              labelText="Reason for promotion"
              helperText="At least 8 characters."
              value={reason}
              onChange={(event) => setReason(event.target.value)}
              rows={4}
              maxLength={500}
              required
            />
          </Stack>
        </DockedPanel>
      ) : null}

      {panel?.mode === 'staff-access' && !panel.assignment ? (
        <DockedPanel
          heading="Grant HR / Staff Manager role"
          onClose={closePanel}
          onSubmit={submitStaffAccess}
          primaryLabel="Grant access"
          primaryDisabled={staffEmail.trim() === '' || staffFacilityId === ''}
        >
          <Stack gap={5}>
            <p className="admin-role-promotions__panel-intro">
              This role opens the facility HR workspace to manage staff profiles, employment, assignments, and staff-to-account links. It adds the role to an existing user who already has access to this facility; it does not create an account.
            </p>
            <Select
              id="facility-staff-access-facility"
              labelText="Facility"
              value={staffFacilityId}
              onChange={(event) => setStaffFacilityId(event.target.value)}
            >
              {staffAccessFacilities.map((facility) => (
                <SelectItem key={facility.id} value={facility.id} text={facility.name} />
              ))}
            </Select>
            <TextInput
              id="facility-staff-access-email"
              type="email"
              labelText="Existing facility user email"
              helperText="The account must be active, verified, and already assigned to this facility."
              value={staffEmail}
              onChange={(event) => setStaffEmail(event.target.value)}
              required
            />
          </Stack>
        </DockedPanel>
      ) : null}

      {panel?.mode === 'ordinary-grant' ? (
        <DockedPanel
          heading="Grant ordinary facility role"
          onClose={closePanel}
          onSubmit={submitOrdinaryGrant}
          primaryLabel="Grant access"
          primaryDisabled={selectedOrdinaryGrantAccount === null || ordinaryFacilityId === '' || ordinaryRoleKey === '' || ordinaryReason.trim().length < 8}
        >
          <Stack gap={5}>
            <p className="admin-role-promotions__panel-intro">
              Assign a staff role to an existing, active, verified account in this tenant. The role applies only to the selected facility.
            </p>
            <FormErrors errors={errors} fields={['facility_id', 'email', 'role_key', 'reason']} />
            <Select
              id="ordinary-grant-facility"
              labelText="Facility"
              value={ordinaryFacilityId}
              onChange={(event) => {
                setOrdinaryFacilityId(event.target.value);
                setOrdinaryAccountSearch('');
                setSelectedOrdinaryGrantAccount(null);
              }}
            >
              {staffAccessFacilities.map((facility) => (
                <SelectItem key={facility.id} value={facility.id} text={facility.name} />
              ))}
            </Select>
            <Select
              id="ordinary-grant-role"
              labelText="Ordinary role"
              value={ordinaryRoleKey}
              onChange={(event) => setOrdinaryRoleKey(event.target.value)}
            >
              {ordinaryRoleChoices.map((role) => (
                <SelectItem key={role.key} value={role.key} text={role.label} />
              ))}
            </Select>
            <ComboBox<OrdinaryGrantAccount>
              id="ordinary-grant-email"
              titleText="Existing tenant account"
              helperText="Search by name or email. Only active, verified accounts are listed."
              items={ordinaryGrantAccounts}
              selectedItem={selectedOrdinaryGrantAccount}
              itemToString={(account) => account ? `${account.name} · ${account.email}` : ''}
              onInputChange={(value) => {
                setOrdinaryAccountSearch(value);
                setSelectedOrdinaryGrantAccount((current) => (
                  current && value === `${current.name} · ${current.email}` ? current : null
                ));
              }}
              onChange={({ selectedItem }) => {
                setSelectedOrdinaryGrantAccount(selectedItem ?? null);
                setOrdinaryAccountSearch(selectedItem ? `${selectedItem.name} · ${selectedItem.email}` : '');
              }}
              placeholder="Type at least 2 characters"
              shouldFilterItem={() => true}
              invalid={Boolean(errors.email)}
              invalidText={errors.email}
            />
            {ordinaryAccountSearchLoading ? <p role="status">Searching tenant accounts…</p> : null}
            {ordinaryAccountSearchError ? <p role="alert">{ordinaryAccountSearchError}</p> : null}
            {!ordinaryAccountSearchLoading
              && !ordinaryAccountSearchError
              && ordinaryAccountSearch.trim().length >= 2
              && ordinaryGrantAccounts.length === 0 ? (
              <p role="status">No eligible account matched that search.</p>
            ) : null}
            <TextArea
              id="ordinary-grant-reason"
              labelText="Reason"
              helperText="At least 8 characters."
              value={ordinaryReason}
              onChange={(event) => setOrdinaryReason(event.target.value)}
              rows={3}
              maxLength={500}
              required
            />
          </Stack>
        </DockedPanel>
      ) : null}

      {panel?.mode === 'ordinary-revoke' ? (
        <Modal
          open
          danger
          modalHeading={`Revoke ${panel.assignment.roleLabel} role?`}
          primaryButtonText="Revoke access"
          primaryButtonDisabled={revokeReason.trim().length < 8}
          secondaryButtonText="Cancel"
          onRequestClose={closePanel}
          onRequestSubmit={() => submitOrdinaryRevoke(panel.assignment.id)}
        >
          <Stack gap={5}>
            <p>
              Revoke {panel.assignment.roleLabel} access for <strong>{panel.assignment.userName}</strong> at{' '}
              <strong>{panel.assignment.facilityName}</strong>?
            </p>
            <p className="admin-role-promotions__panel-intro">
              Their access for this facility will end, and their active sessions will be revoked.
            </p>
            <TextInput
              id="ordinary-revoke-reason"
              labelText="Reason"
              helperText="Required. At least 8 characters."
              value={revokeReason}
              onChange={(event) => setRevokeReason(event.target.value)}
              maxLength={500}
              required
            />
          </Stack>
        </Modal>
      ) : null}

      {selectedPromotion ? (
        <DockedPanel
          heading="Review access request"
          width="min(40rem, 100%)"
          onClose={closePanel}
          onSubmit={canDecide ? (event) => {
            event.preventDefault();
            approve(selectedPromotion);
          } : undefined}
          primaryLabel={canDecide
            ? decisionInFlight === 'approve'
              ? 'Approving…'
              : selectedPromotion.action === 'REVOKE'
                ? 'Approve revocation'
                : canSelfApprove
                  ? 'Approve invited access'
                  : 'Approve promotion'
            : undefined}
          primaryDisabled={decisionInFlight !== null}
        >
          <div className="admin-role-promotions__review">
            <header
              className="admin-role-promotions__review-summary"
              aria-labelledby="admin-role-promotions-review-title"
            >
              <div className="admin-role-promotions__review-summary-head">
                <div className="admin-role-promotions__review-heading">
                  <p className="afy-eyebrow">
                    {selectedPromotion.action === 'REVOKE' ? 'Access revocation' : 'Access request'}
                    {' · '}
                    {roleCategory(selectedPromotion.roleKey)}
                  </p>
                  <h3
                    id="admin-role-promotions-review-title"
                    className="admin-role-promotions__review-title"
                  >
                    {roleLabel(selectedPromotion)}
                  </h3>
                  <p className="admin-role-promotions__review-target">
                    <strong>{personLabel(selectedPromotion.targetName, selectedPromotion.targetEmail)}</strong>
                  </p>
                </div>
                <Tag type={statusTagType(selectedPromotion.status)}>
                  {statusLabel(selectedPromotion.status)}
                </Tag>
              </div>

              <dl className="admin-role-promotions__review-metadata">
                <div>
                  <dt>Scope</dt>
                  <dd>
                    {selectedPromotion.scopeName
                      ?? (selectedPromotion.scopeType === 'ORG' ? 'Organization' : 'Facility')}
                    <span className="admin-role-promotions__review-fact-note">
                      {' · '}
                      {selectedPromotion.scopeType === 'ORG' ? 'Organization-wide' : 'Facility'}
                    </span>
                  </dd>
                </div>
                <div>
                  <dt>Requested by</dt>
                  <dd>{personLabel(selectedPromotion.requestedByName, selectedPromotion.requestedByEmail)}</dd>
                </div>
                {selectedPromotion.requestOrigin === 'FACILITY_INVITATION' ? (
                  <div>
                    <dt>Source</dt>
                    <dd>Facility Admin invitation</dd>
                  </div>
                ) : null}
                <div>
                  <dt>Submitted</dt>
                  <dd>
                    <time dateTime={selectedPromotion.createdAt}>{displayDate(selectedPromotion.createdAt)}</time>
                  </dd>
                </div>
              </dl>
            </header>

            {canSelfApprove ? (
              <div className="admin-role-promotions__review-notice">
                <InlineNotification
                  kind="warning"
                  lowContrast
                  title="You are approving the access request from your invitation"
                  subtitle="The invited facility role will be activated without another administrator. This decision is recorded in the audit log."
                  hideCloseButton
                />
              </div>
            ) : null}

            {selectedPromotion.status === 'PENDING' && !canDecide ? (
              <p className="admin-role-promotions__review-hint">
                <Information size={16} aria-hidden="true" />
                <span>
                  Another administrator must decide this request. You cannot approve or reject a
                  request you raised or that affects your own access.
                </span>
              </p>
            ) : null}

            <section
              className="admin-role-promotions__review-section"
              aria-labelledby="admin-role-promotions-review-reason"
            >
              <h4
                id="admin-role-promotions-review-reason"
                className="admin-role-promotions__review-section-title"
              >
                Reason for request
              </h4>
              <blockquote className="admin-role-promotions__review-reason">
                <p>{selectedPromotion.reason}</p>
              </blockquote>
            </section>

            {selectedPromotion.decidedBy || selectedPromotion.decisionReason ? (
              <section
                className="admin-role-promotions__review-section"
                aria-labelledby="admin-role-promotions-review-decision-record"
              >
                <h4
                  id="admin-role-promotions-review-decision-record"
                  className="admin-role-promotions__review-section-title"
                >
                  Decision record
                </h4>
                <dl className="admin-role-promotions__review-metadata">
                  {selectedPromotion.decidedBy ? (
                    <div>
                      <dt>Decided by</dt>
                      <dd>{selectedPromotion.decidedByName ?? 'User details unavailable'}</dd>
                    </div>
                  ) : null}
                  {selectedPromotion.decisionReason ? (
                    <div>
                      <dt>Decision reason</dt>
                      <dd>{selectedPromotion.decisionReason}</dd>
                    </div>
                  ) : null}
                </dl>
              </section>
            ) : null}

            <details className="admin-role-promotions__technical-details">
              <summary className="admin-role-promotions__technical-summary">
                <span className="admin-role-promotions__technical-summary-label">
                  <span>Technical identifiers</span>
                  <span className="admin-role-promotions__technical-hint">Audit reference</span>
                </span>
              </summary>              <div className="admin-role-promotions__identifier-list">
                {technicalIdentifiers.map(([label, identifier]) => (
                  <div key={label} className="admin-role-promotions__identifier-row">
                    <span><strong>{label}:</strong> <code>{identifier}</code></span>
                    <Button type="button" kind="ghost" size="sm" onClick={() => void copyIdentifier(identifier)}>
                      Copy
                    </Button>
                  </div>
                ))}
                {copyFeedback ? <p role="status">{copyFeedback}</p> : null}
              </div>
            </details>

            {canDecide ? (
              <section
                className="admin-role-promotions__review-decision"
                aria-labelledby="admin-role-promotions-review-decision"
              >
                <div className="admin-role-promotions__review-decision-heading">
                  <h4
                    id="admin-role-promotions-review-decision"
                    className="admin-role-promotions__review-decision-title"
                  >
                    {canSelfApprove ? 'Withdraw this request' : 'Reject this request'}
                  </h4>
                  <p className="admin-role-promotions__review-decision-note">
                    Add a reason to record the rejection. The reason is kept in the audit log.
                  </p>
                </div>
                <TextArea
                  id={`promotion-rejection-${selectedPromotion.id}`}
                  labelText={canSelfApprove ? 'Reason for withdrawal' : 'Reason for rejection'}
                  helperText="Required. At least 8 characters."
                  value={decisionReason}
                  onChange={(event) => setDecisionReason(event.target.value)}
                  rows={2}
                  maxLength={500}
                />
                <Button size="md" renderIcon={Close}
                  type="button"
                  kind="danger"
                  disabled={decisionInFlight !== null || decisionReason.trim().length < 8}
                  onClick={() => reject(selectedPromotion)}
                >
                  {decisionInFlight === 'reject' ? 'Submitting…' : canSelfApprove ? 'Withdraw request' : 'Reject request'}
                </Button>
              </section>
            ) : null}
          </div>
        </DockedPanel>
      ) : null}
    </AppLayout>
  );
}

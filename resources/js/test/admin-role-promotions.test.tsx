import { fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import AdminRolePromotions from '@/pages/Admin/Identity/RolePromotions';

const state = vi.hoisted(() => ({
  post: vi.fn(),
  delete: vi.fn(),
  get: vi.fn(),
}));

vi.mock('@inertiajs/react', async () => {
  const { useState } = await import('react');

  return {
    router: { post: state.post, delete: state.delete, get: state.get },
    useForm: (initial: Record<string, string>) => {
      const [data, setState] = useState(initial);
      return {
        data,
        setData: (keyOrData: string | Record<string, string>, value?: string) => setState((current) => (
          typeof keyOrData === 'string'
            ? { ...current, [keyOrData]: value ?? '' }
            : { ...current, ...keyOrData }
        )),
        post: (url: string, options: Record<string, unknown>) => state.post(url, data, options),
        processing: false,
        errors: {},
        reset: vi.fn(),
      };
    },
  };
});

vi.mock('@/layouts/AppLayout/AppLayout', () => ({
  AppLayout: ({ children }: { children: React.ReactNode }) => <>{children}</>,
}));

const promotion = {
  id: 'request-1',
  facilityId: 'facility-1',
  targetUserId: 'target-user-1',
  targetName: 'Sam Example',
  targetEmail: 'sam@example.test',
  roleKey: 'facility_admin' as const,
  scopeType: 'FACILITY' as const,
  scopeName: 'Central Clinic',
  action: 'PROMOTE' as const,
  status: 'PENDING' as const,
  reason: 'Manage daily facility operations.',
  decisionReason: null,
  requestedBy: 'requester-1',
  requestedByName: 'Alex Requester',
  requestedByEmail: 'alex@example.test',
  requestOrigin: null,
  decidedBy: null,
  decidedByName: null,
  createdAt: '2026-10-08T09:30:00Z',
};

const props = {
  facilities: [{ id: 'facility-1', organizationId: 'organization-1', name: 'Central Clinic', capabilities: [] }],
  organizations: [{ id: 'organization-1', name: 'Central Health' }],
  promotions: [promotion],
  canRequest: true,
  total: 1,
  statusCounts: { ALL: 1, PENDING: 1, APPROVED: 0, REJECTED: 0, CANCELLED: 0 },
  statusFilter: 'ALL' as const,
  currentPage: 1,
  lastPage: 1,
  currentUserId: 'approver-1',
  canInviteFacilityAdmins: true,
  canInviteFacilityUsers: true,
  invitableFacilityRoles: [
    { key: 'facility_admin', label: 'Facility Admin' },
    { key: 'facility_staff_manager', label: 'HR / Staff Manager' },
    { key: 'nurse', label: 'Nurse' },
  ],
  facilityAdminInviteFacilities: [{ id: 'facility-1', organizationId: 'organization-1', name: 'Central Clinic', capabilities: [] }],
  staffAccessFacilities: [{ id: 'facility-1', organizationId: 'organization-1', name: 'Central Clinic', capabilities: [] }],
  staffManagerAssignments: [{
    id: 'staff-access-1',
    facilityId: 'facility-1',
    facilityName: 'Central Clinic',
    userName: 'Sam Example',
    userEmail: 'sam@example.test',
  }],
  ordinaryAssignments: [
    {
      id: 'ordinary-assignment-1',
      facilityId: 'facility-1',
      facilityName: 'Central Clinic',
      userName: 'Nuru Nurse',
      userEmail: 'nuru@example.test',
      roleKey: 'nurse',
      roleLabel: 'Nurse',
      status: 'ACTIVE' as const,
    },
    {
      id: 'ordinary-assignment-2',
      facilityId: 'facility-1',
      facilityName: 'Central Clinic',
      userName: 'Asha Receptionist',
      userEmail: 'asha@example.test',
      roleKey: 'receptionist',
      roleLabel: 'Receptionist',
      status: 'SUSPENDED' as const,
    },
  ],
  ordinaryRoleOptions: [
    { key: 'nurse', label: 'Nurse' },
    { key: 'receptionist', label: 'Receptionist' },
  ],
  facilityInvitations: [{
    id: 'invitation-1',
    userName: 'Nuru Nurse',
    userEmail: 'nuru@example.test',
    roles: ['Nurse', 'Receptionist'],
    facilityName: 'Central Clinic',
    status: 'AWAITING_SETUP' as const,
    createdAt: '2026-10-08T09:30:00Z',
    canResend: false,
  }],
};

describe('Admin role approvals workspace', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    vi.unstubAllGlobals();
    window.history.replaceState({}, '', '/');
    window.matchMedia = vi.fn().mockImplementation((query: string) => ({
      matches: false,
      media: query,
      onchange: null,
      addListener: vi.fn(),
      removeListener: vi.fn(),
      addEventListener: vi.fn(),
      removeEventListener: vi.fn(),
      dispatchEvent: vi.fn(),
    }));
    globalThis.ResizeObserver ??= class {
      observe(): void {}
      unobserve(): void {}
      disconnect(): void {}
    };
    Object.defineProperty(navigator, 'clipboard', {
      configurable: true,
      value: { writeText: vi.fn().mockResolvedValue(undefined) },
    });
  });

  it('shows a searchable Carbon request table with identifiable users and scopes', () => {
    render(<AdminRolePromotions {...props} />);

    expect(screen.getByRole('heading', { name: 'Role approvals' })).toBeInTheDocument();
    expect(screen.getByRole('heading', { name: 'Access requests 1 request' })).toBeInTheDocument();
    const requestsTable = screen.getByRole('table', { name: 'Access requests 1 request' });
    expect(within(requestsTable).getByRole('columnheader', { name: 'Access change' })).toBeInTheDocument();
    expect(within(requestsTable).getByRole('columnheader', { name: 'Category' })).toBeInTheDocument();
    expect(within(requestsTable).getByText('Sam Example · sam@example.test')).toBeInTheDocument();
    expect(within(requestsTable).getByText('Central Clinic')).toBeInTheDocument();
    expect(screen.queryByText('target-user-1')).not.toBeInTheDocument();
    expect(screen.queryByText('requester-1')).not.toBeInTheDocument();
    const search = screen.getByRole('searchbox', { name: 'Search access requests' });
    const statusFilter = screen.getByRole('button', { name: 'Filter by status: All statuses' });
    const requestAction = screen.getByRole('button', { name: 'Request Facility Admin assignment' });
    expect(search.compareDocumentPosition(statusFilter) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
    expect(statusFilter.compareDocumentPosition(requestAction) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();

    fireEvent.change(search, {
      target: { value: 'no matching user' },
    });
    expect(screen.getByRole('heading', { name: 'No requests match your search' })).toBeInTheDocument();
    expect(within(requestsTable).queryByText('Sam Example · sam@example.test')).not.toBeInTheDocument();
  });

  it('groups ordinary and HR facility assignments in one user role table', () => {
    render(<AdminRolePromotions {...props} />);

    expect(screen.getByRole('tab', { name: /Access requests/ })).toHaveAttribute('aria-selected', 'true');
    expect(screen.queryByRole('tab', { name: /HR staff access/ })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Invite facility account' })).not.toBeInTheDocument();

    fireEvent.click(screen.getByRole('tab', { name: /Facility invitations/ }));
    expect(screen.getByRole('heading', { name: /Facility account invitations/ })).toBeInTheDocument();
    const invitationsTable = screen.getByRole('table', { name: 'Facility account invitations 1 invitation' });
    expect(within(invitationsTable).getByText('Nuru Nurse · nuru@example.test')).toBeInTheDocument();
    expect(within(invitationsTable).getByText('Nurse, Receptionist')).toBeInTheDocument();
    expect(within(invitationsTable).getByText('Central Clinic')).toBeInTheDocument();
    expect(within(invitationsTable).getByText('Awaiting setup')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Invite facility account' })).toBeInTheDocument();

    fireEvent.click(screen.getByRole('tab', { name: /User role assignments/ }));
    const assignmentsTable = screen.getByRole('table', { name: 'Facility user role assignments 3 assignments' });
    expect(within(assignmentsTable).getByRole('columnheader', { name: 'User account' })).toBeInTheDocument();
    expect(within(assignmentsTable).getByRole('columnheader', { name: 'Facility role' })).toBeInTheDocument();
    expect(within(assignmentsTable).getByText('Nuru Nurse · nuru@example.test')).toBeInTheDocument();
    expect(within(assignmentsTable).getByText('Nurse')).toBeInTheDocument();
    expect(within(assignmentsTable).getByText('Sam Example · sam@example.test')).toBeInTheDocument();
    expect(within(assignmentsTable).getByText('HR / Staff Manager')).toBeInTheDocument();
    expect(within(assignmentsTable).getByText('Asha Receptionist · asha@example.test')).toBeInTheDocument();
    expect(within(assignmentsTable).getAllByText('Central Clinic')).toHaveLength(3);
    expect(within(assignmentsTable).getAllByText('Active')).toHaveLength(2);
  });

  it('restores the selected workspace tab from the URL', () => {
    window.history.replaceState({}, '', '/workspaces/admin/identity/role-promotions?tab=assignments');
    render(<AdminRolePromotions {...props} />);

    expect(screen.getByRole('tab', { name: /User role assignments/ })).toHaveAttribute('aria-selected', 'true');
    expect(screen.getByRole('table', { name: 'Facility user role assignments 3 assignments' })).toBeInTheDocument();
  });

  it('provides Carbon search and status filtering for facility role assignments', () => {
    render(<AdminRolePromotions {...props} />);
    fireEvent.click(screen.getByRole('tab', { name: /User role assignments/ }));

    const search = screen.getByRole('searchbox', { name: 'Search role assignments' });
    const filter = screen.getByRole('button', { name: 'Filter assignments: All statuses' });
    const grantButton = screen.getByRole('button', { name: 'Grant facility role' });
    expect(search.compareDocumentPosition(filter) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
    expect(filter.compareDocumentPosition(grantButton) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
    fireEvent.change(search, { target: { value: 'Asha Receptionist' } });
    const assignmentsTable = screen.getByRole('table', { name: 'Facility user role assignments 3 assignments' });
    expect(within(assignmentsTable).getByText('Asha Receptionist · asha@example.test')).toBeInTheDocument();
    expect(within(assignmentsTable).queryByText('Nuru Nurse · nuru@example.test')).not.toBeInTheDocument();

    fireEvent.click(screen.getByRole('button', { name: 'Clear search input' }));
    expect(within(assignmentsTable).getByText('Nuru Nurse · nuru@example.test')).toBeInTheDocument();
    fireEvent.click(filter);
    const statusItems = screen.getAllByRole('menuitem', { hidden: true });
    expect(statusItems[2]).toHaveTextContent('Suspended (1)');
    fireEvent.click(statusItems[2]!);
    expect(screen.getByRole('button', { name: 'Filter assignments: Suspended' })).toBeInTheDocument();
    expect(within(assignmentsTable).getByText('Asha Receptionist · asha@example.test')).toBeInTheDocument();
    expect(within(assignmentsTable).queryByText('Nuru Nurse · nuru@example.test')).not.toBeInTheDocument();
  });

  it('grants an ordinary role only to an account selected from tenant search results', async () => {
    const user = userEvent.setup();
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue({
      ok: true,
      json: async () => ({
        data: [{ id: 'user-nuru', name: 'Nuru Nurse', email: 'nuru@example.test' }],
      }),
    }));
    render(<AdminRolePromotions {...props} />);
    await user.click(screen.getByRole('tab', { name: /User role assignments/ }));
    await user.click(screen.getByRole('button', { name: 'Grant facility role' }));

    const grantButton = screen.getByRole('button', { name: 'Grant access' });
    expect(grantButton).toBeDisabled();
    const accountSearch = screen.getByRole('combobox', { name: 'Existing tenant account' });
    await user.type(accountSearch, 'nuru');
    expect(accountSearch).toHaveValue('nuru');
    expect(grantButton).toBeDisabled();

    await waitFor(() => expect(fetch).toHaveBeenCalledWith(
      expect.stringContaining('facility_id=facility-1&search=nuru'),
      expect.any(Object),
    ));
    await user.click(await screen.findByRole('option', { name: /Nuru Nurse.*nuru@example\.test/ }));
    await user.type(screen.getByLabelText('Reason'), 'Assign nursing duties at this facility.');
    expect(grantButton).toBeEnabled();
    fireEvent.click(grantButton);

    expect(state.post).toHaveBeenCalledWith(
      '/workspaces/admin/identity/facility-assignments',
      expect.objectContaining({
        facility_id: 'facility-1',
        email: 'nuru@example.test',
        role_key: 'nurse',
      }),
      expect.objectContaining({ preserveScroll: true }),
    );
  });

  it('offers shared roles across facility types and capability-specific roles only when enabled', async () => {
    const user = userEvent.setup();
    const labFacility = {
      id: 'facility-lab',
      organizationId: 'organization-1',
      name: 'Laboratory Clinic',
      capabilities: ['LABORATORY'],
    };
    const dispensary = {
      id: 'facility-dispensary',
      organizationId: 'organization-1',
      name: 'Village Dispensary',
      capabilities: ['PHARMACY'],
    };
    render(<AdminRolePromotions
      {...props}
      facilities={[...props.facilities, labFacility, dispensary]}
      facilityAdminInviteFacilities={[...props.facilityAdminInviteFacilities, labFacility, dispensary]}
      staffAccessFacilities={[...props.staffAccessFacilities, labFacility, dispensary]}
      ordinaryRoleOptions={[
        ...props.ordinaryRoleOptions,
        { key: 'laboratory_technician', label: 'Laboratory Technician', requiredCapability: 'LABORATORY' },
        { key: 'pharmacist', label: 'Pharmacist', requiredCapability: 'PHARMACY' },
        { key: 'doctor', label: 'Clinician' },
      ]}
      invitableFacilityRoles={[
        ...props.invitableFacilityRoles,
        { key: 'laboratory_technician', label: 'Laboratory Technician', requiredCapability: 'LABORATORY' },
        { key: 'pharmacist', label: 'Pharmacist', requiredCapability: 'PHARMACY' },
        { key: 'doctor', label: 'Clinician' },
      ]}
    />);
    await user.click(screen.getByRole('tab', { name: /User role assignments/ }));
    await user.click(screen.getByRole('button', { name: 'Grant facility role' }));
    const roleSelect = screen.getByRole('combobox', { name: 'Ordinary role' });
    expect(within(roleSelect).queryByRole('option', { name: 'Laboratory Technician' })).not.toBeInTheDocument();
    expect(within(roleSelect).queryByRole('option', { name: 'Pharmacist' })).not.toBeInTheDocument();
    expect(within(roleSelect).getByRole('option', { name: 'Clinician' })).toBeInTheDocument();

    await user.selectOptions(screen.getByRole('combobox', { name: 'Facility' }), 'facility-dispensary');
    expect(within(roleSelect).queryByRole('option', { name: 'Laboratory Technician' })).not.toBeInTheDocument();
    expect(within(roleSelect).getByRole('option', { name: 'Clinician' })).toBeInTheDocument();
    expect(within(roleSelect).getByRole('option', { name: 'Pharmacist' })).toBeInTheDocument();

    await user.selectOptions(screen.getByRole('combobox', { name: 'Facility' }), 'facility-lab');
    expect(await within(roleSelect).findByRole('option', { name: 'Laboratory Technician' })).toBeInTheDocument();
  });

  it('keeps shared invitation roles across facility types and filters by capabilities', async () => {
    const user = userEvent.setup();
    const labFacility = {
      id: 'facility-lab',
      organizationId: 'organization-1',
      name: 'Laboratory Clinic',
      capabilities: ['LABORATORY'],
    };
    const dispensary = {
      id: 'facility-dispensary',
      organizationId: 'organization-1',
      name: 'Village Dispensary',
      capabilities: ['PHARMACY'],
    };
    render(<AdminRolePromotions
      {...props}
      facilities={[...props.facilities, labFacility, dispensary]}
      facilityAdminInviteFacilities={[...props.facilityAdminInviteFacilities, labFacility, dispensary]}
      staffAccessFacilities={[...props.staffAccessFacilities, labFacility, dispensary]}
      invitableFacilityRoles={[
        ...props.invitableFacilityRoles,
        { key: 'laboratory_technician', label: 'Laboratory Technician', requiredCapability: 'LABORATORY' },
        { key: 'pharmacist', label: 'Pharmacist', requiredCapability: 'PHARMACY' },
        { key: 'doctor', label: 'Clinician' },
      ]}
    />);
    await user.click(screen.getByRole('tab', { name: /Facility invitations/ }));
    await user.click(screen.getByRole('button', { name: 'Invite facility account' }));
    await user.selectOptions(screen.getByRole('combobox', { name: 'Facility' }), 'facility-lab');

    const roleSelect = screen.getByRole('combobox', { name: 'Facility role' });
    expect(await within(roleSelect).findByRole('option', { name: 'Laboratory Technician' })).toBeInTheDocument();
    expect(within(roleSelect).queryByRole('option', { name: 'Pharmacist' })).not.toBeInTheDocument();
    expect(within(roleSelect).getByRole('option', { name: 'Clinician' })).toBeInTheDocument();
    await user.selectOptions(roleSelect, 'laboratory_technician');
    expect(screen.getByRole('combobox', { name: 'Facility' })).toHaveValue('facility-lab');

    await user.selectOptions(roleSelect, 'facility_admin');
    await user.selectOptions(screen.getByRole('combobox', { name: 'Facility' }), 'facility-dispensary');
    expect(within(roleSelect).getByRole('option', { name: 'Pharmacist' })).toBeInTheDocument();
    expect(within(roleSelect).getByRole('option', { name: 'Clinician' })).toBeInTheDocument();
  });

  it('identifies admin, HR, and ordinary facility roles in the shared request list', () => {
    const nurseRequest = {
      ...promotion,
      id: 'request-nurse',
      roleKey: 'nurse',
      targetUserId: 'nurse-user',
      targetName: 'Nuru Nurse',
      targetEmail: 'nuru@example.test',
    };
    const hrRequest = {
      ...promotion,
      id: 'request-hr',
      roleKey: 'facility_staff_manager',
      targetUserId: 'hr-user',
      targetName: 'Hawa HR',
      targetEmail: 'hawa@example.test',
    };

    render(<AdminRolePromotions
      {...props}
      promotions={[promotion, nurseRequest, hrRequest]}
      total={3}
    />);

    const table = screen.getByRole('table', { name: 'Access requests 3 requests' });
    expect(within(table).getByText('Admin role')).toBeInTheDocument();
    expect(within(table).getByText('Staff role')).toBeInTheDocument();
    expect(within(table).getByText('HR management')).toBeInTheDocument();
    expect(within(table).getByText('Grant Nurse')).toBeInTheDocument();
    expect(within(table).getByText('Grant HR / Staff Manager')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Request Facility Admin assignment' })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Request Tenant Admin assignment' })).toBeInTheDocument();
  });

  it('keeps completed decisions in history without a review action', () => {
    render(<AdminRolePromotions
      {...props}
      promotions={[{ ...promotion, status: 'APPROVED' }]}
      total={1}
      statusCounts={{ ALL: 1, PENDING: 0, APPROVED: 1, REJECTED: 0, CANCELLED: 0 }}
      statusFilter="APPROVED"
    />);

    const historyTable = screen.getByRole('table', { name: 'Access requests 1 request' });
    expect(within(historyTable).getByText('Approved')).toBeInTheDocument();
    expect(within(historyTable).queryByRole('button', { name: /Review/ })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /Review/ })).not.toBeInTheDocument();
  });

  it('filters access requests by backend status in the Carbon table filter menu', async () => {
    render(<AdminRolePromotions {...props} />);

    fireEvent.click(screen.getByRole('button', { name: 'Filter by status: All statuses' }));
    const statusItems = screen.getAllByRole('menuitem', { hidden: true });
    expect(statusItems[2]).toHaveTextContent('Approved (0)');
    fireEvent.click(statusItems[2]!);

    expect(state.get).toHaveBeenCalledWith(
      '/workspaces/admin/identity/role-promotions',
      { status: 'APPROVED', tab: 'requests' },
      expect.objectContaining({ preserveScroll: true, preserveState: true, replace: true }),
    );
  });

  it('resends a setup link only for an eligible expired facility invitation', () => {
    const expiredInvitation = {
      ...props.facilityInvitations[0]!,
      id: 'expired-invitation',
      status: 'EXPIRED' as const,
      canResend: true,
    };
    render(<AdminRolePromotions {...props} facilityInvitations={[expiredInvitation]} />);

    fireEvent.click(screen.getByRole('tab', { name: /Facility invitations/ }));
    fireEvent.click(screen.getByRole('button', {
      name: 'Resend setup link to nuru@example.test',
    }));

    const panel = screen.getByRole('dialog', { name: 'Resend setup link' });
    expect(within(panel).getByText(/new 60-minute setup link to nuru@example.test/)).toBeInTheDocument();
    expect(within(panel).getByText('Nurse, Receptionist · Central Clinic')).toBeInTheDocument();
    fireEvent.change(within(panel).getByRole('textbox', { name: 'Non-secret change/ticket reference' }), {
      target: { value: 'OPS-INVITE-RESEND' },
    });
    fireEvent.change(within(panel).getByRole('textbox', { name: 'Reason for resending' }), {
      target: { value: 'The original setup link expired before use.' },
    });
    fireEvent.click(within(panel).getByRole('button', { name: 'Resend invitation' }));

    expect(state.post).toHaveBeenCalledWith(
      '/workspaces/admin/identity/role-promotions/facility-user-invitations/expired-invitation/resend',
      {
        change_reference: 'OPS-INVITE-RESEND',
        reason: 'The original setup link expired before use.',
      },
      expect.objectContaining({ preserveScroll: true }),
    );
  });

  it('opens a review panel with hidden identifiers and independent decisions', async () => {
    render(<AdminRolePromotions {...props} />);

    fireEvent.click(screen.getByRole('button', { name: 'Review Grant Facility Admin' }));

    const panel = screen.getByRole('dialog', { name: 'Review access request' });
    expect(within(panel).getByText('Sam Example · sam@example.test')).toBeInTheDocument();
    expect(within(panel).getByText('Manage daily facility operations.')).toBeInTheDocument();
    expect(within(panel).getByText('target-user-1')).not.toBeVisible();
    expect(within(panel).getByText('requester-1')).not.toBeVisible();
    fireEvent.click(within(panel).getByText('Technical identifiers'));
    expect(within(panel).getByText('target-user-1')).toBeInTheDocument();
    fireEvent.click(within(panel).getAllByRole('button', { name: 'Copy' })[0]!);
    await waitFor(() => expect(within(panel).getByRole('status')).toHaveTextContent('Identifier copied.'));
    expect(within(panel).getByRole('button', { name: 'Approve promotion' })).toBeEnabled();
    const reject = within(panel).getByRole('button', { name: 'Reject request' });
    expect(reject).toBeDisabled();

    fireEvent.change(within(panel).getByRole('textbox', { name: 'Reason for rejection' }), {
      target: { value: 'Insufficient operational experience.' },
    });
    expect(reject).toBeEnabled();
    fireEvent.click(reject);

    expect(state.post).toHaveBeenCalledWith(
      '/workspaces/admin/identity/role-promotions/request-1/reject',
      { decision_reason: 'Insufficient operational experience.' },
      expect.objectContaining({
        preserveScroll: true,
        preserveState: true,
        only: expect.arrayContaining(['promotions', 'total', 'statusCounts']),
      }),
    );
  });

  it('lets the inviting Tenant Admin approve a facility invitation request', () => {
    render(<AdminRolePromotions
      {...props}
      currentUserId="requester-1"
      promotions={[{ ...promotion, requestOrigin: 'FACILITY_INVITATION' }]}
    />);

    fireEvent.click(screen.getByRole('button', { name: 'Review Grant Facility Admin' }));

    const panel = screen.getByRole('dialog', { name: 'Review access request' });
    expect(within(panel).getByText('You are approving the access request from your invitation')).toBeInTheDocument();
    expect(within(panel).getByText('Facility Admin invitation')).toBeInTheDocument();
    expect(within(panel).getByRole('button', { name: 'Approve invited access' })).toBeEnabled();
    expect(within(panel).getByRole('button', { name: 'Withdraw request' })).toBeDisabled();

    fireEvent.click(within(panel).getByRole('button', { name: 'Approve invited access' }));

    expect(state.post).toHaveBeenCalledWith(
      '/workspaces/admin/identity/role-promotions/request-1/approve',
      {},
      expect.objectContaining({
        preserveScroll: true,
        preserveState: true,
        only: expect.arrayContaining(['promotions', 'total', 'statusCounts']),
      }),
    );
  });

  it('lets the inviter approve HR / Staff Manager access from the invitation request', () => {
    render(<AdminRolePromotions
      {...props}
      currentUserId="requester-1"
      promotions={[{
        ...promotion,
        roleKey: 'facility_staff_manager',
        requestOrigin: 'FACILITY_INVITATION',
      }]}
    />);

    fireEvent.click(screen.getByRole('button', { name: 'Review Grant HR / Staff Manager' }));

    const panel = screen.getByRole('dialog', { name: 'Review access request' });
    expect(within(panel).getByRole('button', { name: 'Approve invited access' })).toBeEnabled();
    expect(within(panel).getByText('You are approving the access request from your invitation')).toBeInTheDocument();
  });

  it('does not let a requester approve a manual Facility Admin promotion', () => {
    render(<AdminRolePromotions
      {...props}
      currentUserId="requester-1"
    />);

    fireEvent.click(screen.getByRole('button', { name: 'Review Grant Facility Admin' }));

    const panel = screen.getByRole('dialog', { name: 'Review access request' });
    expect(within(panel).queryByText('You are approving your own invitation')).not.toBeInTheDocument();
    expect(within(panel).queryByRole('button', { name: 'Approve promotion' })).not.toBeInTheDocument();
    expect(within(panel).queryByRole('button', { name: 'Approve facility admin' })).not.toBeInTheDocument();
    expect(within(panel).queryByRole('button', { name: 'Reject request' })).not.toBeInTheDocument();
  });

  it('opens role requests and invitations in the shared workspace panel', () => {
    render(<AdminRolePromotions {...props} promotions={[]} total={0} />);

    fireEvent.click(screen.getByRole('button', { name: 'Request Facility Admin assignment' }));
    const assignmentPanel = screen.getByRole('dialog', { name: 'Request Facility Admin assignment' });
    expect(within(assignmentPanel).getByLabelText('Existing account email')).toBeInTheDocument();
    expect(within(assignmentPanel).getByText('Assign this role to another account. A different administrator must approve.')).toBeInTheDocument();
    expect(within(assignmentPanel).getByRole('button', { name: 'Send for approval' })).toBeInTheDocument();

    fireEvent.click(screen.getByRole('button', { name: 'Close Request Facility Admin assignment' }));
    fireEvent.click(screen.getByRole('tab', { name: /Facility invitations/ }));
    fireEvent.click(screen.getByRole('button', { name: 'Invite facility account' }));
    expect(screen.getByRole('dialog', { name: 'Invite a facility account' })).toBeInTheDocument();
    expect(screen.getByLabelText('Facility role')).toBeInTheDocument();
    expect(screen.getByLabelText('Email address')).toBeInTheDocument();
    expect(screen.getByRole('option', { name: 'Nurse' })).toBeInTheDocument();
  });

  it('lets a facility administrator invite a limited HR staff manager but not another administrator', () => {
    render(<AdminRolePromotions
      {...props}
      canInviteFacilityAdmins={false}
      invitableFacilityRoles={[{ key: 'facility_staff_manager', label: 'HR / Staff Manager' }]}
      facilityAdminInviteFacilities={[]}
    />);

    fireEvent.click(screen.getByRole('tab', { name: /Facility invitations/ }));
    fireEvent.click(screen.getByRole('button', { name: 'Invite facility account' }));
    expect(screen.getByRole('option', { name: 'HR / Staff Manager' })).toBeInTheDocument();
    expect(screen.queryByRole('option', { name: 'Facility Admin' })).not.toBeInTheDocument();
    fireEvent.change(screen.getByRole('textbox', { name: 'Full name' }), {
      target: { value: 'New HR colleague' },
    });
    fireEvent.change(screen.getByRole('textbox', { name: 'Email address' }), {
      target: { value: 'hr@example.test' },
    });
    fireEvent.change(screen.getByRole('textbox', { name: 'Non-secret change/ticket reference' }), {
      target: { value: 'HR-INVITE-101' },
    });
    fireEvent.change(screen.getByRole('textbox', { name: 'Reason for the access request' }), {
      target: { value: 'Provide facility staff management.' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Send setup invitation' }));

    expect(state.post).toHaveBeenCalledWith(
      '/workspaces/admin/identity/role-promotions/facility-user-invitations',
      {
        role_key: 'facility_staff_manager',
        facility_id: 'facility-1',
        name: 'New HR colleague',
        email: 'hr@example.test',
        change_reference: 'HR-INVITE-101',
        reason: 'Provide facility staff management.',
      },
      expect.objectContaining({ preserveScroll: true }),
    );
  });

  it('submits an account email instead of requiring an internal user identifier', () => {
    render(<AdminRolePromotions {...props} promotions={[]} total={0} />);

    fireEvent.click(screen.getByRole('button', { name: 'Request Facility Admin assignment' }));
    fireEvent.change(screen.getByRole('textbox', { name: 'Existing account email' }), {
      target: { value: 'sam@example.test' },
    });
    fireEvent.change(screen.getByRole('textbox', { name: 'Reason for promotion' }), {
      target: { value: 'Manage daily facility operations.' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Send for approval' }));

    expect(state.post).toHaveBeenCalledWith(
      '/workspaces/admin/identity/role-promotions',
      {
        role_key: 'facility_admin',
        target_email: 'sam@example.test',
        facility_id: 'facility-1',
        reason: 'Manage daily facility operations.',
      },
      expect.objectContaining({ preserveScroll: true }),
    );
  });

  it('grants the HR / Staff Manager role to an existing facility user and can revoke it', () => {
    render(<AdminRolePromotions {...props} />);

    fireEvent.click(screen.getByRole('tab', { name: /User role assignments/ }));
    const assignmentsTable = screen.getByRole('table', { name: 'Facility user role assignments 3 assignments' });
    expect(within(assignmentsTable).getByText('Sam Example · sam@example.test')).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Grant HR / Staff Manager role' }));
    const panel = screen.getByRole('dialog', { name: 'Grant HR / Staff Manager role' });
    expect(within(panel).getByText(/facility HR workspace to manage staff profiles/)).toBeInTheDocument();
    expect(within(panel).getByRole('textbox', { name: 'Existing facility user email' })).toBeInTheDocument();
    fireEvent.change(within(panel).getByRole('textbox', { name: 'Existing facility user email' }), {
      target: { value: 'sam@example.test' },
    });
    fireEvent.click(within(panel).getByRole('button', { name: 'Grant access' }));

    expect(state.post).toHaveBeenCalledWith(
      '/workspaces/admin/identity/facility-staff-managers',
      { facility_id: 'facility-1', email: 'sam@example.test' },
      expect.objectContaining({ preserveScroll: true }),
    );

    fireEvent.click(screen.getByRole('button', { name: 'Revoke HR / Staff Manager for Sam Example' }));
    const revokeDialog = screen.getByRole('dialog', { name: 'Revoke HR / Staff Manager role?' });
    expect(within(revokeDialog).getByText(/Sam Example/)).toBeInTheDocument();
    expect(within(revokeDialog).getByText(/Central Clinic/)).toBeInTheDocument();
    expect(within(revokeDialog).getByText(/They will lose access to this facility's HR workspace/)).toBeInTheDocument();
    expect(state.delete).not.toHaveBeenCalled();
    fireEvent.click(within(revokeDialog).getByRole('button', { name: 'Revoke access' }));
    expect(state.delete).toHaveBeenCalledWith(
      '/workspaces/admin/identity/facility-staff-managers/staff-access-1',
      expect.objectContaining({ preserveScroll: true }),
    );
  });

  it('confirms ordinary facility-role revocation in a Carbon modal with a required reason', () => {
    render(<AdminRolePromotions {...props} />);
    fireEvent.click(screen.getByRole('tab', { name: /User role assignments/ }));
    fireEvent.click(screen.getByRole('button', { name: 'Revoke Nurse for Nuru Nurse' }));

    const revokeModal = screen.getByRole('dialog', { name: 'Revoke Nurse role?' });
    expect(within(revokeModal).getByText(/Nuru Nurse/)).toBeInTheDocument();
    expect(within(revokeModal).getByText(/Central Clinic/)).toBeInTheDocument();
    expect(within(revokeModal).getByText(/active sessions will be revoked/)).toBeInTheDocument();
    const confirm = within(revokeModal).getByRole('button', { name: 'Revoke access' });
    expect(confirm).toBeDisabled();
    expect(state.delete).not.toHaveBeenCalled();

    fireEvent.change(within(revokeModal).getByRole('textbox', { name: 'Reason' }), {
      target: { value: 'Role is no longer required for this user.' },
    });
    expect(confirm).toBeEnabled();
    fireEvent.click(confirm);

    expect(state.delete).toHaveBeenCalledWith(
      '/workspaces/admin/identity/facility-assignments/ordinary-assignment-1',
      expect.objectContaining({
        data: { reason: 'Role is no longer required for this user.' },
        preserveScroll: true,
      }),
    );
  });
});

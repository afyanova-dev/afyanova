import { act, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import FacilityStaffIndex from '@/pages/HR/Staff/Index';
import { AssignmentRequestsPanel } from '@/features/hr/components/AssignmentRequestsPanel';
import { PositionsManager } from '@/features/hr/components/PositionsManager';
import { StaffCreateForm } from '@/features/hr/components/StaffCreateForm';
import { StaffProfile } from '@/features/hr/components/StaffProfile';
import AccountInvitationsPage from '@/pages/HR/Staff/AccountInvitations';
import StaffShowPage from '@/pages/HR/Staff/Show';
import AccessRequestsPage from '@/pages/HR/Staff/AccessRequests';

const state = vi.hoisted(() => ({
  post: vi.fn(),
  put: vi.fn(),
  delete: vi.fn(),
  get: vi.fn(),
  visit: vi.fn(),
  url: '/workspaces/hr/staff/staff-1',
}));

vi.mock('@inertiajs/react', async () => {
  const { useState } = await import('react');

  return {
    Link: ({ children, href }: { children: React.ReactNode; href: string }) => <a href={href}>{children}</a>,
    router: { post: state.post, put: state.put, delete: state.delete, get: state.get, visit: state.visit },
    usePage: () => ({ url: state.url, props: {} }),
    useForm: (initial: Record<string, unknown>) => {
      const [data, setState] = useState(initial);
      const [errors, setErrors] = useState<Record<string, string>>({});
      let transformedData = data;
      return {
        data,
        setData: (key: string, value: unknown) => setState((current) => ({ ...current, [key]: value })),
        setError: (field: string, message: string) => setErrors((current) => ({ ...current, [field]: message })),
        clearErrors: () => setErrors({}),
        post: (url: string, options: Record<string, unknown>) => state.post(url, transformedData, options),
        put: (url: string, options: Record<string, unknown>) => state.put(url, data, options),
        transform: (callback: (data: Record<string, unknown>) => Record<string, unknown>) => {
          transformedData = callback(data);
        },
        processing: false,
        errors,
        reset: vi.fn(),
      };
    },
  };
});

vi.mock('@/layouts/AppLayout/AppLayout', () => ({
  AppLayout: ({ children }: { children: React.ReactNode }) => <>{children}</>,
}));

const facility = { id: 'facility-1', name: 'Central Clinic' };
const departments = [{ id: 'department-1', name: 'Outpatient' }];
const units = [{ id: 'unit-1', departmentId: 'department-1', name: 'Consultation' }];
const positions = [{ id: 'position-1', code: 'RN-01', title: 'Nurse', cadreId: 'cadre-1' }];
const cadres = [{ id: 'cadre-1', code: 'NURSE', name: 'Nursing', category: 'NURSING' }];
const staff = {
  id: 'staff-1',
  staffNumber: 'STF-00000001',
  name: 'Sam Example',
  status: 'PROSPECT',
  joinedDate: null,
  suspensionReason: null,
  terminatedDate: null,
  terminationReason: null,
  positionTitle: 'Nurse',
  departmentName: 'Outpatient',
  unitName: 'Consultation',
  assignmentScope: 'UNIT',
  hasCurrentFacilityAssignment: true,
  phone: '+255700000001',
  email: 'sam@example.test',
  linkedUserId: null,
  linkedUserEmail: null,
  personProfile: {
    firstName: 'Sam',
    middleName: null,
    lastName: 'Example',
    dateOfBirth: '1990-04-12',
    gender: 'UNKNOWN',
    nationalId: null,
    phone: '+255700000001',
    email: 'sam@example.test',
    physicalAddress: null,
    photoDocumentId: null,
  },
  photoUrl: null,
  employment: {
    id: 'employment-1',
    contractType: 'PERMANENT',
    status: 'DRAFT',
    startDate: '2026-10-01',
    fte: '1.00',
    endDate: null,
    probationEndDate: null,
    terms: null,
  },
};
const noTerminationBlockers = {
  activeAssignments: 0,
  openEmployments: 0,
  futurePublishedShifts: 0,
};
const setupInvitation = {
  id: 'invitation-1',
  status: 'EXPIRED',
  sentAt: '2026-10-01T09:00:00Z',
  expiresAt: '2026-10-01T10:00:00Z',
  expired: true,
  canResend: true,
  canRevoke: true,
  deliveryStatus: 'SENT',
  lastSentByName: 'HR Manager',
  roles: ['nurse'],
};
const facilityInvitation = {
  invitationId: 'invitation-1',
  userId: 'user-1',
  staffId: 'staff-1',
  staffNumber: 'STF-00000001',
  name: 'Sam Example',
  email: 'sam-account@example.test',
  positionTitle: 'Nurse',
  roles: ['nurse'],
  status: 'PENDING',
  statusLabel: 'Expired',
  sentAt: '2026-10-01T09:00:00Z',
  expiresAt: '2026-10-01T10:00:00Z',
  expired: true,
  deliveryStatus: 'SENT',
  resendCount: 0,
  lastSentByName: 'HR Manager',
  canResend: true,
  canRevoke: true,
  consumedAt: null,
  revokedAt: null,
  revocationReason: null,
};

describe('facility staff workflows', () => {
  beforeEach(() => {
    state.url = '/workspaces/hr/staff/staff-1';
    vi.clearAllMocks();
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
  });

  it('keeps task navigation out of the staff directory', () => {
    render(<FacilityStaffIndex
      facility={facility}
      activeStatus="ALL"
      statusCounts={{ ALL: 2, PROSPECT: 1, ACTIVE: 1 }}
      pagination={{ page: 1, perPage: 25, total: 2, lastPage: 1 }}
      staff={[{
        id: staff.id,
        staffNumber: staff.staffNumber,
        name: staff.name,
        status: staff.status,
        phone: staff.phone,
        email: staff.email,
        positionTitle: staff.positionTitle,
        departmentName: 'Outpatient',
        unitName: 'Consultation',
      }, {
        id: 'staff-2',
        staffNumber: 'STF-00000002',
        name: 'Amina Nurse',
        status: 'ACTIVE',
        phone: '+255700000002',
        email: 'amina@example.test',
        positionTitle: 'Registered Nurse',
        departmentName: 'Inpatient',
        unitName: 'Ward A',
      }]}
    />);

    expect(screen.getByText('All staff (2 shown of 2)')).toBeInTheDocument();
    expect(screen.getAllByRole('table')).toHaveLength(1);
    expect(screen.queryByRole('region', { name: 'Facility staff summary' })).not.toBeInTheDocument();
    expect(screen.queryByText('Invitation expired')).not.toBeInTheDocument();
    expect(screen.queryByRole('link', { name: 'Resend' })).not.toBeInTheDocument();
    expect(screen.queryByRole('link', { name: 'Add staff' })).not.toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Sam Example' })).toHaveAttribute(
      'href',
      '/workspaces/hr/staff/staff-1',
    );
    expect(screen.getByRole('searchbox', { name: 'Search staff' })).toBeInTheDocument();
    expect(screen.getByRole('cell', { name: 'Outpatient' })).toBeInTheDocument();
    expect(screen.getByRole('cell', { name: 'Consultation' })).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Invite staff user' })).toBeNull();
    expect(screen.queryByRole('link', { name: /Staff placements/ })).not.toBeInTheDocument();
    expect(screen.queryByRole('link', { name: /Cadres & positions/ })).not.toBeInTheDocument();
    expect(screen.queryByRole('link', { name: /Account invitations/ })).not.toBeInTheDocument();
    expect(screen.queryByRole('heading', { name: 'People operations' })).not.toBeInTheDocument();
    expect(screen.queryByRole('heading', { name: 'Job cadres & positions' })).toBeNull();
    expect(screen.queryByRole('heading', { name: 'Create staff record' })).toBeNull();
  });

  it('provides a separate account invitation worklist with a resend-profile action', () => {
    render(<AccountInvitationsPage
      facility={facility}
      pendingAccessRequestCount={0}
      canResendInvitations
      invitations={[facilityInvitation]}
    />);

    expect(screen.getByRole('heading', { name: 'Invitation history' })).toBeInTheDocument();
    expect(screen.getByText('All invitations (1 of 1)')).toBeInTheDocument();
    expect(screen.getByText('sam-account@example.test')).toBeInTheDocument();
    expect(screen.getAllByText('Expired').length).toBeGreaterThan(0);
    expect(screen.getByRole('link', { name: 'Review & resend' })).toHaveAttribute(
      'href',
      '/workspaces/hr/staff/staff-1?section=access&resend=1',
    );
  });

  it('separates staff lifecycle views and shows the account lifecycle state', () => {
    render(<FacilityStaffIndex
      facility={facility}
      activeStatus="ACTIVE"
      statusCounts={{ ALL: 2, ACTIVE: 1, PROSPECT: 1 }}
      pagination={{ page: 1, perPage: 25, total: 2, lastPage: 1 }}
      staff={[
        {
          id: 'staff-2',
          staffNumber: 'STF-00000002',
          name: 'Amina Nurse',
          status: 'ACTIVE',
          phone: '+255700000002',
          email: 'amina@example.test',
          positionTitle: 'Registered Nurse',
          departmentName: 'Inpatient',
          unitName: 'Ward A',
          accountState: 'ACTIVE',
        },
        {
          id: 'staff-1',
          staffNumber: 'STF-00000001',
          name: 'Sam Example',
          status: 'PROSPECT',
          phone: '+255700000001',
          email: 'sam@example.test',
          positionTitle: 'Nurse',
          departmentName: 'Outpatient',
          unitName: 'Consultation',
          accountState: 'INVITATION_EXPIRED',
        },
      ]}
    />);

    expect(screen.getByRole('tab', { name: 'Active (1)' })).toBeInTheDocument();
    expect(screen.getByRole('tab', { name: 'Pending onboarding (1)' })).toBeInTheDocument();
    expect(screen.getByText('Active account')).toBeInTheDocument();
    expect(screen.getByText('Invitation expired')).toBeInTheDocument();

    fireEvent.click(screen.getByRole('tab', { name: 'Pending onboarding (1)' }));
    expect(state.get).toHaveBeenCalledWith(
      '/workspaces/hr/staff',
      { status: 'PROSPECT' },
      expect.objectContaining({ preserveState: true, preserveScroll: true, replace: true }),
    );
  });

  it('shows all staff by default and hides lifecycle tabs with no records', () => {
    render(<FacilityStaffIndex
      facility={facility}
      activeStatus="ALL"
      statusCounts={{ ALL: 2, PROSPECT: 2 }}
      pagination={{ page: 1, perPage: 25, total: 2, lastPage: 1 }}
      staff={[
        {
          id: 'staff-1',
          staffNumber: 'STF-00000001',
          name: 'Sam Example',
          status: 'PROSPECT',
          phone: '+255700000001',
          email: 'sam@example.test',
          positionTitle: 'Nurse',
          departmentName: 'Outpatient',
          unitName: 'Consultation',
        },
        {
          id: 'staff-2',
          staffNumber: 'STF-00000002',
          name: 'Amina Nurse',
          status: 'PROSPECT',
          phone: '+255700000002',
          email: 'amina@example.test',
          positionTitle: 'Registered Nurse',
          departmentName: 'Inpatient',
          unitName: 'Ward A',
        },
      ]}
    />);

    expect(screen.getByRole('tab', { name: 'All (2)' })).toBeInTheDocument();
    expect(screen.getByRole('tab', { name: 'Pending onboarding (2)' })).toBeInTheDocument();
    expect(screen.queryByRole('tab', { name: /Active/ })).not.toBeInTheDocument();
    expect(screen.getByText('All staff (2 shown of 2)')).toBeInTheDocument();
  });

  it('separates invitation history by lifecycle status and counts each tab', () => {
    render(<AccountInvitationsPage
      facility={facility}
      canResendInvitations
      invitations={[
        facilityInvitation,
        {
          ...facilityInvitation,
          invitationId: 'invitation-2',
          email: 'revoked@example.test',
          status: 'REVOKED',
          statusLabel: 'Revoked',
          expired: false,
          canResend: false,
          canRevoke: false,
        },
        {
          ...facilityInvitation,
          invitationId: 'invitation-3',
          email: 'accepted@example.test',
          status: 'CONSUMED',
          statusLabel: 'Accepted',
          expired: false,
          canResend: false,
          canRevoke: false,
        },
      ]}
    />);

    expect(screen.getByRole('tab', { name: 'Expired (1)' })).toBeInTheDocument();
    expect(screen.getByRole('tab', { name: 'Revoked (1)' })).toBeInTheDocument();
    expect(screen.getByRole('tab', { name: 'Accepted (1)' })).toBeInTheDocument();

    fireEvent.click(screen.getByRole('tab', { name: 'Revoked (1)' }));
    expect(screen.getByText('Revoked invitations (1 of 3)')).toBeInTheDocument();
    expect(screen.getByText('revoked@example.test')).toBeInTheDocument();
    expect(screen.queryByText('sam-account@example.test')).not.toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Review invitation' })).toBeInTheDocument();
  });

  it('filters staff using Carbon table search and a labeled filter panel', async () => {
    render(<FacilityStaffIndex
      facility={facility}
      activeStatus="ALL"
      statusCounts={{ ALL: 2, PROSPECT: 1, ACTIVE: 1 }}
      pagination={{ page: 1, perPage: 25, total: 2, lastPage: 1 }}
      staff={[
        {
          id: 'staff-1',
          staffNumber: 'STF-00000001',
          name: 'Sam Example',
          status: 'PROSPECT',
          phone: '+255700000001',
          email: 'sam@example.test',
          positionTitle: 'Nurse',
          departmentName: 'Outpatient',
          unitName: 'Consultation',
        },
        {
          id: 'staff-2',
          staffNumber: 'STF-00000002',
          name: 'Amina Nurse',
          status: 'ACTIVE',
          phone: '+255700000002',
          email: 'amina@example.test',
          positionTitle: 'Registered Nurse',
          departmentName: 'Inpatient',
          unitName: 'Ward A',
        },
      ]}
    />);

    fireEvent.click(screen.getByRole('button', { name: 'Filters' }));
    fireEvent.change(screen.getByLabelText('Department'), { target: { value: 'Inpatient' } });
    fireEvent.click(screen.getByRole('button', { name: 'Apply filters' }));
    expect(screen.getByRole('button', { name: 'Filters (1)' })).toBeInTheDocument();
    expect(screen.getByLabelText('Active staff filters').closest('.cds--data-table-header__decorator')).not.toBeNull();
    expect(screen.getByLabelText('Active staff filters').closest('[aria-label="All facility staff directory toolbar"]')).toBeNull();
    expect(screen.getByText('All staff (1 shown of 2)')).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Amina Nurse' })).toBeInTheDocument();
    expect(screen.queryByRole('link', { name: 'Sam Example' })).not.toBeInTheDocument();

    fireEvent.click(screen.getByRole('button', { name: 'Filters (1)' }));
    fireEvent.change(screen.getByLabelText('Department'), { target: { value: 'Outpatient' } });
    fireEvent.change(screen.getByLabelText('Unit'), { target: { value: 'Consultation' } });
    fireEvent.change(screen.getByLabelText('Position'), { target: { value: 'Nurse' } });
    fireEvent.click(screen.getByRole('button', { name: 'Apply filters' }));
    expect(screen.getByRole('link', { name: 'Sam Example' })).toBeInTheDocument();
    expect(screen.queryByRole('link', { name: 'Amina Nurse' })).not.toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Filters (3)' })).toBeInTheDocument();
    expect(screen.getByText('All staff (1 shown of 2)')).toBeInTheDocument();

    fireEvent.click(screen.getByRole('button', { name: 'Clear all' }));
    expect(screen.getByText('All staff (2 shown of 2)')).toBeInTheDocument();

    const search = screen.getByRole('searchbox', { name: 'Search staff' });
    fireEvent.change(search, { target: { value: 'STF-00000001' } });
    await waitFor(() => {
      expect(screen.getByText('All staff (1 shown of 2)')).toBeInTheDocument();
    });
    expect(screen.getByRole('link', { name: 'Sam Example' })).toBeInTheDocument();
  });

  it('creates a staff record with an assignment request', () => {
    render(<StaffCreateForm departments={departments} units={units} positions={positions}
      canManageEmployment invitationRoles={[]} canInviteUser={false} />);

    expect(screen.getByRole('heading', { name: 'Personal details' })).toBeInTheDocument();
    expect(screen.getByRole('heading', { name: 'Requested placement' })).toBeInTheDocument();
    expect(screen.getByRole('heading', { name: 'Employment details' })).toBeInTheDocument();
    const namesGrid = screen.getByLabelText('First name').closest('.facility-staff__create-names');
    expect(namesGrid).toContainElement(screen.getByLabelText('Middle name (optional)'));
    expect(namesGrid).toContainElement(screen.getByLabelText('Last name'));
    const personalGrid = screen.getByLabelText('Date of birth').closest('.facility-staff__create-grid');
    expect(personalGrid).toContainElement(screen.getByLabelText('Gender'));
    expect(personalGrid).toContainElement(screen.getByLabelText('Phone (optional)'));
    expect(personalGrid).toContainElement(screen.getByLabelText('Contact email (optional)'));
    const placementGrid = screen.getByLabelText('Department (optional)').closest('.facility-staff__create-grid');
    expect(placementGrid).toHaveClass('facility-staff__create-grid--three');
    expect(placementGrid).toContainElement(screen.getByLabelText('Unit (optional)'));
    expect(placementGrid).toContainElement(screen.getByLabelText('Requested assignment start'));

    fireEvent.change(screen.getByRole('textbox', { name: 'First name' }), { target: { value: 'Mariam' } });
    fireEvent.change(screen.getByRole('textbox', { name: 'Last name' }), { target: { value: 'Nyerere' } });
    fireEvent.change(screen.getByLabelText('Date of birth'), { target: { value: '02/01/1990' } });
    fireEvent.click(screen.getByRole('button', { name: 'Create staff' }));

    expect(state.post).toHaveBeenCalledWith(
      '/workspaces/hr/staff',
      expect.objectContaining({ first_name: 'Mariam', last_name: 'Nyerere', date_of_birth: '1990-02-01' }),
      expect.objectContaining({ preserveScroll: true }),
    );
  });

  it('shows inline validation before submitting an invalid date of birth', () => {
    render(<StaffCreateForm departments={departments} units={units} positions={positions}
      canManageEmployment invitationRoles={[]} canInviteUser={false} />);

    expect(document.querySelector('.facility-staff__create-form')).toHaveAttribute('novalidate');
    fireEvent.change(screen.getByRole('textbox', { name: 'First name' }), { target: { value: 'Mariam' } });
    fireEvent.change(screen.getByRole('textbox', { name: 'Last name' }), { target: { value: 'Nyerere' } });
    fireEvent.click(screen.getByRole('button', { name: 'Create staff' }));

    expect(screen.getByText('Enter a valid date of birth before today.')).toBeInTheDocument();
    expect(state.post).not.toHaveBeenCalled();
  });

  it('shows custom inline messages for missing required details instead of native browser validation', () => {
    render(<StaffCreateForm departments={departments} units={units} positions={positions}
      canManageEmployment invitationRoles={[
        { key: 'nurse', label: 'Nurse' },
        { key: 'receptionist', label: 'Receptionist' },
      ]} canInviteUser />);

    expect(document.querySelector('.facility-staff__create-form')).toHaveAttribute('novalidate');
    fireEvent.click(screen.getByRole('checkbox', { name: 'Invite this staff member to use AfyaNova' }));
    fireEvent.click(screen.getByRole('button', { name: 'Create & invite' }));

    expect(screen.getByText("Enter the staff member's first name.")).toBeInTheDocument();
    expect(screen.getByText("Enter the staff member's last name.")).toBeInTheDocument();
    expect(screen.getByText('Enter a valid date of birth before today.')).toBeInTheDocument();
    expect(screen.getByText('Enter an email address for account setup.')).toBeInTheDocument();
    expect(screen.getByText('Select a primary AfyaNova access role.')).toBeInTheDocument();
    expect(screen.getByText('Enter a valid non-secret change or ticket reference (3-100 characters).')).toBeInTheDocument();
    expect(screen.getByText('Enter a reason between 8 and 500 characters.')).toBeInTheDocument();
    expect(state.post).not.toHaveBeenCalled();
  });

  it('invites an account with roles directly from its staff record', () => {
    state.url = '/workspaces/hr/staff/staff-1?section=access';
    render(<StaffProfile
      staff={{ ...staff, linkedUserId: null, linkedUserEmail: null }}
      terminationBlockers={noTerminationBlockers}
      canManagePerson={false}
      canManageEmployment={false}
      canManageStaff={false}
      canLinkStaff={false}
      canInviteUser
      invitationRoles={[
        { key: 'nurse', label: 'Nurse' },
        { key: 'receptionist', label: 'Receptionist' },
      ]}
    />);

    fireEvent.click(screen.getByRole('button', { name: 'Actions' }));
    fireEvent.click(screen.getByRole('menuitem', { name: 'Invite account' }));
    const panel = screen.getByRole('dialog', { name: 'Invite account' });

    expect(within(panel).getByLabelText('Account email')).toHaveValue('sam@example.test');
    // Patient-style identity block replaces the long intro paragraph.
    expect(within(panel).getByText('Sam Example')).toBeInTheDocument();
    expect(within(panel).getByText('STF-00000001 · Nurse')).toBeInTheDocument();
    expect(within(panel).queryByText(/Choose a primary role/)).not.toBeInTheDocument();
    fireEvent.change(within(panel).getByLabelText('Primary AfyaNova access role'), { target: { value: 'nurse' } });
    // Secondary roles group tracks the two-role limit.
    expect(within(panel).getByText('Secondary AfyaNova access roles')).toBeInTheDocument();
    expect(within(panel).getByText('Select up to two additional roles · 0 of 2 selected')).toBeInTheDocument();
    fireEvent.click(within(panel).getByRole('checkbox', { name: 'Receptionist' }));
    expect(within(panel).getByText('Select up to two additional roles · 1 of 2 selected')).toBeInTheDocument();
    fireEvent.change(within(panel).getByLabelText('Change or ticket reference'), { target: { value: 'HR-STAFF-001' } });
    fireEvent.change(within(panel).getByLabelText('Reason for access'), {
      target: { value: 'Provide clinical care at this facility.' },
    });
    fireEvent.click(within(panel).getByRole('button', { name: 'Invite account and request roles' }));

    expect(state.post).toHaveBeenCalledWith(
      '/workspaces/hr/staff/staff-1/invite-account',
      expect.objectContaining({
        email: 'sam@example.test',
        primary_role_key: 'nurse',
        secondary_role_keys: ['receptionist'],
        change_reference: 'HR-STAFF-001',
        access_reason: 'Provide clinical care at this facility.',
      }),
      expect.objectContaining({ preserveScroll: true }),
    );
  });

  it('shows pending access status on the profile and links to the access request worklist', () => {
    state.url = '/workspaces/hr/staff/staff-1?section=access';
    render(<StaffProfile
      staff={{ ...staff, linkedUserId: 'user-1', linkedUserEmail: 'sam@example.test' }}
      terminationBlockers={noTerminationBlockers}
      canManagePerson={false}
      canManageEmployment={false}
      canManageStaff
      canLinkStaff={false}
      pendingInvitationAccessRequests={[{ id: 'promotion-1', roleKey: 'nurse', roleLabel: 'Nurse' }]}
    />);

    expect(document.querySelector('main.hr-staff-profile')).toBeInTheDocument();
    expect(screen.getByText('Facility access is awaiting review')).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Open access requests' }));
    expect(state.visit).toHaveBeenCalledWith('/workspaces/hr/staff/access-requests');
  });

  it('groups roles for each staff member and keeps decisions role-specific', () => {
    render(<AccessRequestsPage
      facility={facility}
      timezone="Africa/Dar_es_Salaam"
      pendingAccessRequestCount={3}
      requests={['Pharmacist', 'Cashier', 'Billing'].map((roleLabel, index) => ({
        id: `promotion-${index + 1}`,
        staffId: 'staff-1',
        staffNumber: 'STF-00000001',
        staffName: 'Sam Example',
        accountEmail: 'sam-account@example.test',
        roleKey: roleLabel.toLowerCase(),
        roleLabel,
        reason: 'Provide access for this facility role.',
        requestedAt: '2026-10-27 10:32:24+00',
        requesterName: 'HR Manager',
      }))}
    />);

    expect(screen.getByText('Pending access requests (3 roles · 1 staff)')).toBeInTheDocument();
    expect(screen.getByText('sam-account@example.test')).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Sam Example' })).toBeInTheDocument();
    expect(screen.getByText('Pharmacist, Cashier, Billing')).toBeInTheDocument();
    expect(screen.getAllByRole('row')).toHaveLength(2);
    expect(screen.queryByRole('button', { name: 'Approve Pharmacist' })).not.toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Expand access requests for Sam Example' }));
    const requestTimestamp = screen.getAllByText('27-Oct-2026, 13:32 GMT+3');
    expect(requestTimestamp).toHaveLength(3);
    expect(requestTimestamp[0]).toHaveAttribute('dateTime', '2026-10-27 10:32:24+00');
    expect(screen.getByText('Pharmacist')).toBeInTheDocument();
    expect(screen.getByText('Cashier')).toBeInTheDocument();
    expect(screen.getByText('Billing')).toBeInTheDocument();
    expect(screen.getAllByRole('row')).toHaveLength(3);
    expect(screen.getAllByRole('button', { name: /^Approve (Pharmacist|Cashier|Billing)$/ })).toHaveLength(3);
    expect(screen.getAllByRole('button', { name: /^Withdraw (Pharmacist|Cashier|Billing)$/ })).toHaveLength(3);
    fireEvent.click(screen.getByRole('button', { name: 'Approve Pharmacist' }));
    expect(state.post).toHaveBeenCalledWith(
      '/workspaces/hr/access-requests/promotion-1/staff/staff-1/approve',
      { return_to: 'access-requests' },
      { preserveScroll: true },
    );
    fireEvent.click(screen.getByRole('button', { name: 'Withdraw Billing' }));
    expect(screen.getByText('Withdraw Billing access for Sam Example? This does not alter the staff record.')).toBeInTheDocument();
  });

  it('explains that a newly created staff placement is awaiting confirmation', () => {
    render(<StaffProfile
      staff={{
        ...staff,
        hasCurrentFacilityAssignment: false,
        pendingAssignmentRequestId: 'assignment-request-1',
        pendingAssignmentEffectiveFrom: '2026-10-27',
      }}
      terminationBlockers={noTerminationBlockers}
      canManagePerson={false}
      canManageEmployment={false}
      canManageStaff
      canLinkStaff={false}
    />);

    const placementAlert = screen.getByRole('status');
    expect(within(placementAlert).getByText('Placement awaiting confirmation')).toBeInTheDocument();
    expect(within(placementAlert).getByText(/Requested placement: Consultation, effective 2026-10-27/)).toBeInTheDocument();
  });

  it('keeps the staff photo in the right column and submits it with the staff form', () => {
    render(<StaffCreateForm departments={departments} units={units} positions={positions}
      canManageEmployment invitationRoles={[]} canInviteUser={false} />);

    const layout = document.querySelector('.facility-staff__create-layout');
    const photoColumn = screen.getByText('Staff photo (optional)').closest('aside');
    expect(layout).not.toBeNull();
    expect(photoColumn?.parentElement).toBe(layout);
    expect(photoColumn).toHaveClass('facility-staff__create-photo');
    expect(photoColumn).toHaveTextContent('No image to display');
    expect(photoColumn).toHaveTextContent('JPG, PNG or WebP');
    expect(within(photoColumn as HTMLElement).getByRole('button', { name: 'Add photo' })).toBeInTheDocument();

    fireEvent.change(screen.getByRole('textbox', { name: 'First name' }), { target: { value: 'Mariam' } });
    fireEvent.change(screen.getByRole('textbox', { name: 'Last name' }), { target: { value: 'Nyerere' } });
    fireEvent.change(screen.getByLabelText('Date of birth'), { target: { value: '02/01/1990' } });
    const photo = new File(['staff image'], 'staff-photo.png', { type: 'image/png' });
    fireEvent.change(screen.getByLabelText('Staff photo file'), { target: { files: [photo] } });
    fireEvent.click(screen.getByRole('button', { name: 'Create staff' }));

    expect(state.post).toHaveBeenCalledWith(
      '/workspaces/hr/staff',
      expect.objectContaining({ photo }),
      expect.objectContaining({ preserveScroll: true }),
    );
  });

  it('returns to the staff directory when staff creation is cancelled', () => {
    render(<StaffCreateForm departments={departments} units={units} positions={positions}
      canManageEmployment invitationRoles={[]} canInviteUser={false} />);

    fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));

    expect(state.visit).toHaveBeenCalledWith('/workspaces/hr/staff');
  });

  it('can request account setup with primary and secondary AfyaNova access roles', () => {
    render(<StaffCreateForm departments={departments} units={units} positions={positions}
      canManageEmployment invitationRoles={[
        { key: 'nurse', label: 'Nurse' },
        { key: 'receptionist', label: 'Receptionist' },
      ]} canInviteUser />);

    expect(screen.getByRole('heading', { name: 'AfyaNova access roles' })).toBeInTheDocument();
    expect(screen.getByText('These roles control what the person can do in AfyaNova. They are separate from their professional cadre and job position.')).toBeInTheDocument();
      fireEvent.change(screen.getByRole('textbox', { name: 'First name' }), { target: { value: 'Nuru' } });
      fireEvent.change(screen.getByRole('textbox', { name: 'Last name' }), { target: { value: 'Nurse' } });
      fireEvent.change(screen.getByLabelText('Date of birth'), { target: { value: '02/01/1990' } });
      fireEvent.click(screen.getByRole('checkbox', { name: 'Invite this staff member to use AfyaNova' }));
      expect(screen.getByRole('button', { name: 'Create & invite' })).toBeInTheDocument();
    fireEvent.change(screen.getByRole('textbox', { name: 'Staff email for account setup' }), {
      target: { value: 'nuru@example.test' },
    });
    fireEvent.change(screen.getByLabelText('Primary AfyaNova access role'), { target: { value: 'nurse' } });
    fireEvent.click(screen.getByRole('checkbox', { name: 'Receptionist' }));
    fireEvent.change(screen.getByLabelText('Non-secret change/ticket reference'), {
      target: { value: 'HR-INVITE-101' },
    });
    fireEvent.change(screen.getByLabelText('Reason for requested access'), {
      target: { value: 'Provide nursing care at this facility.' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Create & invite' }));

    expect(state.post).toHaveBeenCalledWith(
      '/workspaces/hr/staff',
      expect.objectContaining({
        first_name: 'Nuru',
        last_name: 'Nurse',
        email: 'nuru@example.test',
        request_login: true,
        primary_role_key: 'nurse',
        secondary_role_keys: ['receptionist'],
        change_reference: 'HR-INVITE-101',
        access_reason: 'Provide nursing care at this facility.',
      }),
      expect.objectContaining({ preserveScroll: true }),
    );
  });

  it('offers a resend action for an expired, eligible setup invitation', () => {
    state.url = '/workspaces/hr/staff/staff-1?section=access';
    render(<StaffProfile
      staff={{
        ...staff,
        linkedUserId: 'user-1',
        linkedUserEmail: 'sam@example.test',
        setupInvitation,
      }}
      terminationBlockers={noTerminationBlockers}
      canManagePerson={false}
      canManageEmployment={false}
      canManageStaff={false}
      canLinkStaff={false}
      canResendInvitation
    />);

    expect(screen.getByText('Setup invitation expired')).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Resend invitation' }));
    const panel = screen.getByRole('dialog', { name: 'Resend setup invitation' });
    fireEvent.change(within(panel).getByLabelText('Change or ticket reference'), {
      target: { value: 'HR-RESEND-101' },
    });

    fireEvent.change(within(panel).getByLabelText('Reason for resend'), {
      target: { value: 'Staff member did not receive the earlier email.' },
    });
    fireEvent.click(within(panel).getByRole('button', { name: 'Resend invitation' }));

    expect(state.post).toHaveBeenCalledWith(
      '/workspaces/hr/staff/staff-1/resend-invitation',
      {
        change_reference: 'HR-RESEND-101',
        access_reason: 'Staff member did not receive the earlier email.',
      },
      { preserveScroll: true },
    );
  });

  it('revokes an active setup invitation from the profile with a reason', () => {
    state.url = '/workspaces/hr/staff/staff-1?section=access';
    render(<StaffProfile
      staff={{
        ...staff,
        linkedUserId: 'user-1',
        linkedUserEmail: 'sam@example.test',
        setupInvitation: { ...setupInvitation, status: 'PENDING', expired: false },
      }}
      terminationBlockers={noTerminationBlockers}
      canManagePerson={false}
      canManageEmployment={false}
      canManageStaff={false}
      canLinkStaff={false}
      canResendInvitation
      canRevokeInvitation
      canUnlinkAccount
      invitationHistory={[{
        id: 'event-1',
        eventType: 'ISSUED',
        eventLabel: 'Invitation sent',
        actorName: 'HR Manager',
        reason: 'Provide nursing cover.',
        occurredAt: '2026-10-01T09:00:00Z',
        recipientEmail: 'sam@example.test',
        roles: ['nurse'],
        metadata: {},
      }]}
    />);

    expect(screen.getByText('Setup invitation pending')).toBeInTheDocument();
    expect(screen.getByRole('region', { name: 'Invitation history' })).toHaveTextContent('Invitation sent');
    fireEvent.click(screen.getAllByRole('button', { name: 'Revoke invitation' })[0]!);
    const panel = screen.getByRole('dialog', { name: 'Revoke setup invitation' });
    fireEvent.change(within(panel).getByLabelText('Reason for invitation action (at least 8 characters)'), {
      target: { value: 'The invitation was sent to the wrong email address.' },
    });
    fireEvent.click(within(panel).getByRole('button', { name: 'Revoke invitation' }));

    expect(state.post).toHaveBeenCalledWith(
      '/workspaces/hr/staff/staff-1/revoke-invitation',
      { reason: 'The invitation was sent to the wrong email address.' },
      expect.objectContaining({ preserveScroll: true }),
    );
  });

  it('unlinks an unactivated account from the profile with a reason', () => {
    state.url = '/workspaces/hr/staff/staff-1?section=access';
    render(<StaffProfile
      staff={{
        ...staff,
        linkedUserId: 'user-1',
        linkedUserEmail: 'sam@example.test',
        setupInvitation: { ...setupInvitation, status: 'REVOKED', canResend: false },
      }}
      terminationBlockers={noTerminationBlockers}
      canManagePerson={false}
      canManageEmployment={false}
      canManageStaff={false}
      canLinkStaff={false}
      canRevokeInvitation={false}
      canUnlinkAccount
    />);

    expect(screen.getByText('Setup invitation revoked')).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Unlink unactivated account' }));
    const panel = screen.getByRole('dialog', { name: 'Unlink unactivated account' });
    fireEvent.change(within(panel).getByLabelText('Reason for invitation action (at least 8 characters)'), {
      target: { value: 'The wrong person was linked to this staff record.' },
    });
    fireEvent.click(within(panel).getByRole('button', { name: 'Unlink account' }));

    expect(state.post).toHaveBeenCalledWith(
      '/workspaces/hr/staff/staff-1/unlink-account',
      { reason: 'The wrong person was linked to this staff record.' },
      expect.objectContaining({ preserveScroll: true }),
    );
  });

  it('shows each assigned role and submits reasoned per-role access changes', () => {
    state.url = '/workspaces/hr/staff/staff-1?section=access';
    render(<StaffProfile
      staff={{
        ...staff,
        linkedUserId: 'user-1',
        linkedUserEmail: 'sam@example.test',
      }}
      terminationBlockers={noTerminationBlockers}
      canManagePerson={false}
      canManageEmployment={false}
      canManageStaff
      canLinkStaff={false}
      canManageStaffAccess
      canReadAccessHistory
      accessAssignments={[
        {
          id: 'assignment-nurse',
          role: 'nurse',
          roleName: 'Nurse',
          scope: 'FACILITY',
          status: 'ACTIVE',
          effectiveFrom: '2026-10-01',
          effectiveTo: null,
          isEffective: true,
          isLifecycleSuspended: false,
          canManageIndividually: true,
        },
        {
          id: 'assignment-pharmacist',
          role: 'pharmacist',
          roleName: 'Pharmacist',
          scope: 'FACILITY',
          status: 'SUSPENDED',
          effectiveFrom: null,
          effectiveTo: null,
          isEffective: false,
          isLifecycleSuspended: false,
          canManageIndividually: true,
        },
      ]}
      accessHistory={[{
        action: 'identity.access.suspended',
        role: 'nurse',
        actorName: 'HR Manager',
        reason: 'Temporary role coverage change.',
        occurredAt: '2026-10-27T10:32:24Z',
      }]}
    />);

    const assignedRoles = within(screen.getByRole('region', { name: 'Assigned facility roles' }));
    expect(assignedRoles.getByText('Nurse')).toBeInTheDocument();
    expect(assignedRoles.getByText('Pharmacist')).toBeInTheDocument();
    expect(screen.getByRole('region', { name: 'Access change history' })).toHaveTextContent('HR Manager');
    fireEvent.click(screen.getAllByRole('button', { name: 'Revoke role' })[0]!);
    fireEvent.change(screen.getByLabelText('Reason (at least 8 characters)'), {
      target: { value: 'Role no longer required.' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Confirm' }));

    expect(state.post).toHaveBeenCalledWith(
      '/workspaces/hr/staff/staff-1/access-assignments/assignment-nurse/revoke',
      { reason: 'Role no longer required.' },
      expect.objectContaining({ preserveScroll: true }),
    );
  });

  it('opens the resend form from the staff directory resend action', () => {
    state.url = '/workspaces/hr/staff/staff-1?section=access&resend=1';
    render(<StaffShowPage
      facility={facility}
      staff={{
        ...staff,
        linkedUserId: 'user-1',
        linkedUserEmail: 'sam@example.test',
        setupInvitation,
      }}
      qualifications={[]}
      canReadQualifications
      terminationBlockers={noTerminationBlockers}
      canManagePerson={false}
      canManageEmployment={false}
      canManageStaff={false}
      canCreateStaff={false}
      canLinkStaff={false}
      canInviteUser={false}
      canResendInvitation
      invitationRoles={[]}
      pendingInvitationAccessRequests={[]}
      canManagePositions={false}
      canReviewAssignments={false}
    />);

    expect(screen.getByRole('dialog', { name: 'Resend setup invitation' })).toBeInTheDocument();
  });

  it('shows assignment review and rejected-request correction in their own workflow', () => {
    render(<AssignmentRequestsPanel
      pendingRequests={[{
        id: 'request-1',
        staffNumber: 'STF-2',
        name: 'Alex Example',
        scope: 'DEPARTMENT',
        departmentName: 'Outpatient',
        unitName: null,
        effectiveFrom: '2026-10-09',
      }]}
      rejectedRequests={[{
        id: 'rejected-1',
        staffNumber: 'STF-3',
        name: 'Mariam Example',
        departmentId: null,
        unitId: null,
        departmentName: null,
        unitName: null,
        effectiveFrom: '2026-10-01',
        decisionReason: 'Choose a department.',
      }]}
      departments={departments}
      units={units}
      canReviewAssignments
    />);

    expect(screen.getAllByRole('table')).toHaveLength(2);
    expect(document.querySelector('.facility-staff__request')).toBeNull();
    expect(screen.queryByLabelText('Decision note (required when rejecting)')).not.toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Confirm' }));
    expect(state.post).toHaveBeenCalledWith(
      '/workspaces/hr/staff/assignment-requests/request-1/decision',
      { decision: 'approve', reason: '' },
      expect.objectContaining({ preserveScroll: true }),
    );

    fireEvent.click(screen.getByRole('button', { name: 'Reject' }));
    const rejectDialog = screen.getByRole('dialog', { name: 'Reject placement request' });
    expect(within(rejectDialog).getByText(/Alex Example/)).toBeInTheDocument();
    expect(within(rejectDialog).getByRole('button', { name: 'Reject request' })).toBeDisabled();
    fireEvent.change(within(rejectDialog).getByLabelText('Decision note'), {
      target: { value: 'Choose a department first.' },
    });
    fireEvent.click(within(rejectDialog).getByRole('button', { name: 'Reject request' }));
    expect(state.post).toHaveBeenCalledWith(
      '/workspaces/hr/staff/assignment-requests/request-1/decision',
      { decision: 'reject', reason: 'Choose a department first.' },
      expect.objectContaining({ preserveScroll: true }),
    );

    fireEvent.change(screen.getByRole('combobox', { name: 'Department' }), {
      target: { value: 'department-1' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Update' }));
    expect(state.post).toHaveBeenCalledWith(
      '/workspaces/hr/staff/assignment-requests/rejected-1/resubmit',
      expect.objectContaining({ department_id: 'department-1', effective_from: '2026-10-01' }),
      expect.objectContaining({ preserveScroll: true }),
    );
  });

  it('supports profile updates, onboarding, and employment activation on the staff record', () => {
    state.url = '/workspaces/hr/staff/staff-1?section=personal';
    const { unmount } = render(<StaffProfile
      staff={staff}
      terminationBlockers={noTerminationBlockers}
      canManagePerson
      canManageEmployment
      canManageStaff
      canLinkStaff
    />);

    fireEvent.click(screen.getByRole('button', { name: 'Actions' }));
    fireEvent.click(screen.getByRole('menuitem', { name: 'Edit personal details' }));
    const editPanel = screen.getByRole('dialog', { name: 'Edit personal details' });
    fireEvent.change(within(editPanel).getByRole('textbox', { name: 'Contact email' }), {
      target: { value: 'sam.updated@example.test' },
    });
    fireEvent.click(within(editPanel).getByRole('button', { name: 'Save staff details' }));
    expect(state.post).toHaveBeenCalledWith(
      '/workspaces/hr/staff/staff-1/person',
      expect.objectContaining({ email: 'sam.updated@example.test', _method: 'put' }),
      expect.objectContaining({ preserveScroll: true, forceFormData: true }),
    );
    unmount();

    state.url = '/workspaces/hr/staff/staff-1?section=employment';
    render(<StaffProfile
      staff={staff}
      terminationBlockers={noTerminationBlockers}
      canManagePerson
      canManageEmployment
      canManageStaff
      canLinkStaff
    />);
    fireEvent.click(screen.getByRole('button', { name: 'Actions' }));
    fireEvent.click(screen.getByRole('menuitem', { name: 'Complete onboarding' }));
    const onboardPanel = screen.getByRole('dialog', { name: 'Complete onboarding' });
    fireEvent.change(within(onboardPanel).getByLabelText('Actual joined date'), { target: { value: '2026-10-12' } });
    fireEvent.click(within(onboardPanel).getByRole('button', { name: 'Complete onboarding' }));
    expect(state.post).toHaveBeenCalledWith(
      '/workspaces/hr/staff/staff-1/onboard',
      { joined_date: '2026-10-12' },
      expect.objectContaining({ preserveScroll: true }),
    );
  });

  it('starts a draft employment only after staff onboarding', () => {
    state.url = '/workspaces/hr/staff/staff-1?section=employment';
    const activeStaff = {
      ...staff,
      status: 'ACTIVE',
      employment: { ...staff.employment, status: 'DRAFT' },
    };
    render(<StaffProfile
      staff={activeStaff}
      terminationBlockers={noTerminationBlockers}
      canManagePerson={false}
      canManageEmployment
      canManageStaff={false}
      canLinkStaff={false}
    />);
    fireEvent.click(screen.getByRole('button', { name: 'Actions' }));
    fireEvent.click(screen.getByRole('menuitem', { name: 'Start employment' }));
    const startPanel = screen.getByRole('dialog', { name: 'Start employment' });
    fireEvent.change(within(startPanel).getByLabelText('Confirmed employment start date'), { target: { value: '2026-10-12' } });
    fireEvent.click(within(startPanel).getByRole('button', { name: 'Start employment' }));
    expect(state.post).toHaveBeenCalledWith(
      '/workspaces/hr/staff/staff-1/employments/employment-1/start',
      { start_date: '2026-10-12' },
      expect.objectContaining({ preserveScroll: true }),
    );
  });

  it('suspends active staff with a reason and reinstates suspended staff', () => {
    const { unmount } = render(<StaffProfile
      staff={{ ...staff, status: 'ACTIVE' }}
      terminationBlockers={{
        activeAssignments: 1,
        openEmployments: 1,
        futurePublishedShifts: 0,
      }}
      canManagePerson={false}
      canManageEmployment={false}
      canManageStaff
      canLinkStaff={false}
    />);

    fireEvent.click(screen.getByRole('button', { name: 'Actions' }));
    fireEvent.click(screen.getByRole('menuitem', { name: 'Suspend staff' }));
    const suspendPanel = screen.getByRole('dialog', { name: 'Suspend staff' });
    fireEvent.change(within(suspendPanel).getByRole('textbox', { name: 'Reason for suspension' }), {
      target: { value: 'Administrative review' },
    });
    fireEvent.click(within(suspendPanel).getByRole('button', { name: 'Confirm suspension' }));
    expect(state.post).toHaveBeenCalledWith(
      '/workspaces/hr/staff/staff-1/suspend',
      { reason: 'Administrative review' },
      expect.objectContaining({ preserveScroll: true }),
    );
    unmount();

    render(<StaffProfile
      staff={{ ...staff, status: 'SUSPENDED', suspensionReason: 'Administrative review' }}
      terminationBlockers={noTerminationBlockers}
      canManagePerson={false}
      canManageEmployment={false}
      canManageStaff
      canLinkStaff={false}
    />);
    const overview = screen.getByRole('region', { name: 'Staff Summary' });
    fireEvent.click(within(overview).getByRole('button', { name: 'Reinstate staff' }));
    expect(state.post).toHaveBeenCalledWith(
      '/workspaces/hr/staff/staff-1/reinstate',
      {},
      expect.objectContaining({ preserveScroll: true }),
    );
  });

  it('requires a termination reason and effective date and disables termination while blockers remain', () => {
    const { rerender } = render(<StaffProfile
      staff={{ ...staff, status: 'ACTIVE' }}
      terminationBlockers={{
        activeAssignments: 1,
        openEmployments: 0,
        futurePublishedShifts: 2,
      }}
      canManagePerson={false}
      canManageEmployment={false}
      canManageStaff
      canLinkStaff={false}
    />);

    fireEvent.click(screen.getByRole('button', { name: 'Actions' }));
    fireEvent.click(screen.getByRole('menuitem', { name: 'Terminate staff' }));
    const terminatePanel = screen.getByRole('dialog', { name: 'Terminate staff' });
    expect(within(terminatePanel).getByRole('button', { name: 'Confirm termination' })).toBeDisabled();
    expect(within(terminatePanel).getByText('1 active assignment(s) must be ended')).toBeTruthy();
    expect(within(terminatePanel).getByText('2 future published shift(s) must be reassigned or cancelled')).toBeTruthy();

    rerender(<StaffProfile
      staff={{ ...staff, status: 'ACTIVE' }}
      terminationBlockers={noTerminationBlockers}
      canManagePerson={false}
      canManageEmployment={false}
      canManageStaff
      canLinkStaff={false}
    />);
    const openPanel = screen.getByRole('dialog', { name: 'Terminate staff' });
    fireEvent.change(within(openPanel).getByLabelText('Termination effective date'), {
      target: { value: '2026-10-09' },
    });
    fireEvent.change(within(openPanel).getByRole('textbox', { name: 'Reason for termination' }), {
      target: { value: 'Resignation' },
    });
    fireEvent.click(within(openPanel).getByRole('button', { name: 'Confirm termination' }));
    expect(state.post).toHaveBeenCalledWith(
      '/workspaces/hr/staff/staff-1/terminate',
      { terminated_date: '2026-10-09', reason: 'Resignation' },
      expect.objectContaining({ preserveScroll: true }),
    );
  });

  it('renders the chart-style staff banner with photo placeholder and collapsed details', () => {
    state.url = '/workspaces/hr/staff/staff-1';
    const { rerender } = render(<StaffProfile
      staff={{ ...staff, status: 'ACTIVE', photoUrl: null }}
      terminationBlockers={noTerminationBlockers}
      canManagePerson
      canManageEmployment
      canManageStaff
      canLinkStaff
    />);

    expect(screen.getByRole('heading', { name: 'Sam Example' })).toBeInTheDocument();
    const banner = screen.getByRole('banner', { name: 'Staff header' });
    expect(within(banner).getByText('Staff')).toBeInTheDocument();
    expect(within(banner).getByText('Staff number: STF-00000001')).toBeInTheDocument();
    expect(within(banner).getByText('Nurse')).toBeInTheDocument();
    expect(screen.getByRole('img', { name: 'Staff photo placeholder' })).toHaveTextContent('SE');
    const showMore = within(banner).getByRole('button', { name: 'Show more' });
    expect(showMore).toHaveAttribute('aria-expanded', 'false');
    expect(within(banner).queryByText('Contact')).not.toBeInTheDocument();

    fireEvent.click(showMore);
    expect(within(banner).getByRole('button', { name: 'Show less' })).toHaveAttribute(
      'aria-expanded',
      'true',
    );
    expect(within(banner).getByRole('heading', { name: 'Demographics' })).toBeInTheDocument();
    expect(within(banner).getByRole('heading', { name: 'Contact' })).toBeInTheDocument();
    expect(within(banner).getByRole('heading', { name: 'Staff record' })).toBeInTheDocument();
    expect(within(banner).getByRole('heading', { name: 'Employment' })).toBeInTheDocument();
    expect(within(banner).getByText('+255700000001')).toBeInTheDocument();

    rerender(<StaffProfile
      staff={{ ...staff, status: 'ACTIVE', photoUrl: '/staff/staff-1/photo' }}
      terminationBlockers={noTerminationBlockers}
      canManagePerson
      canManageEmployment
      canManageStaff
      canLinkStaff
    />);
    expect(screen.getByRole('img', { name: 'Sam Example staff photo' })).toHaveAttribute(
      'src',
      '/staff/staff-1/photo',
    );
  });

  it('does not confuse restricted employment data with a missing employment record', () => {
    state.url = '/workspaces/hr/staff/staff-1';
    const restricted = render(<StaffProfile
      staff={staff}
      terminationBlockers={noTerminationBlockers}
      canManagePerson={false}
      canManageEmployment={false}
      canManageStaff={false}
      canLinkStaff={false}
    />);

    expect(screen.getByText('Details not available for your access level')).toBeInTheDocument();
    restricted.unmount();

    render(<StaffProfile
      staff={{ ...staff, employment: null }}
      terminationBlockers={noTerminationBlockers}
      canManagePerson={false}
      canManageEmployment
      canManageStaff={false}
      canLinkStaff={false}
    />);
    expect(screen.getByText('No employment record')).toBeInTheDocument();
  });

  it('uses a labelled three-dots Actions menu button with icon-enabled items', () => {
    state.url = '/workspaces/hr/staff/staff-1';
    render(<StaffProfile
      staff={{ ...staff, status: 'ACTIVE' }}
      terminationBlockers={noTerminationBlockers}
      canManagePerson
      canManageEmployment
      canManageStaff
      canLinkStaff
    />);

    const banner = screen.getByRole('banner', { name: 'Staff header' });
    const actionsButton = within(banner).getByRole('button', { name: 'Actions' });
    expect(actionsButton).toHaveTextContent('Actions');
    expect(actionsButton.querySelector('svg')).toBeInTheDocument();

    fireEvent.click(actionsButton);

    expect(screen.getByRole('menuitem', { name: 'Edit personal details' }).querySelector('svg')).toBeInTheDocument();
    expect(screen.getByRole('menuitem', { name: 'Terminate staff' }).querySelector('svg')).toBeInTheDocument();
  });

  it('renders no quick-actions rail — record actions live in the Actions menu', () => {
    state.url = '/workspaces/hr/staff/staff-1';
    const { rerender } = render(<StaffProfile
      staff={{ ...staff, status: 'ACTIVE' }}
      terminationBlockers={noTerminationBlockers}
      canManagePerson
      canManageEmployment={false}
      canManageStaff
      canLinkStaff={false}
    />);

    expect(screen.queryByRole('complementary', { name: 'Quick actions' })).not.toBeInTheDocument();

    state.url = '/workspaces/hr/staff/staff-1?section=employment';
    rerender(<StaffProfile
      staff={{ ...staff, status: 'ACTIVE' }}
      terminationBlockers={noTerminationBlockers}
      canManagePerson
      canManageEmployment={false}
      canManageStaff
      canLinkStaff={false}
    />);
    expect(screen.queryByRole('complementary', { name: 'Quick actions' })).not.toBeInTheDocument();
  });

  it('docks staff workspace panels to the viewport edge with no rail to reserve', () => {
    state.url = '/workspaces/hr/staff/staff-1?section=personal';
    render(<StaffProfile
      staff={staff}
      terminationBlockers={noTerminationBlockers}
      canManagePerson
      canManageEmployment={false}
      canManageStaff={false}
      canLinkStaff={false}
    />);

    fireEvent.click(screen.getByRole('button', { name: 'Actions' }));
    fireEvent.click(screen.getByRole('menuitem', { name: 'Edit personal details' }));

    const panel = document.querySelector('.afy-dock');
    expect(panel).not.toBeNull();
    expect(panel).toHaveStyle({ right: '0px' });
  });

  it('guards dirty workspace switches with a discard prompt', () => {
    state.url = '/workspaces/hr/staff/staff-1';
    render(<StaffProfile
      staff={{ ...staff, status: 'ACTIVE' }}
      terminationBlockers={noTerminationBlockers}
      canManagePerson
      canManageEmployment={false}
      canManageStaff
      canLinkStaff={false}
    />);

    fireEvent.click(screen.getByRole('button', { name: 'Actions' }));
    fireEvent.click(screen.getByRole('menuitem', { name: 'Edit personal details' }));
    const editPanel = screen.getByRole('dialog', { name: 'Edit personal details' });
    fireEvent.change(within(editPanel).getByRole('textbox', { name: 'First name' }), {
      target: { value: 'Samuel' },
    });

    fireEvent.click(screen.getByRole('button', { name: 'Actions' }));
    fireEvent.click(screen.getByRole('menuitem', { name: 'Suspend staff' }));
    expect(screen.getByText('Discard unsaved changes?')).toBeInTheDocument();
    expect(screen.getByRole('dialog', { name: 'Edit personal details' })).toBeInTheDocument();

    fireEvent.click(screen.getByRole('button', { name: 'Discard changes' }));
    expect(screen.getByRole('dialog', { name: 'Suspend staff' })).toBeInTheDocument();
    expect(screen.queryByRole('dialog', { name: 'Edit personal details' })).not.toBeInTheDocument();
  });

  it('creates cadres and positions in workforce setup', () => {
    render(<PositionsManager cadres={cadres} positions={positions} />);

    fireEvent.click(screen.getByRole('button', { name: 'Add cadre' }));
    const cadrePanel = screen.getByRole('dialog', { name: 'Add job cadre' });
    expect(cadrePanel).toBeInTheDocument();
    fireEvent.change(screen.getByRole('textbox', { name: 'Cadre code' }), { target: { value: 'NURSE' } });
    fireEvent.change(screen.getByRole('textbox', { name: 'Cadre name' }), { target: { value: 'Nursing' } });
    fireEvent.click(within(cadrePanel).getByRole('button', { name: 'Add cadre' }));
    expect(state.post).toHaveBeenCalledWith(
      '/workspaces/hr/staff/positions/cadres',
      expect.objectContaining({ code: 'NURSE', name: 'Nursing' }),
      expect.objectContaining({ preserveScroll: true }),
    );
    const cadrePostCall = state.post.mock.calls.at(0);
    if (cadrePostCall === undefined) throw new Error('Cadre creation request was not submitted.');
    act(() => {
      (cadrePostCall[2] as { onSuccess: () => void }).onSuccess();
    });

    fireEvent.click(screen.getByRole('tab', { name: 'Positions (1)' }));
    fireEvent.click(screen.getByRole('button', { name: 'Add position' }));
    const positionPanel = screen.getByRole('dialog', { name: 'Add position' });
    fireEvent.change(screen.getByRole('textbox', { name: 'Position code' }), { target: { value: 'RN-01' } });
    fireEvent.change(screen.getByRole('textbox', { name: 'Position title' }), { target: { value: 'Registered Nurse' } });
    fireEvent.change(screen.getByLabelText('Job cadre', { selector: 'select' }), { target: { value: 'cadre-1' } });
    fireEvent.click(within(positionPanel).getByRole('button', { name: 'Add position' }));
    expect(state.post).toHaveBeenCalledWith(
      '/workspaces/hr/staff/positions',
      expect.objectContaining({ code: 'RN-01', title: 'Registered Nurse', cadre_id: 'cadre-1' }),
      expect.objectContaining({ preserveScroll: true }),
    );
  });

  it('requires a cadre before opening position creation', () => {
    render(<PositionsManager cadres={[]} positions={[]} />);

    fireEvent.click(screen.getByRole('tab', { name: 'Positions (0)' }));
    expect(screen.getByRole('button', { name: 'Add position' })).toBeDisabled();
    expect(screen.getByText('No positions yet')).toBeInTheDocument();
  });

  it('restores the selected job-structure tab from the URL', () => {
    window.history.replaceState({}, '', '/workspaces/hr/staff/positions?tab=positions');
    render(<PositionsManager cadres={cadres} positions={positions} />);

    expect(screen.getByRole('tab', { name: 'Positions (1)' })).toHaveAttribute('aria-selected', 'true');
    expect(screen.getByRole('table', { name: 'Job positions' })).toBeInTheDocument();
  });

  it('places add actions and search in each Carbon table toolbar', () => {
    const secondCadre = { id: 'cadre-2', code: 'MED', name: 'Medicine', category: 'CLINICAL' };
    const secondPosition = { id: 'position-2', code: 'MD-01', title: 'Physician', cadreId: secondCadre.id };
    render(<PositionsManager cadres={[...cadres, secondCadre]} positions={[...positions, secondPosition]} />);

    expect(screen.getByRole('table', { name: 'Job cadres' })).toBeInTheDocument();
    expect(screen.queryByRole('table', { name: 'Job positions' })).not.toBeInTheDocument();
    expect(screen.queryByRole('checkbox')).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /Add position in selected cadre/ })).not.toBeInTheDocument();
    const addCadreButton = screen.getByRole('button', { name: 'Add cadre' });
    expect(addCadreButton.closest('.cds--table-toolbar')).not.toBeNull();
    expect(screen.getByPlaceholderText('Search cadres')).toBeInTheDocument();
    fireEvent.change(screen.getByPlaceholderText('Search cadres'), { target: { value: 'not-a-cadre' } });
    expect(screen.queryByRole('row', { name: /NURSE Nursing/ })).not.toBeInTheDocument();

    fireEvent.click(screen.getByRole('tab', { name: 'Positions (2)' }));
    expect(screen.getByRole('table', { name: 'Job positions' })).toBeInTheDocument();
    expect(screen.queryByRole('table', { name: 'Job cadres' })).not.toBeInTheDocument();
    const addPositionButton = screen.getByRole('button', { name: 'Add position' });
    expect(addPositionButton.closest('.cds--table-toolbar')).not.toBeNull();
    const positionSearch = screen.getByPlaceholderText('Search positions');
    const cadreFilter = screen.getByRole('button', { name: 'Filter positions by cadre' });
    expect(cadreFilter.closest('.cds--table-toolbar')).not.toBeNull();
    expect(positionSearch.compareDocumentPosition(cadreFilter) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
    expect(cadreFilter.compareDocumentPosition(addPositionButton) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
    fireEvent.click(cadreFilter);
    fireEvent.click(screen.getByRole('checkbox', { name: 'Nursing' }));
    fireEvent.click(screen.getByRole('button', { name: 'Apply filter' }));
    expect(screen.getByRole('row', { name: /RN-01 Nurse Nursing/ })).toBeInTheDocument();
    expect(screen.queryByRole('row', { name: /MD-01 Physician Medicine/ })).not.toBeInTheDocument();

    fireEvent.click(screen.getByRole('button', { name: /Filter positions by cadre/ }));
    fireEvent.click(screen.getByRole('button', { name: 'Reset filters' }));
    expect(screen.getByRole('row', { name: /MD-01 Physician Medicine/ })).toBeInTheDocument();
  });
});

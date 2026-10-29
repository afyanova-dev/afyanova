import { fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import StaffOnboardingPage from '@/pages/HR/Staff/Onboarding';
import type { StaffOnboardingRow } from '@/features/hr/types';

const state = vi.hoisted(() => ({
  get: vi.fn(),
  visit: vi.fn(),
}));

vi.mock('@inertiajs/react', async () => {
  const { useState } = await import('react');

  return {
    Link: ({ children, href }: { children: React.ReactNode; href: string }) => <a href={href}>{children}</a>,
    router: { get: state.get, visit: state.visit },
    usePage: () => ({ url: '/workspaces/hr/staff/onboarding', props: {} }),
    useForm: (initial: Record<string, unknown>) => {
      const [data, setData] = useState(initial);
      return {
        data,
        setData: (key: string, value: unknown) => setData((current) => ({ ...current, [key]: value })),
        post: vi.fn(),
        put: vi.fn(),
        delete: vi.fn(),
        processing: false,
        errors: {},
        reset: vi.fn(),
        clearErrors: vi.fn(),
      };
    },
  };
});

vi.mock('@/layouts/AppLayout/AppLayout', () => ({
  AppLayout: ({ children }: { children: React.ReactNode }) => <>{children}</>,
}));

function row(overrides: Partial<StaffOnboardingRow>): StaffOnboardingRow {
  return {
    id: 'staff-1',
    staffNumber: 'STF-00000001',
    name: 'Neema Mushi',
    staffStatus: 'PROSPECT',
    positionTitle: 'Registered Nurse',
    linkedUserId: null,
    accountEmail: null,
    accountVerifiedAt: null,
    placement: {
      state: 'PENDING',
      label: 'Pending confirmation',
      requestId: 'request-1',
      departmentName: 'Outpatient',
      unitName: 'Consultation',
      effectiveFrom: '2026-10-01',
      decisionReason: null,
    },
    accountState: 'NO_ACCOUNT',
    invitation: {
      id: null,
      status: null,
      expiresAt: null,
      roleKeys: [],
    },
    account: {
      state: 'NO_ACCOUNT',
      label: 'No account',
      email: null,
      expiresAt: null,
      invitationStatus: null,
    },
    access: {
      state: 'WAITING',
      label: 'Waiting for account',
      pendingCount: 0,
      roleKeys: [],
      roleLabels: [],
    },
    employment: {
      state: 'NONE',
      label: 'No employment',
      status: null,
      startDate: null,
    },
    onboarding: {
      state: 'BLOCKED',
      label: 'Placement not confirmed',
    },
    nextAction: {
      kind: 'CONFIRM_PLACEMENT',
      label: 'Confirm placement',
    },
    needsAction: true,
    ...overrides,
  };
}

const rows: StaffOnboardingRow[] = [
  row({}),
  row({
    id: 'staff-2',
    staffNumber: 'STF-00000002',
    name: 'Amani Juma',
    placement: {
      state: 'CONFIRMED',
      label: 'Confirmed',
      requestId: null,
      departmentName: 'Outpatient',
      unitName: null,
      effectiveFrom: '2026-10-01',
      decisionReason: null,
    },
    accountState: 'INVITATION_EXPIRED',
    account: {
      state: 'INVITATION_EXPIRED',
      label: 'Invitation expired',
      email: 'amani@example.test',
      expiresAt: '2026-10-01T09:00:00Z',
      invitationStatus: 'EXPIRED',
    },
    employment: {
      state: 'DRAFT_DUE',
      label: 'Draft employment due',
      status: 'DRAFT',
      startDate: '2026-10-01',
    },
    onboarding: {
      state: 'READY',
      label: 'Ready to onboard',
    },
    nextAction: {
      kind: 'RESEND_INVITATION',
      label: 'Resend invitation',
    },
  }),
  row({
    id: 'staff-3',
    staffNumber: 'STF-00000003',
    name: 'Rehema Said',
    staffStatus: 'ACTIVE',
    placement: {
      state: 'CONFIRMED',
      label: 'Confirmed',
      requestId: null,
      departmentName: 'Outpatient',
      unitName: null,
      effectiveFrom: '2026-09-01',
      decisionReason: null,
    },
    accountState: 'ACTIVE',
    account: {
      state: 'ACTIVE',
      label: 'Active account',
      email: 'rehema@example.test',
      expiresAt: null,
      invitationStatus: 'CONSUMED',
    },
    access: {
      state: 'PENDING',
      label: '1 role pending',
      pendingCount: 1,
      roleKeys: ['nurse'],
      roleLabels: ['Nurse'],
    },
    employment: {
      state: 'ACTIVE',
      label: 'Employment active',
      status: 'ACTIVE',
      startDate: '2026-09-01',
    },
    onboarding: {
      state: 'ACTIVE',
      label: 'Active',
    },
    nextAction: {
      kind: 'APPROVE_ACCESS',
      label: 'Approve access',
    },
  }),
  row({
    id: 'staff-4',
    staffNumber: 'STF-00000004',
    name: 'Waiting Person',
    placement: {
      state: 'CONFIRMED',
      label: 'Confirmed',
      requestId: null,
      departmentName: 'Outpatient',
      unitName: null,
      effectiveFrom: '2026-10-01',
      decisionReason: null,
    },
    accountState: 'INVITED',
    account: {
      state: 'INVITED',
      label: 'Invitation pending',
      email: 'waiting@example.test',
      expiresAt: '2026-10-10T09:00:00Z',
      invitationStatus: 'PENDING',
    },
    onboarding: {
      state: 'READY',
      label: 'Ready to onboard',
    },
    nextAction: {
      kind: 'WAIT_ACCOUNT',
      label: 'Waiting for account setup',
    },
    needsAction: false,
  }),
];

const summary = {
  total: 4,
  needsAction: 3,
  placements: 1,
  invitations: 1,
  expiredInvitations: 1,
  access: 1,
  readyToOnboard: 2,
};

function renderPage(focus: 'needs-action' | 'placements' | 'ready' = 'needs-action'): void {
  render(
    <StaffOnboardingPage
      facility={{ id: 'facility-1', name: 'Central Clinic' }}
      rows={rows}
      summary={summary}
      focus={focus}
      permissions={{
        canReviewPlacements: true,
        canManageStaff: true,
        canManageAccess: true,
      }}
    />,
  );
}

describe('staff onboarding queue', () => {
  beforeEach(() => {
    state.get.mockClear();
    state.visit.mockClear();
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

  it('shows needs-action staff with direct links to the action surface', () => {
    renderPage();

    expect(screen.getByRole('heading', { name: 'Staff onboarding' })).toBeInTheDocument();
    expect(screen.getByText('Needs action (3)')).toBeInTheDocument();
    expect(screen.getByText('In progress (1)')).toBeInTheDocument();
    expect(screen.getByText('All (4)')).toBeInTheDocument();
    expect(screen.getByRole('link', { name: /Needs action/ })).toHaveAttribute(
      'href',
      '/workspaces/hr/staff/onboarding',
    );
    expect(screen.getByRole('link', { name: /Ready to onboard/ })).toHaveAttribute(
      'href',
      '/workspaces/hr/staff/onboarding?focus=ready',
    );
    expect(screen.getByRole('link', { name: 'Confirm placement' })).toHaveAttribute(
      'href',
      '/workspaces/hr/staff/assignment-requests',
    );
    expect(screen.getByRole('link', { name: 'Resend invitation' })).toHaveAttribute(
      'href',
      '/workspaces/hr/staff/staff-2?section=access&resend=1',
    );
    expect(screen.getByRole('link', { name: 'Approve access' })).toHaveAttribute(
      'href',
      '/workspaces/hr/staff/access-requests',
    );
    expect(screen.queryByText('Waiting Person')).not.toBeInTheDocument();
  });

  it('switches to the in-progress and all views', () => {
    renderPage();

    fireEvent.click(screen.getByRole('tab', { name: /In progress/ }));
    expect(screen.getByText('Waiting Person')).toBeInTheDocument();
    expect(screen.queryByText('Neema Mushi')).not.toBeInTheDocument();

    fireEvent.click(screen.getByRole('tab', { name: /All/ }));
    expect(screen.getByText('Neema Mushi')).toBeInTheDocument();
    expect(screen.getByText('Waiting Person')).toBeInTheDocument();
  });

  it('filters to staff who are ready to onboard', () => {
    renderPage('ready');

    expect(screen.getByText('Filtered: Ready to onboard')).toBeInTheDocument();
    expect(screen.getByText('Amani Juma')).toBeInTheDocument();
    expect(screen.getByText('Waiting Person')).toBeInTheDocument();
    expect(screen.queryByText('Neema Mushi')).not.toBeInTheDocument();
    expect(screen.queryByText('Rehema Said')).not.toBeInTheDocument();
  });

  it('filters by search and clears a dashboard focus', () => {
    renderPage('placements');

    expect(screen.getByText('Filtered: Placements')).toBeInTheDocument();
    expect(screen.getByText('Neema Mushi')).toBeInTheDocument();
    expect(screen.queryByText('Rehema Said')).not.toBeInTheDocument();

    fireEvent.change(screen.getByRole('searchbox', { name: 'Search onboarding staff' }), {
      target: { value: 'Amani' },
    });
    expect(screen.queryByText('Neema Mushi')).not.toBeInTheDocument();
    expect(screen.getByText('No staff match your search')).toBeInTheDocument();

    fireEvent.click(screen.getByRole('button', { name: 'Clear filter' }));
    expect(state.get).toHaveBeenCalledWith('/workspaces/hr/staff/onboarding');
  });
});

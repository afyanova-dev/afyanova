import { render, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { describe, expect, it, vi } from 'vitest';
import Dashboard from '../pages/Dashboard/Index';

const state = vi.hoisted(() => ({
  granted: new Set<string>(),
}));

vi.mock('@/lib/authorization', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/lib/authorization')>();
  return {
    ...actual,
    useCan: () => (permission: string) => state.granted.has(permission),
  };
});

vi.mock('@inertiajs/react', () => ({
  Link: ({ children, href, ...props }: { children: ReactNode; href: string }) => <a href={href} {...props}>{children}</a>,
  router: { get: vi.fn() },
  usePage: () => ({
    props: {
      app: { name: 'AfyaNova' },
      auth: {
        user: null,
        roles: [],
        can: {},
        facilities: [],
        activeFacility: null,
        contextMode: 'general',
        canUseGeneral: true,
      },
      flash: {},
      correlationId: null,
    },
    url: '/dashboard',
  }),
}));

vi.mock('@/layouts/AppLayout', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/layouts/AppLayout')>();
  return {
    ...actual,
    AppLayout: ({ children }: { children: ReactNode }) => <>{children}</>,
    Breadcrumbs: () => null,
  };
});

describe('Dashboard', () => {
  it('makes General working context explicit', () => {
    render(<Dashboard date="2026-10-04" quickActions={[]} widgets={[]} restricted={[]} unavailable={[]} pendingApprovals={[]} />);
    expect(screen.getByText(/General context: tenant-level work is shared/)).toBeInTheDocument();
  });

  it('keeps the dashboard focused on overview data rather than workspace navigation', () => {
    state.granted = new Set(['patient.patients.read', 'billing.access']);
    render(<Dashboard date="2026-10-04" quickActions={[]} widgets={[]} restricted={[]} unavailable={[]} pendingApprovals={[]} />);

    expect(screen.getByRole('heading', { name: 'Dashboard' })).toBeInTheDocument();
    expect(screen.queryByRole('heading', { name: 'Workspaces' })).not.toBeInTheDocument();
    expect(screen.queryByRole('link', { name: /Patients/ })).not.toBeInTheDocument();
    expect(screen.queryByRole('link', { name: /Billing/ })).not.toBeInTheDocument();
    expect(screen.queryByText('Today')).not.toBeInTheDocument();
  });

  it('does not duplicate HR navigation on the dashboard', () => {
    state.granted = new Set([
      'hr.staff.read',
      'hr.person.manage',
      'hr.staff.manage',
      'hr.assignment.manage',
      'hr.staff_user_link.manage',
      'hr.cadre_position.manage',
    ]);
    render(<Dashboard date="2026-10-04" quickActions={[]} widgets={[]} restricted={[]} unavailable={[]} pendingApprovals={[]} />);

    expect(screen.queryByRole('link', { name: /Staff directory/ })).not.toBeInTheDocument();
    expect(screen.queryByRole('link', { name: /Add staff/ })).not.toBeInTheDocument();
    expect(screen.queryByRole('link', { name: /Staff placements/ })).not.toBeInTheDocument();
  });

  it('keeps an empty overview free of workspace-launcher empty states', () => {
    state.granted = new Set();
    render(<Dashboard date="2026-10-04" quickActions={[]} widgets={[]} restricted={[]} unavailable={[]} pendingApprovals={[]} />);

    expect(screen.queryByText('You do not have access to any workspace.')).not.toBeInTheDocument();
    expect(screen.getByRole('heading', { name: 'Dashboard' })).toBeInTheDocument();
  });

  it('renders daily work widgets on the dashboard', () => {
    state.granted = new Set(['patient.patients.read']);
    render(
      <Dashboard
        date="2026-10-04"
        quickActions={[]}
        widgets={[
          { id: 'scheduled-today', title: 'Scheduled today', count: 4, href: '/workspaces/appointments/calendar' },
          { id: 'arrived-today', title: 'Arrived today', count: 2, href: '/workspaces/reception' },
        ]}
        restricted={['active-visits']}
        unavailable={[]}
        pendingApprovals={[]}
      />,
    );

    expect(screen.getByText('Today')).toBeInTheDocument();
    expect(screen.getByText('4')).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Open Scheduled today' })).toHaveAttribute(
      'href',
      '/workspaces/appointments/calendar',
    );
    expect(screen.queryByRole('heading', { name: 'Workspaces' })).not.toBeInTheDocument();
  });

  it('separates current workflow queues from daily totals', () => {
    state.granted = new Set(['patient.patients.read']);
    render(
      <Dashboard
        date="2026-10-04"
        quickActions={[]}
        widgets={[
          { id: 'arrived-today', title: 'Arrived today', count: 2, href: '/workspaces/reception' },
          { id: 'triage-queue', title: 'Triage queue', count: 3, href: '/workspaces/nurse-station', section: 'work' },
        ]}
        restricted={[]}
        unavailable={[]}
        pendingApprovals={[]}
      />,
    );

    expect(screen.getByRole('heading', { name: 'Today' })).toBeInTheDocument();
    expect(screen.getByRole('heading', { name: 'Work queues' })).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Open Triage queue' })).toHaveAttribute(
      'href',
      '/workspaces/nurse-station',
    );
  });

  it('renders HR staff and placement summaries instead of an empty page', () => {
    render(
      <Dashboard
        date="2026-10-04"
        quickActions={[]}
        widgets={[
          { id: 'hr-assigned-staff', title: 'Assigned staff', count: 0, href: '/workspaces/hr/staff' },
          {
            id: 'hr-pending-assignment-requests',
            title: 'Staff placements to review',
            count: 0,
            href: '/workspaces/hr/staff/onboarding?focus=placements',
          },
          {
            id: 'hr-pending-access-requests',
            title: 'Role approvals to review',
            count: 2,
            href: '/workspaces/hr/staff/onboarding?focus=access',
          },
          {
            id: 'hr-expired-invitations',
            title: 'Expired invitations',
            count: 1,
            href: '/workspaces/hr/staff/onboarding?focus=invitations',
          },
        ]}
        restricted={[]}
        unavailable={[]}
        pendingApprovals={[]}
      />,
    );

    expect(screen.getByRole('heading', { name: 'Workforce overview' })).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Open Assigned staff' }).closest('.afy-stats'))
      .toHaveAttribute('aria-label', 'Workforce overview');
    expect(screen.getByRole('link', { name: 'Open Assigned staff' })).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Open Staff placements to review' })).toHaveAttribute(
      'href',
      '/workspaces/hr/staff/onboarding?focus=placements',
    );
    expect(screen.getByRole('link', { name: 'Open Role approvals to review' })).toHaveAttribute(
      'href',
      '/workspaces/hr/staff/onboarding?focus=access',
    );
    expect(screen.getByRole('link', { name: 'Open Expired invitations' }))
      .toHaveAttribute('href', '/workspaces/hr/staff/onboarding?focus=invitations');
    expect(screen.getAllByText('0')).toHaveLength(2);
    expect(screen.getByText('2')).toBeInTheDocument();
    expect(screen.getByText('1')).toBeInTheDocument();
  });

  it('shows a compact empty queue row without a restricted-permissions alert', () => {
    state.granted = new Set();
    render(
      <Dashboard
        date="2026-10-04"
        quickActions={[]}
        widgets={[
          { id: 'triage-queue', title: 'Triage queue', count: 0, href: '/workspaces/nurse-station', section: 'work' },
        ]}
        restricted={['consultation-queue']}
        unavailable={['pharmacy-orders-to-process']}
        pendingApprovals={[]}
      />,
    );

    expect(screen.getByText('No items are waiting in this queue.')).toBeInTheDocument();
    expect(screen.queryByText('Some dashboard summaries are restricted')).not.toBeInTheDocument();
    expect(screen.getByText('Some dashboard summaries could not be loaded')).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Open Triage queue' })).toBeInTheDocument();
    expect(screen.getByRole('list', { name: 'Current work queues' })).toBeInTheDocument();
  });

  it('renders quick actions beside the page title', () => {
    state.granted = new Set(['patient.patients.create']);
    render(
      <Dashboard
        date="2026-10-04"
        quickActions={[
          { id: 'register-patient', title: 'Register new patient', href: '/workspaces/patient/register' },
        ]}
        widgets={[]}
        restricted={[]}
        unavailable={[]}
        pendingApprovals={[]}
      />,
    );

    expect(screen.queryByText('Quick actions')).not.toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Register new patient' })).toBeInTheDocument();
    expect(screen.queryByText('Today')).not.toBeInTheDocument();
  });

  it('shows pending access requests separately from workspace navigation', () => {
    state.granted = new Set();
    render(
      <Dashboard
        date="2026-10-04"
        quickActions={[]}
        widgets={[]}
        restricted={[]}
        unavailable={[]}
        pendingApprovals={[{ roleLabel: 'HR / Staff Manager', facilityName: 'Central Clinic' }]}
      />,
    );

    const requests = screen.getByRole('heading', { name: 'Role approvals' }).closest('section');
    expect(requests).toBeInTheDocument();
    expect(requests).toContainElement(screen.getByText('HR / Staff Manager'));
    expect(screen.queryByRole('heading', { name: 'Pending approvals' })).not.toBeInTheDocument();
    expect(screen.queryByRole('heading', { name: 'Under review' })).not.toBeInTheDocument();
    expect(screen.getByText('1 request')).toBeInTheDocument();
    expect(screen.getByText('HR / Staff Manager')).toBeInTheDocument();
    expect(screen.getByText('Central Clinic')).toBeInTheDocument();
    expect(screen.getByText('Under review')).toBeInTheDocument();

    const pendingTile = screen.getByText('HR / Staff Manager').closest('a');
    expect(pendingTile).toHaveAttribute('aria-disabled', 'true');
    expect(pendingTile).not.toHaveAttribute('href');

    expect(screen.queryByRole('heading', { name: 'Workspaces' })).not.toBeInTheDocument();
    expect(screen.queryByText('Access awaiting approval')).not.toBeInTheDocument();
    expect(screen.queryByText(/Your account setup is complete/i)).not.toBeInTheDocument();
    expect(screen.queryByText('The requested workspace will appear here once access is approved.')).not.toBeInTheDocument();
  });

  it('keeps pending access status visible without adding workspace links', () => {
    state.granted = new Set();
    render(
      <Dashboard
        date="2026-10-04"
        quickActions={[]}
        widgets={[]}
        restricted={[]}
        unavailable={[]}
        pendingApprovals={[{ roleLabel: 'HR / Staff Manager', facilityName: 'Central Clinic' }]}
      />,
    );

    expect(screen.queryByRole('link', { name: /Patients/ })).not.toBeInTheDocument();

    const pendingTile = screen.getByText('HR / Staff Manager').closest('a');
    expect(pendingTile).toHaveAttribute('aria-disabled', 'true');
    expect(pendingTile).not.toHaveAttribute('href');
    expect(screen.getAllByText('Under review')).toHaveLength(1);
  });
});

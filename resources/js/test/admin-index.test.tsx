import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import AdminIndex from '@/pages/Admin/Index';

vi.mock('@inertiajs/react', () => ({
  Link: ({ href, children }: { href: string; children: React.ReactNode }) => <a href={href}>{children}</a>,
  router: {
    visit: vi.fn(),
  },
}));

vi.mock('@/layouts/AppLayout/AppLayout', () => ({
  AppLayout: ({ children }: { children: React.ReactNode }) => <>{children}</>,
}));

describe('Admin workspace overview', () => {
  it('renders administrative KPI cards and pending role approvals needing decision', () => {
    render(
      <AdminIndex
        canManageOrganization
        canReviewRoleRequests
        canViewRoleCatalog
        canReviewAudit
        canManageAssignments
        canManageProcurementLimits
        stats={{
          facilities: { total: 4, active: 3 },
          pendingApprovalsCount: 2,
          auditEventsCount: 15,
          manageableFacilitiesCount: 3,
        }}
        pendingApprovals={[
          {
            id: 'req-1',
            roleKey: 'FACILITY_ADMIN',
            roleLabel: 'Facility Administrator',
            action: 'GRANT',
            accountEmail: 'doctor@afyanova.org',
            facilityName: 'Main Referral Hospital',
            createdAt: '2026-11-01T10:00:00Z',
          },
        ]}
      />,
    );

    expect(screen.getByRole('heading', { name: 'Admin workspace' })).toBeInTheDocument();
    expect(screen.getByRole('heading', { name: 'Administrative overview' })).toBeInTheDocument();
    expect(screen.getByRole('heading', { name: 'Role approvals needing decision' })).toBeInTheDocument();

    // Statistics cards
    expect(screen.getByText('3 active / 4 total')).toBeInTheDocument();
    expect(screen.getByText('Facilities')).toBeInTheDocument();
    expect(screen.getByText('Pending role approvals')).toBeInTheDocument();
    expect(screen.getByText('Audit events (7 days)')).toBeInTheDocument();
    expect(screen.getByText('Procurement limits')).toBeInTheDocument();
    expect(screen.getByText('3 facilities')).toBeInTheDocument();

    // Table rows
    expect(screen.getByText('doctor@afyanova.org')).toBeInTheDocument();
    expect(screen.getByText('Facility Administrator')).toBeInTheDocument();
    expect(screen.getByText('Main Referral Hospital')).toBeInTheDocument();
    expect(screen.getByText('GRANT')).toBeInTheDocument();

    // Navigation and obsolete headers not present
    expect(screen.queryByRole('heading', { name: 'Management areas' })).not.toBeInTheDocument();
  });

  it('shows an inline notification when there are no pending role approvals', () => {
    render(
      <AdminIndex
        canManageOrganization
        canReviewRoleRequests
        canViewRoleCatalog
        canReviewAudit
        canManageAssignments
        canManageProcurementLimits
        stats={{
          facilities: { total: 2, active: 2 },
          pendingApprovalsCount: 0,
          auditEventsCount: 5,
          manageableFacilitiesCount: 2,
        }}
        pendingApprovals={[]}
      />,
    );

    expect(screen.getByText('All role promotion requests have been reviewed')).toBeInTheDocument();
    expect(screen.getByText('There are currently no administrative access requests awaiting approval.')).toBeInTheDocument();
  });

  it('explains when the administrator has no management areas in their scope', () => {
    render(
      <AdminIndex
        canManageOrganization={false}
        canReviewRoleRequests={false}
        canViewRoleCatalog={false}
        canReviewAudit={false}
        canManageAssignments={false}
        canManageProcurementLimits={false}
      />,
    );

    expect(screen.getByText('Your current access does not include any Admin management pages. Contact your Tenant Admin.')).toBeInTheDocument();
    expect(screen.queryByRole('heading', { name: 'Administrative overview' })).not.toBeInTheDocument();
  });
});

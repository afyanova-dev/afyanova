import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import AdminIndex from '@/pages/Admin/Index';

vi.mock('@inertiajs/react', () => ({
  Link: ({ href, children }: { href: string; children: React.ReactNode }) => <a href={href}>{children}</a>,
}));

vi.mock('@/layouts/AppLayout/AppLayout', () => ({
  AppLayout: ({ children }: { children: React.ReactNode }) => <>{children}</>,
}));

describe('Admin workspace overview', () => {
  it('provides direct links to the management areas available to this administrator', () => {
    render(
      <AdminIndex
        canManageOrganization
        canReviewRoleRequests
        canReviewAudit
        canManageAssignments
        canManageProcurementLimits
      />,
    );

    expect(screen.getByRole('heading', { name: 'Admin workspace' })).toBeInTheDocument();
    expect(screen.queryByText('Tenant administration')).not.toBeInTheDocument();
    expect(screen.queryByRole('region', { name: 'Tenant administration summary' })).not.toBeInTheDocument();
    expect(screen.getByRole('heading', { name: 'Management areas' })).toBeInTheDocument();
    expect(screen.getByRole('link', { name: /Organization & facilities/ })).toHaveAttribute(
      'href',
      '/workspaces/admin/organization',
    );
    expect(screen.getByRole('link', { name: /Procurement limits/ })).toHaveAttribute(
      'href',
      '/workspaces/admin/procurement-limits',
    );
    expect(screen.getByRole('link', { name: /Identity & access/ })).toHaveAttribute(
      'href',
      '/workspaces/admin/identity/role-promotions',
    );
    expect(screen.getByRole('link', { name: /Audit log/ })).toHaveAttribute(
      'href',
      '/workspaces/admin/audit',
    );
    expect(screen.getByRole('link', { name: /Staff assignment approvals/ })).toHaveAttribute(
      'href',
      '/workspaces/hr/staff',
    );
    expect(screen.queryByText('Enabled')).not.toBeInTheDocument();
    expect(screen.queryByRole('navigation', { name: /breadcrumb/i })).not.toBeInTheDocument();
  });

  it('explains when the administrator has no management areas in their scope', () => {
    render(
      <AdminIndex
        canManageOrganization={false}
        canReviewRoleRequests={false}
        canReviewAudit={false}
        canManageAssignments={false}
        canManageProcurementLimits={false}
      />,
    );

    expect(screen.getByText('Your current access does not include any Admin management pages. Contact your Tenant Admin.')).toBeInTheDocument();
    expect(screen.queryByRole('link', { name: /Organization & facilities/ })).not.toBeInTheDocument();
  });
});

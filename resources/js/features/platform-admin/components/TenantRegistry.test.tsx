import { fireEvent, render, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { describe, expect, it, vi } from 'vitest';
import { TenantRegistry } from './TenantRegistry';
import type { TenantSummary } from '../types/platform-admin';

vi.mock('@inertiajs/react', async () => {
  const actual = await vi.importActual<typeof import('@inertiajs/react')>('@inertiajs/react');

  return {
    ...actual,
    Link: ({ children, href, ...props }: { children: ReactNode; href: string }) => <a href={href} {...props}>{children}</a>,
  };
});

const almasiClinic: TenantSummary = {
  id: 'tenant-1',
  name: 'Almasi Clinic',
  slug: 'almasi-clinic',
  status: 'ACTIVE',
  organizationCount: 1,
  facilityCount: 1,
  organizations: [{
    id: 'org-1',
    name: 'Almasi Organization',
    code: 'ALMASI',
    status: 'ACTIVE',
  }],
  facilities: [{
    id: 'facility-1',
    name: 'Almasi Main Facility',
    code: 'ALMASI_MAIN',
    type: 'DISPENSARY',
    careLevel: 'PRIMARY',
    status: 'ACTIVE',
  }],
  hasActiveAdmin: false,
  pendingAdminEmail: null,
  pendingAdminExpiresAt: null,
  pendingAdminInvitationExpired: false,
  canInviteAdmin: true,
};

const tenants: TenantSummary[] = [almasiClinic];
const operationalTenant: TenantSummary = { ...almasiClinic, hasActiveAdmin: true };

describe('tenant registry expansion', () => {
  it('explains what is flagged and shows onboarding progress inside the expanded row', () => {
    render(<TenantRegistry tenants={tenants} />);

    fireEvent.click(screen.getByRole('button', { name: 'Expand Almasi Clinic' }));

    expect(screen.getByRole('heading', { name: 'Attention' })).toBeInTheDocument();
    expect(screen.getByText('No Tenant Administrator')).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Open tenant' })).toHaveAttribute(
      'href',
      '/platform-admin/tenants/tenant-1',
    );
    expect(screen.getByRole('heading', { name: 'Onboarding' })).toBeInTheDocument();
    expect(screen.getByRole('list', { name: 'Onboarding progress' })).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Almasi Main Facility' })).toHaveAttribute(
      'href',
      '/platform-admin/tenants/tenant-1/facilities/facility-1',
    );
    expect(screen.getByRole('link', { name: 'Manage access' })).toHaveAttribute(
      'href',
      '/platform-admin/tenants/tenant-1',
    );
  });

  it('shows a clear state instead of an attention reason for operational tenants', () => {
    render(<TenantRegistry tenants={[operationalTenant]} />);

    fireEvent.click(screen.getByRole('button', { name: 'Expand Almasi Clinic' }));

    expect(screen.getByText(/All requirements met/)).toBeInTheDocument();
    expect(screen.queryByRole('link', { name: 'Open tenant' })).not.toBeInTheDocument();
    expect(screen.getByText('Almasi Organization')).toBeInTheDocument();
  });

  it('shows a pending Tenant Admin invitation in the registry row', () => {
    render(<TenantRegistry tenants={[{ ...almasiClinic, pendingAdminEmail: 'admin@almasi.health' }]} />);

    expect(screen.getByText('Invitation pending')).toBeInTheDocument();
    expect(screen.getByText('admin@almasi.health')).toBeInTheDocument();
  });

  it('labels an expired invitation distinctly from one that is still usable', () => {
    render(
      <TenantRegistry tenants={[{
        ...almasiClinic,
        pendingAdminEmail: 'admin@almasi.health',
        pendingAdminExpiresAt: '2026-10-24T12:00:00Z',
        pendingAdminInvitationExpired: true,
      }]} />,
    );

    expect(screen.getByText('Invitation expired')).toBeInTheDocument();
  });
});

import { fireEvent, render, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { PlatformAdminWorkspace } from './PlatformAdminWorkspace';

const { visit } = vi.hoisted(() => ({ visit: vi.fn() }));

vi.mock('@inertiajs/react', async () => {
  const actual = await vi.importActual<typeof import('@inertiajs/react')>('@inertiajs/react');

  return {
    ...actual,
    Link: ({ children, href, ...props }: { children: ReactNode; href: string }) => <a href={href} {...props}>{children}</a>,
    router: { ...actual.router, visit },
  };
});

vi.mock('@/layouts/AppLayout/AppLayout', () => ({
  AppLayout: ({ children }: { children: ReactNode }) => <>{children}</>,
}));

vi.mock('./CreateTenantWorkflow', () => ({
  CreateTenantWorkflow: ({ onClose }: { onClose: () => void }) => (
    <div role="dialog" aria-label="Create tenant">
      <button type="button" onClick={onClose}>Cancel</button>
    </div>
  ),
}));

vi.mock('./TenantRegistry', () => ({
  TenantRegistry: () => <div data-testid="tenant-registry">Tenant registry body</div>,
}));

const tenants = [
  {
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
      name: 'Almasi Clinic',
      code: 'ALMASI_CLINIC',
      type: 'CLINIC',
      careLevel: 'PRIMARY',
      status: 'ACTIVE',
    }],
    hasActiveAdmin: false,
    pendingAdminEmail: null,
    pendingAdminExpiresAt: null,
    pendingAdminInvitationExpired: false,
    canInviteAdmin: true,
  },
];

const facilityReferences = {
  facilityTypes: [],
  careLevels: [],
  ownershipTypes: [],
};

describe('platform administration workspace', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('renders the super-admin dashboard without tabs', () => {
    render(
      <PlatformAdminWorkspace
        tenants={tenants}
        facilityReferences={facilityReferences}
        section="overview"
      />,
    );

    expect(screen.getByRole('heading', { level: 1, name: /Dashboard/ })).toBeInTheDocument();
    expect(screen.queryByRole('tablist')).not.toBeInTheDocument();
    expect(screen.getByRole('region', { name: 'Platform summary' })).toBeInTheDocument();
    expect(screen.getByText('Workspaces')).toBeInTheDocument();
    expect(screen.getByRole('link', { name: /Tenant registry/ })).toBeInTheDocument();
    expect(screen.getByRole('link', { name: /Platform audit/ })).toHaveAttribute('href', '/platform-admin/audit');
    expect(screen.getByRole('link', { name: /Super Admin access/ })).toHaveAttribute(
      'href',
      '/platform-admin/super-admins',
    );
  });

  it('opens the create dock from the page action and closes it in place', () => {
    render(
      <PlatformAdminWorkspace
        tenants={tenants}
        facilityReferences={facilityReferences}
        section="overview"
      />,
    );

    fireEvent.click(screen.getByRole('button', { name: 'Create tenant' }));
    expect(screen.getByRole('dialog', { name: 'Create tenant' })).toBeInTheDocument();

    fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));
    expect(screen.queryByRole('dialog', { name: 'Create tenant' })).not.toBeInTheDocument();
    expect(visit).not.toHaveBeenCalled();
  });

  it('renders the tenant registry destination', () => {
    render(
      <PlatformAdminWorkspace
        tenants={tenants}
        facilityReferences={facilityReferences}
        section="tenants"
      />,
    );

    expect(screen.getByRole('heading', { level: 1, name: /Tenant registry/ })).toBeInTheDocument();
    expect(screen.getByTestId('tenant-registry')).toBeInTheDocument();
  });

  it('deep-links the create route into the dock and returns to the registry on close', () => {
    render(
      <PlatformAdminWorkspace
        tenants={tenants}
        facilityReferences={facilityReferences}
        section="create"
      />,
    );

    expect(screen.getByRole('dialog', { name: 'Create tenant' })).toBeInTheDocument();

    fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));
    expect(visit).toHaveBeenCalledWith('/platform-admin/tenants', { preserveScroll: true });
  });
});

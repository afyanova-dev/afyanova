import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import AdminRoleCatalog from '@/pages/Admin/Identity/RoleCatalog';

vi.mock('@inertiajs/react', () => ({
  router: { get: vi.fn() },
}));

vi.mock('@/layouts/AppLayout/AppLayout', () => ({
  AppLayout: ({ children }: { children: React.ReactNode }) => <>{children}</>,
}));

describe('Admin role catalogue', () => {
  it('shows role scope and permissions without offering access changes', () => {
    render(
      <AdminRoleCatalog
        roles={{
          data: [{
            id: 'role-1',
            name: 'Facility Admin',
            key: 'facility_admin',
            scope: 'FACILITY',
            status: 'ACTIVE',
            isProtected: true,
            permissions: [{ key: 'identity.roles.read', layer: 'ACTION' }],
          }],
          total: 1,
          currentPage: 1,
          lastPage: 1,
          perPage: 50,
        }}
      />,
    );

    expect(screen.getByRole('heading', { name: 'Role catalogue' })).toBeInTheDocument();
    expect(screen.getByText('Facility Admin')).toBeInTheDocument();
    expect(screen.getByText('FACILITY')).toBeInTheDocument();
    expect(screen.getByText('Protected')).toBeInTheDocument();
    expect(screen.getByText('identity.roles.read (ACTION)')).toBeInTheDocument();
    expect(screen.queryByRole('form')).not.toBeInTheDocument();
    expect(screen.queryByRole('button')).not.toBeInTheDocument();
  });
});

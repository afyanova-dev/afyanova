import { fireEvent, render, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { TenantManagementPanel } from './TenantManagementPanel';
import type { TenantDetails } from '../types/platform-admin';

// Carbon TextArea uses ResizeObserver; jsdom does not provide it.
globalThis.ResizeObserver ??= class {
  observe() {}
  unobserve() {}
  disconnect() {}
} as unknown as typeof ResizeObserver;

const { onClose } = vi.hoisted(() => ({ onClose: vi.fn() }));

vi.mock('@inertiajs/react', async () => {
  const React = await import('react');
  const actual = await vi.importActual<typeof import('@inertiajs/react')>('@inertiajs/react');

  return {
    ...actual,
    Link: ({ children, href, ...props }: { children: ReactNode; href: string }) => <a href={href} {...props}>{children}</a>,
    router: { post: vi.fn() },
    useForm: (initial: Record<string, unknown>) => {
      const [data, setData] = React.useState(initial);

      return {
        data,
        setData: (key: string | ((current: Record<string, unknown>) => Record<string, unknown>), value?: unknown) => setData(
          typeof key === 'function'
            ? (current) => key(current)
            : (current) => ({ ...current, [key]: value }),
        ),
        post: vi.fn(),
        processing: false,
        errors: {},
        reset: vi.fn(),
      };
    },
  };
});

const tenant: TenantDetails = {
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
  administrators: [],
  pendingRevocations: [],
  emergencyReviews: [],
  hasActiveTenantAdmin: false,
};

describe('tenant management dock', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('keeps organization and facility details in the registry expansion, not the dock', () => {
    render(<TenantManagementPanel tenant={tenant} onClose={onClose} />);

    expect(screen.getByRole('heading', { name: 'Almasi Clinic' })).toBeInTheDocument();
    expect(screen.getByRole('heading', { name: 'Initial Tenant Admin' })).toBeInTheDocument();
    expect(screen.queryByRole('heading', { name: 'Organization' })).not.toBeInTheDocument();
    expect(screen.queryByRole('heading', { name: 'Facilities' })).not.toBeInTheDocument();
    expect(screen.queryByText('Almasi Organization')).not.toBeInTheDocument();
    expect(screen.queryByText('Almasi Main Facility')).not.toBeInTheDocument();
  });

  it('submits the invitation from the panel footer like other docked panels', () => {
    render(<TenantManagementPanel tenant={tenant} onClose={onClose} />);

    const send = screen.getByRole('button', { name: 'Send setup invitation' });
    expect(send).toHaveAttribute('form', 'tenant-admin-invitation');
    fireEvent.click(screen.getByRole('button', { name: 'Close' }));
    expect(onClose).toHaveBeenCalledTimes(1);
  });

  it('offers to resend the pending invitation with a rotate checkbox', () => {
    render(
      <TenantManagementPanel
        tenant={{
          ...tenant,
          pendingAdminEmail: 'admin@almasi.health',
          pendingAdminExpiresAt: '2026-10-25T12:00:00Z',
          pendingAdminInvitationExpired: true,
        }}
        onClose={onClose}
      />,
    );

    expect(screen.getByRole('button', { name: 'Resend setup invitation' })).toHaveAttribute('form', 'tenant-admin-invitation');
    expect(screen.getByLabelText(/Rotate and resend the pending invitation/)).toBeInTheDocument();
    expect(screen.getByText(/Invitation expired for admin@almasi\.health/)).toBeInTheDocument();
    expect(screen.getByText(/expires/)).toBeInTheDocument();
  });

  it('requires a reason and confirmation before changing tenant lifecycle status', () => {
    render(<TenantManagementPanel tenant={tenant} onClose={onClose} />);

    expect(screen.getByRole('button', { name: 'Suspend tenant' })).toBeDisabled();
    fireEvent.change(screen.getByLabelText(/Reason for status change/), {
      target: { value: 'Temporary operational pause' },
    });
    expect(screen.getByRole('button', { name: 'Suspend tenant' })).toBeDisabled();
    fireEvent.click(screen.getByLabelText('I confirm this tenant suspension.'));
    expect(screen.getByRole('button', { name: 'Suspend tenant' })).toBeEnabled();
  });

  it('shows governed-access states without nested detail tiles', () => {
    render(
      <TenantManagementPanel
        tenant={{
          ...tenant,
          hasActiveTenantAdmin: true,
          canInviteAdmin: false,
          administrators: [{
            id: 'admin-1',
            assignmentId: 'assignment-1',
            name: 'Grace Mwangi',
            email: 'grace@almasi.health',
            accountStatus: 'ACTIVE',
          }],
          pendingRevocations: [{
            id: 'revocation-1',
            name: 'Grace Mwangi',
            email: 'grace@almasi.health',
          }],
        }}
        onClose={onClose}
      />,
    );

    expect(screen.getByRole('heading', { name: 'Active administrators' })).toBeInTheDocument();
    expect(screen.getAllByText('Grace Mwangi').length).toBeGreaterThan(0);
    expect(screen.getAllByText('grace@almasi.health').length).toBeGreaterThan(0);
    expect(screen.getByRole('heading', { name: 'Revocations awaiting approval' })).toBeInTheDocument();
    expect(screen.getAllByText('Awaiting approval').length).toBeGreaterThan(0);
    expect(screen.queryByText('No active Tenant Admin')).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /setup invitation/ })).not.toBeInTheDocument();
  });
});

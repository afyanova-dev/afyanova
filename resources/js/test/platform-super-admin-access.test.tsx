import { fireEvent, render, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { describe, expect, it, vi } from 'vitest';
import { routes } from '@/app/routes';
import SuperAdminAccessIndex from '@/pages/PlatformAdmin/SuperAdmins/Index';

const { post } = vi.hoisted(() => ({ post: vi.fn() }));

vi.mock('@inertiajs/react', () => ({
  router: { post },
}));

vi.mock('@/layouts/AppLayout/AppLayout', () => ({
  AppLayout: ({ children }: { children: ReactNode }) => <>{children}</>,
}));

globalThis.ResizeObserver ??= class {
  observe() {}
  unobserve() {}
  disconnect() {}
} as unknown as typeof ResizeObserver;

const administrators = [
  {
    id: 'admin-1',
    name: 'Amina Operator',
    email: 'amina@example.test',
    status: 'ACTIVE',
    twoFactorEnabled: true,
  },
  {
    id: 'admin-2',
    name: 'Baraka Reviewer',
    email: 'baraka@example.test',
    status: 'ACTIVE',
    twoFactorEnabled: true,
  },
  {
    id: 'admin-3',
    name: 'Chiku Target',
    email: 'chiku@example.test',
    status: 'ACTIVE',
    twoFactorEnabled: true,
  },
];

describe('Platform Super Admin access management', () => {
  it('prevents self-management and lets a distinct administrator review an access request', () => {
    render(
      <SuperAdminAccessIndex
        administrators={administrators}
        currentAdminId="admin-2"
        requests={[{
          id: 'request-1',
          targetId: 'admin-3',
          targetName: 'Chiku Target',
          requesterId: 'admin-1',
          requesterName: 'Amina Operator',
          reviewerName: null,
          action: 'RESET_MFA',
          status: 'PENDING',
          reason: 'Administrator lost access to MFA device',
          reviewNotes: null,
          createdAt: '2026-10-25T10:00:00Z',
        }]}
      />,
    );

    expect(screen.getByText('You cannot request a change to your own account.')).toBeInTheDocument();
    expect(screen.getByLabelText(/Independent review notes/)).toBeInTheDocument();
    expect(screen.getAllByRole('button', { name: 'Request action' })).toHaveLength(2);

    fireEvent.change(screen.getByLabelText(/Independent review notes/), {
      target: { value: 'Identity evidence reviewed independently' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Approve' }));

    expect(post).toHaveBeenCalledWith(
      routes.platformAdmin.reviewSuperAdminAccessRequest('request-1'),
      { approve: true, review_notes: 'Identity evidence reviewed independently' },
      expect.objectContaining({ preserveScroll: true }),
    );
  });
});

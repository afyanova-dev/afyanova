import { fireEvent, render, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { CreateTenantWorkflow } from './CreateTenantWorkflow';

const { post } = vi.hoisted(() => ({ post: vi.fn() }));

vi.mock('@inertiajs/react', async () => {
  const React = await import('react');
  const actual = await vi.importActual<typeof import('@inertiajs/react')>('@inertiajs/react');

  return {
    ...actual,
    Link: ({ children, href, ...props }: { children: ReactNode; href: string }) => <a href={href} {...props}>{children}</a>,
    useForm: (initial: Record<string, string>) => {
      const [data, setFormData] = React.useState(initial);

      return {
        data,
        setData: (key: string, value: string) => setFormData((current) => ({ ...current, [key]: value })),
        post,
        processing: false,
        errors: {},
      };
    },
  };
});

vi.mock('@/layouts/AppLayout/AppLayout', () => ({
  AppLayout: ({ children }: { children: ReactNode }) => <>{children}</>,
}));

vi.mock('@/layouts/AppLayout', () => ({
  Breadcrumbs: () => null,
}));

const facilityReferences = {
  facilityTypes: [{ code: 'DISPENSARY', name: 'Dispensary' }],
  careLevels: [{ code: 'PRIMARY', name: 'Community / Primary' }],
  ownershipTypes: [{ code: 'PUBLIC', name: 'Public' }],
};

describe('platform tenant setup form', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('presents tenant identity, organization, and facility sections in one organized form and submits them together', () => {
    render(<CreateTenantWorkflow facilityReferences={facilityReferences} onClose={() => {}} />);

    expect(screen.getByRole('heading', { name: 'Tenant identity' })).toBeInTheDocument();
    expect(screen.getByRole('heading', { name: 'Healthcare organization' })).toBeInTheDocument();
    expect(screen.getByRole('heading', { name: 'First facility' })).toBeInTheDocument();
    expect(screen.queryByText('Setup summary')).not.toBeInTheDocument();

    expect(screen.getByLabelText('Tenant name')).toHaveValue('');
    expect(screen.getByLabelText('Tenant URL slug')).toHaveValue('');
    expect(screen.getByLabelText('Organization name')).toHaveValue('');
    expect(screen.getByLabelText('Organization code')).toHaveValue('');
    expect(screen.getByLabelText('Facility name')).toHaveValue('');
    expect(screen.getByLabelText('Facility code')).toBeInTheDocument();

    fireEvent.change(screen.getByLabelText('Tenant name'), { target: { value: 'Almasi Community Clinic' } });
    fireEvent.change(screen.getByLabelText('Tenant URL slug'), { target: { value: 'almasi-clinic' } });
    fireEvent.change(screen.getByLabelText('Organization name'), { target: { value: 'Almasi Healthcare Organization' } });
    fireEvent.change(screen.getByLabelText('Organization code'), { target: { value: 'ALMASI' } });
    fireEvent.change(screen.getByLabelText('Facility name'), { target: { value: 'Almasi Main Facility' } });
    fireEvent.change(screen.getByLabelText('Facility code'), { target: { value: 'ALMASI_MAIN' } });
    fireEvent.change(screen.getByLabelText('Facility type'), { target: { value: 'DISPENSARY' } });
    fireEvent.change(screen.getByLabelText('Care level'), { target: { value: 'PRIMARY' } });
    fireEvent.change(screen.getByLabelText('Ownership'), { target: { value: 'PUBLIC' } });

    fireEvent.click(screen.getByRole('button', { name: 'Create tenant and facility' }));
    expect(post).toHaveBeenCalledWith('/platform-admin/tenants', { preserveScroll: true });
  });

  it('requires the tenant slug and organization/facility codes to follow their canonical formats', () => {
    render(<CreateTenantWorkflow facilityReferences={facilityReferences} onClose={() => {}} />);

    fireEvent.change(screen.getByLabelText('Tenant URL slug'), { target: { value: 'Not A Slug' } });
    fireEvent.click(screen.getByRole('button', { name: 'Create tenant and facility' }));
    expect(screen.getByText('Use lowercase letters, numbers, and single hyphens for the tenant slug.')).toBeInTheDocument();
    expect(post).not.toHaveBeenCalled();
  });

  it('marks missing required fields without submitting', () => {
    render(<CreateTenantWorkflow facilityReferences={facilityReferences} onClose={() => {}} />);

    fireEvent.change(screen.getByLabelText('Tenant name'), { target: { value: '' } });
    fireEvent.click(screen.getByRole('button', { name: 'Create tenant and facility' }));
    expect(screen.getByText('Enter a tenant name.')).toBeInTheDocument();
    expect(post).not.toHaveBeenCalled();
  });
});

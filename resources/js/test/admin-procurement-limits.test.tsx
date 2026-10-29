import { fireEvent, render, screen, within } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import AdminProcurementLimitsIndex from '@/pages/Admin/ProcurementLimits/Index';

const { formPost, formTransform, formReset } = vi.hoisted(() => ({
  formPost: vi.fn(),
  formTransform: vi.fn(),
  formReset: vi.fn(),
}));

vi.mock('@inertiajs/react', async () => {
  const { useState } = await import('react');
  return {
    Link: ({ children }: { children: React.ReactNode }) => <>{children}</>,
    useForm: (initialData: Record<string, string | boolean>) => {
      const [data, setState] = useState(initialData);
      return {
        data,
        errors: {},
        processing: false,
        setData: (key: string, value: string | boolean) => setState((current) => ({ ...current, [key]: value })),
        transform: formTransform,
        post: formPost,
        reset: formReset,
      };
    },
  };
});

vi.mock('@/layouts/AppLayout/AppLayout', () => ({
  AppLayout: ({ children }: { children: React.ReactNode }) => <>{children}</>,
}));

const facility = {
  id: 'facility-1',
  name: 'Mwanza Health Centre',
  type: 'HEALTH_CENTRE',
  limit: null,
  effectiveLimit: '1000000.00',
  departments: [
    {
      id: 'department-lab',
      name: 'Laboratory',
      code: 'LAB',
      limit: '120000.00',
      effectiveLimit: '120000.00',
    },
    {
      id: 'department-opd',
      name: 'Outpatient',
      code: 'OPD',
      limit: null,
      effectiveLimit: '1000000.00',
    },
  ],
};

const secondFacility = {
  id: 'facility-2',
  name: 'Kigoma Dispensary',
  type: 'DISPENSARY',
  limit: '250000.00',
  effectiveLimit: '250000.00',
  departments: [],
};

/** Open the docked editor for a facility row. */
function openFacilityPanel(name: string): void {
  fireEvent.click(screen.getByRole('button', { name: `Manage approval limits for ${name}` }));
}

describe('Admin procurement limits', () => {
  it('lists every facility in the directory with its limit summary', () => {
    render(
      <AdminProcurementLimitsIndex
        facilities={[facility, secondFacility]}
        typeDefaults={{ DISPENSARY: '500000', HEALTH_CENTRE: '1000000' }}
      />,
    );

    const table = screen.getByRole('table', { name: '2 facilities' });

    expect(within(table).getByText('Mwanza Health Centre')).toBeInTheDocument();
    expect(within(table).getByText('Kigoma Dispensary')).toBeInTheDocument();
    expect(within(table).getAllByText('TZS 1,000,000').length).toBeGreaterThan(0);
    expect(within(table).getAllByText('TZS 250,000').length).toBeGreaterThan(0);
    expect(within(table).getByText('1 of 2 set')).toBeInTheDocument();
    expect(within(table).getByText('None')).toBeInTheDocument();
    expect(screen.getByText('2')).toBeInTheDocument();
    expect(screen.getByText('Facilities')).toBeInTheDocument();
  });

  it('shows the facility rule and the facility-type starting point when nothing is set', () => {
    render(
      <AdminProcurementLimitsIndex
        facilities={[facility]}
        typeDefaults={{ DISPENSARY: '500000', HEALTH_CENTRE: '1000000' }}
      />,
    );
    openFacilityPanel('Mwanza Health Centre');

    expect(screen.getByRole('heading', { name: /Mwanza Health Centre/ })).toBeInTheDocument();
    expect(screen.getByTestId('approval-limit-summary-facility-1')).toHaveTextContent(
      'Whole facility: TZS 1,000,000 (type default)',
    );
    expect(screen.getByRole('heading', { name: /Departments with their own limit/ })).toBeInTheDocument();
    expect(screen.getByText(/Laboratory: TZS 120,000/)).toBeInTheDocument();
  });

  it('switches the summary to a department the administrator selects', () => {
    render(<AdminProcurementLimitsIndex facilities={[facility]} typeDefaults={{}} />);
    openFacilityPanel('Mwanza Health Centre');

    fireEvent.change(screen.getByLabelText('Applies to'), { target: { value: 'department-lab' } });

    expect(screen.getByTestId('approval-limit-summary-facility-1')).toHaveTextContent(
      'Laboratory: TZS 120,000',
    );
  });

  it('posts the selected scope, the amount and a clear flag on submit', () => {
    render(<AdminProcurementLimitsIndex facilities={[facility]} typeDefaults={{}} />);
    openFacilityPanel('Mwanza Health Centre');

    fireEvent.change(screen.getByLabelText('Applies to'), { target: { value: 'department-lab' } });
    fireEvent.change(screen.getByLabelText(/One person may approve up to/), { target: { value: '150000' } });
    fireEvent.click(screen.getByRole('button', { name: 'Save limit' }));

    expect(formTransform).toHaveBeenCalled();
    expect(formPost).toHaveBeenCalledWith(
      '/workspaces/admin/procurement-limits',
      expect.objectContaining({ preserveScroll: true }),
    );
  });

  it('disables Remove for a scope that has no rule of its own', () => {
    render(<AdminProcurementLimitsIndex facilities={[facility]} typeDefaults={{}} />);
    openFacilityPanel('Mwanza Health Centre');

    expect(screen.getByRole('button', { name: 'Remove this rule' })).toBeDisabled();

    fireEvent.change(screen.getByLabelText('Applies to'), { target: { value: 'department-lab' } });

    expect(screen.getByRole('button', { name: 'Remove this rule' })).toBeEnabled();
  });

  it('closes the editor without leaving a facility selected', () => {
    render(<AdminProcurementLimitsIndex facilities={[facility]} typeDefaults={{}} />);
    openFacilityPanel('Mwanza Health Centre');

    expect(screen.getByRole('heading', { name: /Mwanza Health Centre/ })).toBeInTheDocument();

    fireEvent.click(screen.getByRole('button', { name: 'Close Mwanza Health Centre' }));

    expect(screen.queryByRole('heading', { name: /Mwanza Health Centre/ })).not.toBeInTheDocument();
  });
});

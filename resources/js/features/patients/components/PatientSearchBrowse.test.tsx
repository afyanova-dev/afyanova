import { act, fireEvent, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { PatientSearchBrowse } from './PatientSearchBrowse';

const state = vi.hoisted(() => ({
  get: vi.fn(),
}));

vi.mock('@inertiajs/react', () => ({
  Link: ({ children, href }: React.AnchorHTMLAttributes<HTMLAnchorElement>) => <a href={href}>{children}</a>,
  router: { get: state.get },
}));

const baseProps = {
  q: '',
  mode: 'browse' as const,
  patients: [
    {
      patientId: 'patient-1',
      patientNumber: 'PAT-00000001',
      displayName: 'Amina Hassan',
      sex: 'FEMALE',
      status: 'ACTIVE',
      isProvisional: false,
      hasAccessibleFacilityRegistration: true,
      survivingPatientId: null,
    },
  ],
  total: 1,
  currentPage: 1,
  lastPage: 1,
  perPage: 15,
  searchError: null,
};

beforeEach(() => {
  vi.clearAllMocks();
  // applyListQuery visits the current pathname (alias-safe) — put the
  // component on its real route before asserting visit targets.
  window.history.replaceState({}, '', '/workspaces/patient');
});

describe('Patient Search & Browse', () => {
  it('renders browse mode and patient rows in a Carbon DataTable', () => {
    render(<PatientSearchBrowse {...baseProps} />);

    expect(screen.getByRole('table')).toBeInTheDocument();
    expect(screen.getByText('Amina Hassan')).toBeInTheDocument();
    expect(screen.getByText('PAT-00000001')).toBeInTheDocument();
    expect(screen.getByRole('columnheader', { name: 'Patient number' })).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Amina Hassan' })).toHaveAttribute('href', '/patient/patient-1/chart');
    expect(screen.getByRole('link', { name: 'PAT-00000001' })).toHaveAttribute('href', '/patient/patient-1/chart');
  });

  it('submits the trimmed search term in the URL using backend query keys', async () => {
    const user = userEvent.setup();
    render(<PatientSearchBrowse {...baseProps} />);
    await user.type(screen.getByRole('searchbox', { name: 'Search patients' }), 'Amina');
    await user.keyboard('{Enter}');

    expect(state.get).toHaveBeenCalledWith(
      '/workspaces/patient',
      { q: 'Amina', per_page: 15 },
      expect.objectContaining({ preserveState: true, preserveScroll: true }),
    );
  });

  it('paginates on the server and preserves the current search', () => {
    render(<PatientSearchBrowse {...baseProps} q="Amina" mode="search" total={40} lastPage={3} />);
    fireEvent.click(screen.getByRole('button', { name: /next page/i }));

    expect(state.get).toHaveBeenCalledWith(
      '/workspaces/patient',
      { q: 'Amina', page: 2, per_page: 15 },
      expect.objectContaining({ preserveState: true }),
    );
  });

  it('clearing the search returns to browse mode through the blank query', () => {
    render(<PatientSearchBrowse {...baseProps} q="Amina" mode="search" />);
    fireEvent.click(screen.getByRole('button', { name: /clear/i }));

    expect(state.get).toHaveBeenCalledWith(
      '/workspaces/patient',
      { per_page: 15 },
      expect.objectContaining({ preserveState: true }),
    );
  });

  it('deleting the box back to empty restores browse without Enter', () => {
    render(<PatientSearchBrowse {...baseProps} q="Amina" mode="search" />);
    const box = screen.getByRole('searchbox', { name: 'Search patients' });
    fireEvent.change(box, { target: { value: 'A' } });
    expect(state.get).not.toHaveBeenCalled();

    fireEvent.change(box, { target: { value: '' } });
    expect(state.get).toHaveBeenCalledWith(
      '/workspaces/patient',
      { per_page: 15 },
      expect.objectContaining({ preserveState: true }),
    );
  });

  it('shows different empty states for browse and search', () => {
    const { rerender } = render(<PatientSearchBrowse {...baseProps} patients={[]} total={0} />);
    expect(screen.getByText('No patients have been registered yet.')).toBeInTheDocument();

    rerender(<PatientSearchBrowse {...baseProps} q="Unknown" mode="search" patients={[]} total={0} />);
    expect(screen.getByText('No patients found')).toBeInTheDocument();
  });

  it('shows the backend criteria error instead of a no-results message', () => {
    render(
      <PatientSearchBrowse
        {...baseProps}
        q="AB"
        mode="search"
        patients={[]}
        total={0}
        searchError="A name-fragment search needs at least 3 characters."
      />,
    );

    expect(screen.getByRole('alert')).toHaveTextContent('A name-fragment search needs at least 3 characters.');
    expect(screen.queryByText('No patients found')).not.toBeInTheDocument();
  });

  it('shows a table skeleton on first load when no rows exist yet', () => {
    render(<PatientSearchBrowse {...baseProps} patients={[]} total={0} />);
    fireEvent.submit(screen.getByRole('form', { name: 'Patient search' }));

    const options = state.get.mock.calls[0]?.[2] as {
      onStart: () => void;
      onFinish: () => void;
    };
    act(() => options.onStart());

    expect(screen.getByRole('status', { name: 'Loading patient results' })).toBeInTheDocument();
    expect(document.querySelector('table.cds--skeleton')).not.toBeNull();
    expect(screen.queryByText('No patients have been registered yet.')).not.toBeInTheDocument();

    act(() => options.onFinish());
  });

  it('keeps existing rows visible during refresh with controls disabled', () => {
    render(<PatientSearchBrowse {...baseProps} />);
    fireEvent.submit(screen.getByRole('form', { name: 'Patient search' }));

    const options = state.get.mock.calls[0]?.[2] as {
      onStart: () => void;
      onFinish: () => void;
    };
    act(() => options.onStart());

    // Stale rows stay mounted with no content swap; the global progress
    // bar is the single pending indicator, the table signals busy.
    expect(screen.getByRole('table')).toBeInTheDocument();
    expect(screen.getByText('Amina Hassan')).toBeInTheDocument();
    expect(screen.queryByText('Refreshing results')).not.toBeInTheDocument();
    expect(screen.getByRole('table')).toHaveAttribute('aria-busy', 'true');
    expect(screen.queryByRole('status', { name: 'Loading patient results' })).not.toBeInTheDocument();

    // Relevant controls are disabled while pending.
    expect(screen.getByRole('button', { name: 'Search' })).toBeDisabled();
    expect(screen.getByRole('searchbox', { name: 'Search patients' })).toBeDisabled();
    expect(screen.getByRole('button', { name: /next page/i })).toBeDisabled();

    act(() => options.onFinish());
    expect(screen.getByRole('table')).not.toHaveAttribute('aria-busy');
    expect(screen.getByRole('table')).toBeInTheDocument();
  });

  it('reports a failed request as an error, distinct from empty', () => {
    render(<PatientSearchBrowse {...baseProps} />);
    fireEvent.submit(screen.getByRole('form', { name: 'Patient search' }));

    const options = state.get.mock.calls[0]?.[2] as {
      onNetworkError: () => void;
      onFinish: () => void;
    };
    act(() => options.onNetworkError());
    act(() => options.onFinish());

    expect(screen.getByRole('alert')).toHaveTextContent('Could not reach the server.');
    expect(screen.queryByRole('table')).not.toBeInTheDocument();
    expect(screen.queryByText('No patients found')).not.toBeInTheDocument();
  });
});

import { render, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import PatientsIndex from '@/pages/Patients/Index';

const state = vi.hoisted(() => ({
  canReadPatients: true,
}));

vi.mock('@/lib/authorization', () => ({
  permissions: { patient: { read: 'patient.patients.read' } },
  useCan: () => () => state.canReadPatients,
}));

vi.mock('@/layouts/AppLayout', () => ({
  AppLayout: ({ children }: { children: ReactNode }) => <>{children}</>,
  Breadcrumbs: () => null,
}));

vi.mock('@/features/patients/components', () => ({
  PatientSearchBrowse: () => <div role="searchbox">Patient search</div>,
}));

const pageProps = {
  q: '',
  mode: 'browse' as const,
  patients: [],
  total: 0,
  currentPage: 1,
  lastPage: 1,
  perPage: 15,
  searchError: null,
};

beforeEach(() => {
  state.canReadPatients = true;
});

describe('Patients page', () => {
  it('renders patient discovery when the read permission is granted', () => {
    render(<PatientsIndex {...pageProps} />);

    expect(screen.getByRole('searchbox')).toHaveTextContent('Patient search');
  });

  it('hides patient discovery when the read permission is missing', () => {
    state.canReadPatients = false;
    render(<PatientsIndex {...pageProps} />);

    expect(screen.getByRole('alert')).toHaveTextContent('You do not have permission to view patient records.');
    expect(screen.queryByRole('searchbox')).not.toBeInTheDocument();
  });
});

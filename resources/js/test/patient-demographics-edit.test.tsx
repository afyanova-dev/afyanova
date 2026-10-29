import { fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { EditDemographicsDialog } from '@/features/patients/forms/EditDemographicsDialog';
import type { PatientChartPatient } from '@/features/patients/types/chart';

const state = vi.hoisted(() => ({
  canUpdate: true,
  patch: vi.fn(),
  processing: false,
  errors: {} as Record<string, string>,
  transformCallback: null as ((data: Record<string, unknown>) => unknown) | null,
}));

vi.mock('@inertiajs/react', async () => {
  const { useState } = await import('react');

  return {
    useForm: (initial: Record<string, unknown>) => {
      const [data, setState] = useState(initial);
      return {
        data,
        setData: (key: string, value: unknown) => {
          setState((current) => ({ ...current, [key]: value }));
        },
        patch: state.patch,
        processing: state.processing,
        errors: state.errors,
        reset: vi.fn(),
        transform: (callback: (data: Record<string, unknown>) => unknown) => {
          state.transformCallback = callback;
        },
      };
    },
  };
});

vi.mock('@/lib/authorization', () => ({
  permissions: { patient: { update: 'patient.patients.update' } },
  useCan: () => () => state.canUpdate,
}));

const patient: PatientChartPatient = {
  patientId: 'patient-1',
  patientNumber: 'PAT-00000001',
  displayName: 'Amina Juma',
  givenName: 'Amina',
  familyName: 'Juma',
  middleName: null,
  dateOfBirth: '1990-02-03',
  dobPrecision: 'KNOWN',
  sex: 'FEMALE',
  status: 'ACTIVE',
  isProvisional: false,
  primaryPhone: '+255700000000',
  email: null,
  registrationSource: 'RECEPTION',
  registeredAt: null,
  hasAccessibleFacilityRegistration: true,
  facilityRegistrationCount: 1,
  survivingPatientId: null,
  identifiers: [],
  addresses: [],
  relationships: [],
  auditHistory: [],
};

function openDialog(): void {
  fireEvent.click(screen.getByRole('button', { name: 'Edit demographics' }));
}

beforeEach(() => {
  vi.clearAllMocks();
  globalThis.ResizeObserver ??= class {
    observe(): void {}
    unobserve(): void {}
    disconnect(): void {}
  };
  state.canUpdate = true;
  state.processing = false;
  state.errors = {};
  state.transformCallback = null;
});

describe('demographics edit dialog', () => {
  it('opens with current patient values', () => {
    render(<EditDemographicsDialog patient={patient} />);
    openDialog();

    expect(screen.getByLabelText('Given name')).toHaveValue('Amina');
    expect(screen.getByLabelText('Family name')).toHaveValue('Juma');
    expect(screen.getByLabelText('Sex')).toHaveValue('FEMALE');
    expect(screen.getByLabelText('Date of birth')).toHaveValue('1990-02-03');
    expect(screen.getByLabelText('Phone')).toHaveValue('+255700000000');
  });

  it('hides the edit action without the update permission', () => {
    state.canUpdate = false;
    render(<EditDemographicsDialog patient={patient} />);

    expect(screen.queryByRole('button', { name: 'Edit demographics' })).not.toBeInTheDocument();
  });

  it('reveals reason on sensitive change, not on phone-only change', () => {
    render(<EditDemographicsDialog patient={patient} />);
    openDialog();
    expect(screen.queryByLabelText('Reason for change')).not.toBeInTheDocument();

    fireEvent.change(screen.getByLabelText('Phone'), { target: { value: '+255711111111' } });
    expect(screen.queryByLabelText('Reason for change')).not.toBeInTheDocument();

    fireEvent.change(screen.getByLabelText('Given name'), { target: { value: 'Aminata' } });
    expect(screen.getByLabelText('Reason for change')).toBeInTheDocument();
  });

  it('locks the date input unless precision is known', () => {
    render(
      <EditDemographicsDialog
        patient={{ ...patient, dobPrecision: 'ESTIMATED', dateOfBirth: '1992-05-28' }}
      />,
    );
    openDialog();

    expect(screen.getByLabelText('Date of birth')).toHaveAttribute('readonly');
    expect(screen.getByText(/Only exact dates of birth can be changed here/)).toBeInTheDocument();

    fireEvent.change(screen.getByLabelText('Date of birth precision'), { target: { value: 'KNOWN' } });
    expect(screen.getByLabelText('Date of birth')).not.toHaveAttribute('readonly');
    expect(screen.queryByText(/Only exact dates of birth can be changed here/)).not.toBeInTheDocument();
  });

  it('PATCHes only dirty fields plus reason when sensitive', () => {
    render(<EditDemographicsDialog patient={patient} />);
    openDialog();

    fireEvent.change(screen.getByLabelText('Phone'), { target: { value: '+255711111111' } });
    fireEvent.change(screen.getByLabelText('Given name'), { target: { value: 'Aminata' } });
    fireEvent.change(screen.getByLabelText('Reason for change'), { target: { value: 'Spelling correction' } });
    fireEvent.click(screen.getByRole('button', { name: 'Save changes' }));

    expect(state.patch).toHaveBeenCalledWith('/workspaces/patient/patient-1');
    const payload = state.transformCallback?.({}) as Record<string, unknown>;
    expect(payload).toEqual({
      primary_phone: '+255711111111',
      given_name: 'Aminata',
      reason: 'Spelling correction',
    });
  });

  it('submits an empty payload when nothing changed', () => {
    render(<EditDemographicsDialog patient={patient} />);
    openDialog();
    fireEvent.click(screen.getByRole('button', { name: 'Save changes' }));

    expect(state.patch).toHaveBeenCalledWith('/workspaces/patient/patient-1');
    expect(state.transformCallback?.({})).toEqual({});
  });

  it('displays validation errors inline and form-level', () => {
    state.errors = { given_name: 'Required.', error: 'Merged record is immutable (ADR-128).' };
    render(<EditDemographicsDialog patient={patient} />);
    openDialog();

    expect(screen.getByLabelText('Given name')).toHaveAttribute('aria-invalid', 'true');
    expect(screen.getByText('Required.')).toBeInTheDocument();
    expect(screen.getByText('Merged record is immutable (ADR-128).')).toBeInTheDocument();
  });

  it('disables saving while processing', () => {
    state.processing = true;
    render(<EditDemographicsDialog patient={patient} />);
    openDialog();

    expect(screen.getByRole('button', { name: 'Saving…' })).toBeDisabled();
  });

  it('reports dirty state so parents can guard workspace switches', () => {
    const onDirtyChange = vi.fn();
    render(<EditDemographicsDialog patient={patient} onDirtyChange={onDirtyChange} />);
    openDialog();

    expect(onDirtyChange).toHaveBeenLastCalledWith(false);

    fireEvent.change(screen.getByLabelText('Phone'), { target: { value: '+255711111111' } });
    expect(onDirtyChange).toHaveBeenLastCalledWith(true);
  });
});

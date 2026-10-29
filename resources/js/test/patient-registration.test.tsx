import { act, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { RegisterPatientForm } from '@/features/patients/forms/RegisterPatientForm';
import PatientRegister from '@/pages/Patients/Register';

const state = vi.hoisted(() => ({
  formPost: vi.fn(),
  formPostOptions: null as Record<string, unknown> | null,
  routerVisit: vi.fn(),
  draftSave: vi.fn(),
  canCreate: true,
  canOverrideDuplicate: false,
  canManageIdentifiers: false,
  activeFacility: 'facility-1' as string | null,
  processing: false,
  errors: {} as Record<string, string>,
  flash: {} as Record<string, unknown>,
}));

vi.mock('@inertiajs/react', async () => {
  const { useState } = await import('react');

  return {
    Link: ({ children }: { children: React.ReactNode }) => <>{children}</>,
    router: { visit: state.routerVisit },
    usePage: () => ({
      props: {
        auth: {
          activeFacility: state.activeFacility,
          user: null,
          roles: [],
          can: {},
          facilities: [],
          contextMode: 'facility',
          canUseGeneral: false,
        },
        flash: state.flash,
      },
    }),
    useForm: (initial: Record<string, unknown>) => {
      const [data, setState] = useState(initial);

      return {
        data,
        setData: (key: string, value: unknown) => {
          setState((current) => ({ ...current, [key]: value }));
        },
        post: (url: string, options: Record<string, (() => void) | boolean>) => {
          state.formPost(url, data);
          state.formPostOptions = options;
        },
        processing: state.processing,
        errors: state.errors,
      };
    },
  };
});

vi.mock('@/lib/http/json', () => ({
  sendJson: state.draftSave,
}));

vi.mock('@/lib/authorization', () => ({
  permissions: {
    patient: {
      create: 'patient.patients.create',
      overrideDuplicate: 'patient.patients.override_duplicate',
      identifiersManage: 'patient.identifiers.manage',
    },
  },
  useCan: () => (permission: string) => {
    if (permission === 'patient.patients.create') return state.canCreate;
    if (permission === 'patient.patients.override_duplicate') return state.canOverrideDuplicate;
    if (permission === 'patient.identifiers.manage') return state.canManageIdentifiers;
    return false;
  },
}));

vi.mock('@/layouts/AppLayout', () => ({
  AppLayout: ({ children }: { children: React.ReactNode }) => <>{children}</>,
  Breadcrumbs: () => null,
}));

beforeEach(() => {
  globalThis.ResizeObserver ??= class {
    observe(): void {}
    unobserve(): void {}
    disconnect(): void {}
  };
  vi.clearAllMocks();
  vi.useRealTimers();
  state.draftSave.mockResolvedValue({ savedAt: '2026-10-05T18:00:00Z', expiresAt: '2026-10-12T18:00:00Z' });
  state.canCreate = true;
  state.canOverrideDuplicate = false;
  state.canManageIdentifiers = false;
  state.activeFacility = 'facility-1';
  state.processing = false;
  state.errors = {};
  state.flash = {};
  state.formPostOptions = null;
});

describe('patient registration form', () => {
  it('disables native browser validation so the server is authoritative', () => {
    render(<RegisterPatientForm canOverrideDuplicate={false} canManageIdentifiers={false} canRecordAddress={false} />);

    // Native bubbles ("Please fill out this field") must never preempt
    // backend validation; `required` stays for assistive tech only.
    expect(screen.getByRole('form', { name: 'Patient registration' })).toHaveAttribute('novalidate');
  });

  it('marks required registration fields as required', () => {
    render(<RegisterPatientForm canOverrideDuplicate={false} canManageIdentifiers={false} canRecordAddress={false} />);

    expect(screen.getByLabelText('First Name')).toBeRequired();
    expect(screen.getByLabelText('Family Name')).toBeRequired();
    expect(screen.getByRole('radio', { name: 'Male' })).toBeInTheDocument();
    expect(screen.getByRole('radio', { name: 'Female' })).toBeInTheDocument();
    expect(screen.getByRole('radio', { name: 'Unknown' })).toBeInTheDocument();
    expect(screen.getByRole('radio', { name: 'Male' })).toBeRequired();
    expect(screen.getByLabelText('Date of birth precision')).toBeRequired();
  });

  it('shows known date or estimated-age inputs according to precision', () => {
    const { container } = render(
      <RegisterPatientForm canOverrideDuplicate={false} canManageIdentifiers={false} canRecordAddress={false} />,
    );

    fireEvent.change(screen.getByLabelText('Date of birth precision'), { target: { value: 'KNOWN' } });
    expect(screen.getByLabelText('Date of birth')).toBeInTheDocument();
    expect(screen.queryByLabelText('Estimated years')).not.toBeInTheDocument();

    fireEvent.change(screen.getByLabelText('Date of birth precision'), { target: { value: 'ESTIMATED' } });
    expect(screen.queryByLabelText('Date of birth')).not.toBeInTheDocument();
    expect(screen.getByLabelText('Estimated years')).toBeInTheDocument();
    expect(screen.getByLabelText('Estimated months')).toBeInTheDocument();
    expect(screen.getByLabelText('Estimated days')).toBeInTheDocument();
    const estimatedDobRow = container.querySelector('.patient-register__estimated-dob');
    expect(estimatedDobRow).not.toBeNull();
    expect(estimatedDobRow).toContainElement(screen.getByLabelText('Estimated years'));
    expect(estimatedDobRow).toContainElement(screen.getByLabelText('Estimated months'));
    expect(estimatedDobRow).toContainElement(screen.getByLabelText('Estimated days'));
  });

  it('submits to the verified registration endpoint', () => {
    render(<RegisterPatientForm canOverrideDuplicate={false} canManageIdentifiers={false} canRecordAddress={false} />);
    fireEvent.submit(screen.getByRole('form', { name: 'Patient registration' }));

    expect(state.formPost).toHaveBeenCalledWith('/workspaces/patient/register', expect.objectContaining({
      given_name: '',
      family_name: '',
      sex: '',
      dob_precision: '',
      after_registration: 'PATIENT_LIST',
    }));
  });

  it('offers post-registration destinations and submits the selected one', () => {
    render(
      <RegisterPatientForm
        canOverrideDuplicate={false}
        canManageIdentifiers={false}
        canRecordAddress={false}
        defaultAfterRegistration="FRONT_DESK"
      />,
    );

    expect(screen.getByRole('radio', { name: 'Go to Front Desk to check in' })).toBeChecked();
    expect(screen.getByRole('radio', { name: 'Open patient chart' })).toBeInTheDocument();
    expect(screen.getByRole('radio', { name: 'Return to patient list' })).toBeInTheDocument();

    fireEvent.click(screen.getByRole('radio', { name: 'Open patient chart' }));
    fireEvent.submit(screen.getByRole('form', { name: 'Patient registration' }));

    expect(state.formPost).toHaveBeenCalledWith(
      '/workspaces/patient/register',
      expect.objectContaining({ after_registration: 'PATIENT_CHART' }),
    );
  });

  it('autosaves registration fields to the server-side draft endpoint', async () => {
    vi.useFakeTimers();
    render(<RegisterPatientForm canOverrideDuplicate={false} canManageIdentifiers={false} canRecordAddress={false} />);
    fireEvent.change(screen.getByLabelText('First Name'), { target: { value: 'Amina' } });
    fireEvent.change(screen.getByLabelText('Family Name'), { target: { value: 'Test' } });

    await act(async () => {
      await vi.advanceTimersByTimeAsync(700);
    });

    expect(state.draftSave).toHaveBeenCalledWith(
      '/workspaces/patient/register/draft',
      'POST',
      { values: expect.objectContaining({ given_name: 'Amina', family_name: 'Test' }) },
    );
    expect(state.draftSave.mock.calls[0]?.[2]).not.toHaveProperty('values.photo');
    expect(screen.getByRole('status')).toHaveTextContent('Draft saved securely');
  });

  it('does not save an untouched form as a draft', async () => {
    vi.useFakeTimers();
    render(
      <RegisterPatientForm
        canOverrideDuplicate={false}
        canManageIdentifiers={false}
        canRecordAddress={false}
        defaultAfterRegistration="FRONT_DESK"
      />,
    );

    await act(async () => {
      await vi.advanceTimersByTimeAsync(700);
    });

    expect(state.draftSave).not.toHaveBeenCalled();
  });

  it('replaces the submitted draft history entry and skips leave-save after successful registration', () => {
    const sendBeacon = vi.fn(() => true);
    Object.defineProperty(navigator, 'sendBeacon', { configurable: true, value: sendBeacon });
    render(<RegisterPatientForm canOverrideDuplicate={false} canManageIdentifiers={false} canRecordAddress={false} />);
    fireEvent.change(screen.getByLabelText('First Name'), { target: { value: 'Amina' } });
    fireEvent.change(screen.getByLabelText('Family Name'), { target: { value: 'Test' } });
    fireEvent.submit(screen.getByRole('form', { name: 'Patient registration' }));

    expect(state.formPostOptions).toMatchObject({ replace: true });
    const onSuccess = state.formPostOptions?.onSuccess;
    expect(onSuccess).toEqual(expect.any(Function));
    if (typeof onSuccess === 'function') act(() => onSuccess({ url: '/workspaces/patient' }));
    fireEvent(window, new Event('pagehide'));

    expect(sendBeacon).not.toHaveBeenCalled();
  });

  it('does not recreate the draft while discarding it', async () => {
    const sendBeacon = vi.fn(() => true);
    Object.defineProperty(navigator, 'sendBeacon', { configurable: true, value: sendBeacon });
    render(
      <RegisterPatientForm
        hasSavedDraft
        initialValues={{ given_name: 'Amina', family_name: 'Test' }}
        canOverrideDuplicate={false}
        canManageIdentifiers={false}
        canRecordAddress={false}
      />,
    );

    fireEvent.click(screen.getByRole('button', { name: 'Discard saved draft' }));
    await waitFor(() => {
      expect(state.draftSave).toHaveBeenCalledWith(
        '/workspaces/patient/register/draft',
        'DELETE',
      );
    });

    fireEvent(window, new Event('pagehide'));
    expect(state.routerVisit).toHaveBeenCalled();
    expect(sendBeacon).not.toHaveBeenCalled();
  });

  it('keeps the draft when the server returns a duplicate review in place', () => {
    const sendBeacon = vi.fn(() => true);
    Object.defineProperty(navigator, 'sendBeacon', { configurable: true, value: sendBeacon });
    render(<RegisterPatientForm canOverrideDuplicate={false} canManageIdentifiers={false} canRecordAddress={false} />);
    fireEvent.change(screen.getByLabelText('First Name'), { target: { value: 'Amina' } });
    fireEvent.change(screen.getByLabelText('Family Name'), { target: { value: 'Test' } });
    fireEvent.submit(screen.getByRole('form', { name: 'Patient registration' }));

    const onSuccess = state.formPostOptions?.onSuccess;
    const onFinish = state.formPostOptions?.onFinish;
    expect(onSuccess).toEqual(expect.any(Function));
    expect(onFinish).toEqual(expect.any(Function));
    if (typeof onSuccess === 'function') {
      act(() => onSuccess({ url: '/workspaces/patient/register' }));
    }
    if (typeof onFinish === 'function') act(() => onFinish());
    fireEvent(window, new Event('pagehide'));

    expect(sendBeacon).toHaveBeenCalled();
  });

  it('shows inline server validation messages', () => {
    state.errors = { given_name: 'The given name field is required.' };
    render(<RegisterPatientForm canOverrideDuplicate={false} canManageIdentifiers={false} canRecordAddress={false} />);

    expect(screen.getByLabelText('First Name')).toHaveAttribute('aria-invalid', 'true');
    expect(screen.getByText('The given name field is required.')).toBeInTheDocument();
  });

  it('disables submission while processing', () => {
    state.processing = true;
    render(<RegisterPatientForm canOverrideDuplicate={false} canManageIdentifiers={false} canRecordAddress={false} />);

    expect(screen.getByRole('button', { name: 'Registering…' })).toBeDisabled();
  });

  it('submits an uploaded photo as a File', () => {
    render(<RegisterPatientForm canOverrideDuplicate={false} canManageIdentifiers={false} canRecordAddress={false} />);
    const photo = new File(['photo'], 'patient.jpg', { type: 'image/jpeg' });
    const input = document.querySelector('input[type="file"]');
    expect(input).not.toBeNull();
    fireEvent.change(input as HTMLInputElement, { target: { files: [photo] } });
    fireEvent.submit(screen.getByRole('form', { name: 'Patient registration' }));

    expect(state.formPost).toHaveBeenCalledWith(
      '/workspaces/patient/register',
      expect.objectContaining({ photo }),
    );
  });

  it('places all registration fields and the photo in separate columns', () => {
    const { container } = render(
      <RegisterPatientForm canOverrideDuplicate={false} canManageIdentifiers={false} canRecordAddress={false} />,
    );
    const fields = container.querySelector('.patient-register__fields');
    const photo = container.querySelector('.patient-register__photo');

    expect(fields?.parentElement).toBe(photo?.parentElement);
    expect(fields).toContainElement(screen.getByLabelText('First Name'));
    expect(photo).toHaveTextContent('No image to display');
    expect(photo?.firstElementChild).toHaveTextContent('Photo');
    expect(photo?.firstElementChild).toHaveClass('cds--label');
  });

  it('shows duplicate review data and gates the override controls by permission', () => {
    const duplicateReview = {
      message: 'Potential duplicate patient detected.',
      confidenceBand: 'HIGH',
      outcome: 'REVIEW' as const,
      candidates: [{
        patientId: 'patient-1',
        patientNumber: 'P-1',
        displayName: 'Aisha Example',
        dateOfBirth: '1990-01-01',
      }],
    };
    const { rerender } = render(
      <RegisterPatientForm duplicateReview={duplicateReview} canOverrideDuplicate={false} canManageIdentifiers={false} canRecordAddress={false} />,
    );

    expect(screen.getByText('Aisha Example — P-1 — 1990-01-01')).toBeInTheDocument();
    expect(screen.queryByLabelText('Continue registration despite this review')).not.toBeInTheDocument();

    rerender(
      <RegisterPatientForm duplicateReview={duplicateReview} canOverrideDuplicate canManageIdentifiers={false} canRecordAddress={false} />,
    );
    fireEvent.click(screen.getByLabelText('Continue registration despite this review'));
    expect(screen.getByLabelText('Reason for override')).toBeRequired();
  });

  it('repopulates entered values when the backend echoes them after review', () => {
    render(
      <RegisterPatientForm
        canOverrideDuplicate={false}
        canManageIdentifiers={false}
        canRecordAddress={false}
        initialValues={{ given_name: 'Zuwena', family_name: 'Bakari', sex: 'UNKNOWN', _token: 'must-be-ignored' }}
      />,
    );

    expect(screen.getByLabelText('First Name')).toHaveValue('Zuwena');
    expect(screen.getByLabelText('Family Name')).toHaveValue('Bakari');
    expect(screen.getByRole('radio', { name: 'Unknown' })).toBeChecked();
  });

  it('does not render duplicate review data from the pending form-values flash', () => {
    state.flash = {
      pending_walkin_values: { given_name: 'Must not be restored' },
    };
    render(<PatientRegister duplicateReview={null} formValues={null} />);

    expect(screen.getByLabelText('First Name')).toHaveValue('');
    expect(screen.queryByText('Must not be restored')).not.toBeInTheDocument();
  });

  it('renders verified duplicate-review flash without using its draft values', () => {
    state.flash = {
      duplicate_review: {
        message: 'Review these possible matches.',
        confidenceBand: 'HIGH',
        outcome: 'REVIEW',
        candidates: [{
          patientId: 'patient-2',
          patientNumber: 'P-2',
          displayName: 'Musa Example',
          dateOfBirth: null,
        }],
        draft: { given_name: 'Must not be restored' },
      },
    };
    state.canOverrideDuplicate = true;
    render(<PatientRegister duplicateReview={null} formValues={null} />);

    expect(screen.getByText('Review these possible matches.')).toBeInTheDocument();
    expect(screen.getByText('Musa Example — P-2')).toBeInTheDocument();
    expect(screen.getByLabelText('Continue registration despite this review')).toBeInTheDocument();
    expect(screen.getByLabelText('First Name')).toHaveValue('');
    expect(screen.queryByText('Must not be restored')).not.toBeInTheDocument();
  });

  it('renders a plain form with photo box and no jump navigation', () => {
    render(<RegisterPatientForm canOverrideDuplicate={false} canManageIdentifiers={false} canRecordAddress={false} />);

    expect(screen.queryByRole('navigation', { name: 'Form sections' })).not.toBeInTheDocument();
    expect(screen.getByText('No image to display')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Register patient' })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Cancel' })).toBeInTheDocument();
  });

  it('records UNKNOWN names typed directly into the name fields', () => {
    render(<RegisterPatientForm canOverrideDuplicate={false} canManageIdentifiers={false} canRecordAddress={false} />);

    fireEvent.change(screen.getByLabelText('First Name'), { target: { value: 'UNKNOWN' } });
    fireEvent.change(screen.getByLabelText('Family Name'), { target: { value: 'UNKNOWN' } });
    fireEvent.submit(screen.getByRole('form', { name: 'Patient registration' }));

    expect(state.formPost).toHaveBeenCalledWith(
      '/workspaces/patient/register',
      expect.objectContaining({ given_name: 'UNKNOWN', family_name: 'UNKNOWN' }),
    );
  });

  it('does not promise identifier storage without the identifier permission', () => {
    render(<RegisterPatientForm canOverrideDuplicate={false} canManageIdentifiers={false} canRecordAddress={false} />);
    expect(screen.queryByText('Identifiers can be recorded with this registration.')).not.toBeInTheDocument();
    expect(screen.getByText('Identifiers need an additional permission; without it they will not be stored.')).toBeInTheDocument();

    render(<RegisterPatientForm canOverrideDuplicate={false} canManageIdentifiers canRecordAddress={false} />);
    expect(screen.getByText('Identifiers can be recorded with this registration.')).toBeInTheDocument();
  });
});

describe('patient registration page access', () => {
  it('requires the create permission', () => {
    state.canCreate = false;
    render(<PatientRegister duplicateReview={null} formValues={null} />);

    expect(screen.getByRole('alert')).toHaveTextContent('You do not have permission to register patients.');
    expect(screen.queryByRole('form', { name: 'Patient registration' })).not.toBeInTheDocument();
  });

  it('requires an active facility', () => {
    state.activeFacility = null;
    render(<PatientRegister duplicateReview={null} formValues={null} />);

    expect(screen.getByRole('alert')).toHaveTextContent('An active facility is required');
    expect(screen.queryByRole('form', { name: 'Patient registration' })).not.toBeInTheDocument();
  });
});

describe('registration address capture', () => {
  const addressProps = {
    canOverrideDuplicate: false,
    canManageIdentifiers: false,
    canRecordAddress: true,
  };

  it('renders the Tanzanian address fields as optional', () => {
    render(<RegisterPatientForm {...addressProps} />);

    for (const label of ['Region', 'District', 'Ward', 'Street or village', 'Postal code']) {
      const input = screen.getByLabelText(label);
      expect(input).toBeInTheDocument();
      expect(input).not.toBeRequired();
    }
    expect(screen.queryByText('Address details need an additional permission; without it they will not be stored.')).not.toBeInTheDocument();
  });

  it('warns when the address cannot be stored without permission', () => {
    render(<RegisterPatientForm canOverrideDuplicate={false} canManageIdentifiers={false} canRecordAddress={false} />);

    expect(screen.getByText('Address details need an additional permission; without it they will not be stored.')).toBeInTheDocument();
  });

  it('submits address values with the registration', () => {
    render(<RegisterPatientForm {...addressProps} />);
    fireEvent.change(screen.getByLabelText('District'), { target: { value: 'Kinondoni' } });
    fireEvent.change(screen.getByLabelText('Ward'), { target: { value: 'Mikocheni' } });
    fireEvent.submit(screen.getByRole('form', { name: 'Patient registration' }));

    expect(state.formPost).toHaveBeenCalledWith(
      '/workspaces/patient/register',
      expect.objectContaining({ district: 'Kinondoni', ward: 'Mikocheni', region: '' }),
    );
  });

  it('repopulates address values echoed after review', () => {
    render(<RegisterPatientForm {...addressProps} initialValues={{ district: 'Temeke' }} />);

    expect(screen.getByLabelText('District')).toHaveValue('Temeke');
    expect(screen.getByLabelText('Region')).toHaveValue('');
  });

  it('shows inline address validation messages', () => {
    state.errors = { district: 'The district is invalid.' };
    render(<RegisterPatientForm {...addressProps} />);

    expect(screen.getByLabelText('District')).toHaveAttribute('aria-invalid', 'true');
    expect(screen.getByText('The district is invalid.')).toBeInTheDocument();
  });
});

import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { BookAppointmentPanel } from './BookAppointmentPanel';

const state = vi.hoisted(() => ({
  getJson: vi.fn(),
  post: vi.fn(),
  patientSearchOptions: vi.fn(),
  noAvailableSlots: false,
  patient: {
    patientId: 'patient-1',
    patientNumber: 'PAT-1001',
    displayName: 'Jane Doe',
    sex: 'F',
    formattedDob: '10-Oct-1990',
    age: '35 yrs',
    photoUrl: null,
    activeEncounter: null,
  },
}));

vi.mock('@inertiajs/react', async () => {
  const React = await import('react');
  return {
    useForm: (initial: Record<string, string>) => {
      const [data, setState] = React.useState(initial);
      const setData = React.useCallback((
        keyOrUpdater: string | ((current: Record<string, string>) => Record<string, string>),
        value?: string,
      ) => {
        setState((current) => typeof keyOrUpdater === 'function'
          ? keyOrUpdater(current)
          : { ...current, [keyOrUpdater]: value ?? '' });
      }, []);

      return {
        data,
        setData,
        post: (url: string, options: unknown) => state.post(url, data, options),
        processing: false,
        errors: {},
      };
    },
  };
});

vi.mock('@/lib/http', () => ({ getJson: state.getJson }));
vi.mock('@/components/patient-search/usePatientSearch', () => ({
  usePatientSearch: (term: string, options: unknown) => {
    state.patientSearchOptions(options);
    return {
      rows: term.trim() ? [state.patient] : [],
      loading: false,
      errorMessage: null,
    };
  },
}));

beforeEach(() => {
  vi.clearAllMocks();
  state.noAvailableSlots = false;
  globalThis.ResizeObserver = class ResizeObserver {
    observe(): void {}
    unobserve(): void {}
    disconnect(): void {}
  };
  state.getJson.mockImplementation((url: string) => {
    if (url.includes('appointment-options')) {
      return Promise.resolve({
        types: [{ id: 'type-1', code: 'GEN', name: 'General', durationMinutes: 30 }],
        resources: [{ id: 'resource-1', name: 'Dr. Amina', resourceType: 'DOCTOR' }],
      });
    }

    return Promise.resolve({
      date: '2026-10-04',
      slots: state.noAvailableSlots ? [] : [{
        id: 'slot-1',
        resourceId: 'resource-1',
        startTime: '2026-10-04T09:00:00+03:00',
        endTime: '2026-10-04T09:30:00+03:00',
        capacity: 1,
        bookedCount: 0,
        status: 'OPEN',
      }],
    });
  });
});

describe('BookAppointmentPanel', () => {
  it('books the selected patient into an available backend slot', async () => {
    render(<BookAppointmentPanel month="2026-10" onClose={vi.fn()} />);

    expect(screen.getByRole('textbox', { name: 'Date' })).toHaveStyle({ width: '100%', maxWidth: '100%' });
    await screen.findByRole('option', { name: 'General · 30 min' });
    expect(state.patientSearchOptions).toHaveBeenCalledWith(expect.objectContaining({ fetchWhenEmpty: false }));
    expect(screen.queryByRole('button', { name: /Jane Doe/ })).not.toBeInTheDocument();
    fireEvent.change(screen.getByRole('textbox', { name: 'Find a patient' }), {
      target: { value: 'Jane' },
    });
    fireEvent.click(screen.getByRole('button', { name: /Jane Doe/ }));
    fireEvent.change(screen.getByRole('combobox', { name: 'Appointment type' }), {
      target: { value: 'type-1' },
    });
    fireEvent.change(screen.getByRole('combobox', { name: 'Resource' }), {
      target: { value: 'resource-1' },
    });

    const availableTime = await screen.findByRole('combobox', { name: 'Available slot (optional)' });
    await waitFor(() => expect(state.getJson).toHaveBeenCalledWith(expect.stringContaining('available-slots')));
    fireEvent.change(availableTime, { target: { value: 'slot-1' } });
    fireEvent.click(screen.getByRole('button', { name: 'Book appointment' }));

    await waitFor(() => {
      expect(state.post).toHaveBeenCalledWith(
        '/patient/patient-1/appointments',
        expect.objectContaining({
          appointment_type_id: 'type-1',
          resource_id: 'resource-1',
          slot_id: 'slot-1',
          scheduled_time: '09:00',
        }),
        expect.objectContaining({ preserveScroll: true }),
      );
    });
  });

  it('allows booking at a manually entered time when no slot is available', async () => {
    state.noAvailableSlots = true;
    render(<BookAppointmentPanel month="2026-10" onClose={vi.fn()} />);

    await screen.findByRole('option', { name: 'General · 30 min' });
    fireEvent.change(screen.getByRole('textbox', { name: 'Find a patient' }), {
      target: { value: 'Jane' },
    });
    fireEvent.click(screen.getByRole('button', { name: /Jane Doe/ }));
    fireEvent.change(screen.getByRole('combobox', { name: 'Appointment type' }), {
      target: { value: 'type-1' },
    });
    fireEvent.change(screen.getByRole('combobox', { name: 'Resource' }), {
      target: { value: 'resource-1' },
    });
    await screen.findByText('No available slots for this resource and date. You can still enter a time.');
    fireEvent.change(screen.getByLabelText('Time'), { target: { value: '10:30' } });
    fireEvent.click(screen.getByRole('button', { name: 'Book appointment' }));

    await waitFor(() => {
      expect(state.post).toHaveBeenCalledWith(
        '/patient/patient-1/appointments',
        expect.objectContaining({
          scheduled_time: '10:30',
          slot_id: '',
        }),
        expect.objectContaining({ preserveScroll: true }),
      );
    });
  });
});
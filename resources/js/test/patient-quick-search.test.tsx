import { act, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { PatientQuickSearch } from '@/components/navigation/PatientQuickSearch';

const state = vi.hoisted(() => ({
  canRead: true,
  getJson: vi.fn(),
  visit: vi.fn(),
}));

vi.mock('@inertiajs/react', () => ({
  router: { visit: state.visit },
}));

vi.mock('@/lib/http', () => ({
  getJson: state.getJson,
}));

vi.mock('@/lib/authorization', () => ({
  permissions: { patient: { read: 'patient.patients.read' } },
  useCan: () => () => state.canRead,
}));

const row = {
  patientId: 'patient-1',
  patientNumber: 'PAT-00000001',
  displayName: 'Amina Hassan',
  sex: 'FEMALE',
  formattedDob: '02-Jun-1994',
  age: '32 yrs, 4 mths',
  photoUrl: null,
  activeEncounter: null,
};

function searchResponse(patients: unknown[], extra: Record<string, unknown> = {}) {
  return { patients, total: patients.length, currentPage: 1, lastPage: 1, ...extra };
}

function type(term: string): void {
  fireEvent.change(screen.getByRole('combobox', { name: 'Search patients' }), {
    target: { value: term },
  });
}

function advanceDebounce(): void {
  act(() => {
    vi.advanceTimersByTime(300);
  });
}

async function settle(): Promise<void> {
  await act(async () => {});
}

beforeEach(() => {
  vi.clearAllMocks();
  vi.useFakeTimers();
  state.canRead = true;
  state.getJson.mockResolvedValue(searchResponse([]));
});

afterEach(() => {
  vi.useRealTimers();
});

describe('global patient quick search', () => {
  it('does not search below the 2-character minimum', async () => {
    render(<PatientQuickSearch />);
    type('A');
    advanceDebounce();
    await settle();

    const calls = state.getJson.mock.calls.map(([url]) => url as string);
    expect(calls.every((url) => !url.includes('q='))).toBe(true);
  });

  it('debounces keystrokes instead of searching per key', async () => {
    render(<PatientQuickSearch />);
    type('Am');
    advanceDebounce();
    await settle();
    state.getJson.mockClear();

    type('Ami');
    type('Amir');
    expect(state.getJson).not.toHaveBeenCalled();
    advanceDebounce();
    await settle();

    expect(state.getJson).toHaveBeenCalledOnce();
    expect(state.getJson).toHaveBeenCalledWith(expect.stringContaining('q=Amir'));
  });

  it('renders rows with chart links and encounter indicator', async () => {
    state.getJson.mockResolvedValue(
      searchResponse([{ ...row, activeEncounter: { encounterNumber: 'ENC-1' } }]),
    );
    render(<PatientQuickSearch />);
    type('Amina');
    advanceDebounce();
    await settle();

    expect(screen.getByRole('link', { name: /Amina Hassan/ })).toHaveAttribute(
      'href',
      '/patient/patient-1/chart',
    );
    expect(screen.getByText('Visiting')).toBeInTheDocument();
    expect(screen.getByText(/PAT-00000001/)).toBeInTheDocument();
  });

  it('moves with arrows and opens the chart on Enter', async () => {
    state.getJson.mockResolvedValue(
      searchResponse([row, { ...row, patientId: 'patient-2', displayName: 'Baraka Juma' }]),
    );
    render(<PatientQuickSearch />);
    const input = screen.getByRole('combobox', { name: 'Search patients' });
    type('Amina');
    advanceDebounce();
    await settle();
    screen.getByRole('option', { name: /Amina Hassan/ });

    fireEvent.keyDown(input, { key: 'ArrowDown' });
    fireEvent.keyDown(input, { key: 'ArrowDown' });
    fireEvent.keyDown(input, { key: 'Enter' });
    expect(state.visit).toHaveBeenCalledWith('/patient/patient-2/chart');
    // Selecting a patient resets the search (fresh header for next lookup).
    expect(input).toHaveValue('');

    // New search starts collapsed-focus fresh: arrows work from the top.
    type('Amina');
    advanceDebounce();
    await settle();
    screen.getByRole('option', { name: /Amina Hassan/ });

    fireEvent.keyDown(input, { key: 'ArrowDown' });
    fireEvent.keyDown(input, { key: 'Enter' });
    expect(state.visit).toHaveBeenCalledWith('/patient/patient-1/chart');
  });

  it('navigates to browse with ?q= on Enter without a selection', async () => {
    render(<PatientQuickSearch />);
    const input = screen.getByRole('combobox', { name: 'Search patients' });
    type('Amina');
    advanceDebounce();
    await settle();
    screen.getByText(/No patients found/);

    fireEvent.keyDown(input, { key: 'Enter' });
    expect(state.visit).toHaveBeenCalledWith('/workspaces/patient?q=Amina');
  });

  it('closes the dropdown on Escape', async () => {
    state.getJson.mockResolvedValue(searchResponse([row]));
    render(<PatientQuickSearch />);
    const input = screen.getByRole('combobox', { name: 'Search patients' });
    // Real .focus(): models genuinely-focused input (fireEvent.focus does
    // not move activeElement, which would make Carbon's post-clear refocus
    // reopen — impossible for a focused input in production).
    input.focus();
    type('Amina');
    advanceDebounce();
    await settle();
    screen.getByRole('option', { name: /Amina Hassan/ });

    fireEvent.keyDown(input, { key: 'Escape' });
    expect(screen.queryByRole('listbox')).not.toBeInTheDocument();
    expect(input).toHaveValue('');
  });

  it('opens the chart on mouse selection', async () => {
    state.getJson.mockResolvedValue(searchResponse([row]));
    render(<PatientQuickSearch />);
    type('Amina');
    advanceDebounce();
    await settle();
    fireEvent.click(screen.getByRole('link', { name: /Amina Hassan/ }));

    expect(state.visit).toHaveBeenCalledWith('/patient/patient-1/chart');
  });

  it('holds focus on mousedown so the option click cannot be swallowed', async () => {
    state.getJson.mockResolvedValue(searchResponse([row]));
    render(<PatientQuickSearch />);
    type('Amina');
    advanceDebounce();
    await settle();
    const link = screen.getByRole('link', { name: /Amina Hassan/ });

    // Real browsers shift focus on mousedown; preventing the default keeps
    // the input focused so no blur/rerender churn can detach the link before
    // click fires (fireEvent returns false when default is prevented).
    expect(fireEvent.mouseDown(link)).toBe(false);
    fireEvent.click(link);
    expect(state.visit).toHaveBeenCalledWith('/patient/patient-1/chart');
  });

  it('keeps stale rows mounted with a pending indicator during refetch', async () => {
    state.getJson.mockResolvedValue(searchResponse([row]));
    render(<PatientQuickSearch />);
    type('Amina');
    advanceDebounce();
    await settle();
    screen.getByRole('option', { name: /Amina Hassan/ });

    // Next keystroke with the response still in flight: the previous rows
    // must stay mounted and clickable instead of swapping for a spinner.
    state.getJson.mockReturnValueOnce(new Promise(() => {}));
    type('Aminax');
    advanceDebounce();
    await settle();

    expect(screen.getByRole('option', { name: /Amina Hassan/ })).toBeInTheDocument();
    expect(screen.getByText('Searching patients')).toBeInTheDocument();
    fireEvent.click(screen.getByRole('link', { name: /Amina Hassan/ }));
    expect(state.visit).toHaveBeenCalledWith('/patient/patient-1/chart');
  });

  it('shows empty and error states distinctly', async () => {
    render(<PatientQuickSearch />);
    type('Zzz');
    advanceDebounce();
    await settle();
    screen.getByText(/No patients found for/);

    state.getJson.mockRejectedValueOnce(new Error('offline'));
    type('Zzzx');
    advanceDebounce();
    await settle();
    screen.getByRole('alert');
  });

  it('hides entirely without the read permission', () => {
    state.canRead = false;
    render(<PatientQuickSearch />);

    expect(screen.queryByRole('combobox', { name: 'Search patients' })).not.toBeInTheDocument();
    expect(state.getJson).not.toHaveBeenCalled();
  });
});

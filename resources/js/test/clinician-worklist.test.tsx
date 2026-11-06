import { fireEvent, render, screen, within } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { routes } from '@/app/routes';
import ClinicianIndex from '@/pages/Clinician/Index';
import type { ClinicianEntry } from '@/features/clinician/types';

vi.mock('@inertiajs/react', () => ({
  Link: ({ href, children, ...rest }: { href: string; children: React.ReactNode }) => (
    <a href={href} {...rest}>{children}</a>
  ),
}));

vi.mock('@/layouts/AppLayout', () => ({
  AppLayout: ({ children }: { children: React.ReactNode }) => <>{children}</>,
}));

const entries: ClinicianEntry[] = [
  {
    id: 'ticket-routine',
    patientId: 'patient-routine',
    ticketNumber: 'A-010',
    patientName: 'Amina Routine',
    patientNumber: 'P-100',
    queue: 'General OPD Consultation',
    priority: 'ROUTINE',
    status: 'WAITING',
    waitMinutes: 15,
  },
  {
    id: 'ticket-emergency',
    patientId: 'patient-emergency',
    ticketNumber: 'A-011',
    patientName: 'Zawadi Emergency',
    patientNumber: 'P-101',
    queue: 'General OPD Consultation',
    priority: 'EMERGENCY',
    status: 'WAITING',
    waitMinutes: 3,
  },
  {
    id: 'ticket-urgent',
    patientId: 'patient-urgent',
    ticketNumber: 'A-012',
    patientName: 'Baraka Urgent',
    patientNumber: 'P-102',
    queue: 'General OPD Consultation',
    priority: 'URGENT',
    status: 'WAITING',
    waitMinutes: 40,
  },
];

function queueHeadingOrder(): string[] {
  return screen.getAllByRole('heading', { level: 2 }).map((heading) => heading.textContent ?? '');
}

describe('clinician worklist', function () {
  it('sorts by acuity by default and exposes search and filters', function () {
    render(<ClinicianIndex entries={entries} />);

    expect(queueHeadingOrder()).toEqual([
      'Zawadi Emergency',
      'Baraka Urgent',
      'Amina Routine',
    ]);
    expect(screen.getByRole('searchbox', { name: /search consultation queue/i })).toBeInTheDocument();
    expect(screen.getByLabelText('Priority')).toBeInTheDocument();
    expect(screen.getByLabelText('Sort')).toBeInTheDocument();
    expect(screen.getByText('3 in queue')).toBeInTheDocument();
  });

  it('links each queue patient to the chart while preserving encounter entry', function () {
    render(<ClinicianIndex entries={entries} />);

    const emergencyCard = screen.getByRole('heading', { name: 'Zawadi Emergency' }).closest('.clinician-queue-card');
    expect(emergencyCard).not.toBeNull();
    expect(within(emergencyCard as HTMLElement).getByRole('link', { name: 'Open patient chart' }))
      .toHaveAttribute('href', routes.patient.chart('patient-emergency'));
    expect(within(emergencyCard as HTMLElement).getByRole('link', { name: 'Open encounter' }))
      .toHaveAttribute('href', routes.workspaces.clinicianEntry('ticket-emergency'));
  });

  it('filters by patient search and by priority', function () {
    render(<ClinicianIndex entries={entries} />);

    fireEvent.change(screen.getByRole('searchbox', { name: /search consultation queue/i }), {
      target: { value: 'Baraka' },
    });
    expect(queueHeadingOrder()).toEqual(['Baraka Urgent']);
    expect(screen.getByText('1 of 3')).toBeInTheDocument();

    fireEvent.change(screen.getByLabelText('Priority'), {
      target: { value: 'EMERGENCY' },
    });
    expect(screen.getByText('No matching patients')).toBeInTheDocument();

    fireEvent.change(screen.getByRole('searchbox', { name: /search consultation queue/i }), {
      target: { value: '' },
    });
    expect(queueHeadingOrder()).toEqual(['Zawadi Emergency']);
  });

  it('sorts by longest wait and shows a no-match empty state', function () {
    render(<ClinicianIndex entries={entries} />);

    fireEvent.change(screen.getByLabelText('Sort'), {
      target: { value: 'LONGEST_WAIT' },
    });
    expect(queueHeadingOrder()).toEqual([
      'Baraka Urgent',
      'Amina Routine',
      'Zawadi Emergency',
    ]);

    fireEvent.change(screen.getByRole('searchbox', { name: /search consultation queue/i }), {
      target: { value: 'nobody-matches' },
    });
    const empty = screen.getByText('No matching patients').closest('div');
    expect(empty).not.toBeNull();
    expect(within(empty as HTMLElement).getByText(/Adjust the search/i)).toBeInTheDocument();
    expect(within(empty as HTMLElement).getByRole('button', { name: 'Reset filters' })).toBeInTheDocument();
  });

  it('displays active filter pills and resets filters from active filter bar and empty state', function () {
    render(<ClinicianIndex entries={entries} />);

    expect(screen.queryByLabelText('Active queue filters')).not.toBeInTheDocument();

    fireEvent.change(screen.getByRole('searchbox', { name: /search consultation queue/i }), {
      target: { value: 'Baraka' },
    });
    expect(screen.getByLabelText('Active queue filters')).toBeInTheDocument();
    expect(screen.getByText('Search: "Baraka"')).toBeInTheDocument();

    fireEvent.change(screen.getByLabelText('Priority'), {
      target: { value: 'URGENT' },
    });
    expect(screen.getByText('Priority: Urgent')).toBeInTheDocument();

    const clearSearchBtn = screen.getByRole('button', { name: 'Clear search filter' });
    fireEvent.click(clearSearchBtn);
    expect(screen.queryByText('Search: "Baraka"')).not.toBeInTheDocument();
    expect(screen.getByText('Priority: Urgent')).toBeInTheDocument();
    expect(queueHeadingOrder()).toEqual(['Baraka Urgent']);

    fireEvent.change(screen.getByRole('searchbox', { name: /search consultation queue/i }), {
      target: { value: 'nobody-matches' },
    });
    expect(screen.getByText('No matching patients')).toBeInTheDocument();

    const resetEmptyBtn = within(screen.getByText('No matching patients').closest('.afy-card') as HTMLElement)
      .getByRole('button', { name: 'Reset filters' });
    fireEvent.click(resetEmptyBtn);

    expect(queueHeadingOrder()).toEqual([
      'Zawadi Emergency',
      'Baraka Urgent',
      'Amina Routine',
    ]);
    expect(screen.queryByLabelText('Active queue filters')).not.toBeInTheDocument();
  });
});

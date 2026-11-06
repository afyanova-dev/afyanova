import { fireEvent, render, screen, within } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { routes } from '@/app/routes';
import NurseStationIndex from '@/pages/NurseStation/Index';
import type { NurseStationEntry } from '@/features/nurse-station/types';

vi.mock('@inertiajs/react', () => ({
  Link: ({ href, children, ...rest }: { href: string; children: React.ReactNode }) => (
    <a href={href} {...rest}>{children}</a>
  ),
}));

vi.mock('@/layouts/AppLayout', () => ({
  AppLayout: ({ children }: { children: React.ReactNode }) => <>{children}</>,
}));

const entries: NurseStationEntry[] = [
  {
    id: 'triage-ticket-1',
    patientId: 'patient-1',
    ticketNumber: 'T-001',
    patientName: 'Amina Juma',
    patientNumber: 'PAT-001',
    queue: 'OPD Triage',
    priority: 'ROUTINE',
    status: 'WAITING',
    waitMinutes: 12,
  },
  {
    id: 'triage-ticket-2',
    patientId: 'patient-2',
    ticketNumber: 'T-002',
    patientName: 'Zawadi Emergency',
    patientNumber: 'PAT-002',
    queue: 'OPD Triage',
    priority: 'EMERGENCY',
    status: 'WAITING',
    waitMinutes: 3,
  },
  {
    id: 'triage-ticket-3',
    patientId: 'patient-3',
    ticketNumber: 'T-003',
    patientName: 'Baraka Urgent',
    patientNumber: 'PAT-003',
    queue: 'OPD Triage',
    priority: 'URGENT',
    status: 'WAITING',
    waitMinutes: 40,
  },
];

function queueHeadingOrder(): string[] {
  return screen.getAllByRole('heading', { level: 2 }).map((heading) => heading.textContent ?? '');
}

describe('nurse station worklist', () => {
  it('links the patient to the chart and keeps the assessment entry available', () => {
    render(<NurseStationIndex entries={entries} />);

    const patientCard = screen.getByRole('heading', { name: 'Amina Juma' }).closest('.clinician-queue-card');
    expect(patientCard).not.toBeNull();
    expect(within(patientCard as HTMLElement).getByRole('link', { name: 'Open patient chart' }))
      .toHaveAttribute('href', routes.patient.chart('patient-1'));
    expect(within(patientCard as HTMLElement).getByRole('link', { name: 'Review queue ticket' }))
      .toHaveAttribute('href', routes.workspaces.nurseStationEntry('triage-ticket-1'));
  });

  it('uses acuity sorting and filters by patient or priority', () => {
    render(<NurseStationIndex entries={entries} />);

    expect(queueHeadingOrder()).toEqual([
      'Zawadi Emergency',
      'Baraka Urgent',
      'Amina Juma',
    ]);
    expect(screen.getByRole('searchbox', { name: /search nursing queue/i })).toBeInTheDocument();
    expect(screen.getByLabelText('Priority')).toBeInTheDocument();
    expect(screen.getByLabelText('Sort')).toBeInTheDocument();

    fireEvent.change(screen.getByRole('searchbox', { name: /search nursing queue/i }), {
      target: { value: 'Baraka' },
    });
    expect(queueHeadingOrder()).toEqual(['Baraka Urgent']);
    expect(screen.getByText('1 of 3')).toBeInTheDocument();

    fireEvent.change(screen.getByLabelText('Priority'), {
      target: { value: 'EMERGENCY' },
    });
    expect(screen.getByText('No matching patients')).toBeInTheDocument();
  });

  it('sorts by longest wait and restores the queue after resetting an empty result', () => {
    render(<NurseStationIndex entries={entries} />);

    fireEvent.change(screen.getByLabelText('Sort'), {
      target: { value: 'LONGEST_WAIT' },
    });
    expect(queueHeadingOrder()).toEqual([
      'Baraka Urgent',
      'Amina Juma',
      'Zawadi Emergency',
    ]);

    fireEvent.change(screen.getByRole('searchbox', { name: /search nursing queue/i }), {
      target: { value: 'no-match' },
    });
    expect(screen.getByText('No matching patients')).toBeInTheDocument();
    const emptyState = screen.getByText('No matching patients').closest('.afy-card');
    expect(emptyState).not.toBeNull();
    fireEvent.click(within(emptyState as HTMLElement).getByRole('button', { name: 'Reset filters' }));

    expect(queueHeadingOrder()).toEqual([
      'Zawadi Emergency',
      'Baraka Urgent',
      'Amina Juma',
    ]);
  });
});

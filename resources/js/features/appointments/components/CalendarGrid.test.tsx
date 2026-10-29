import { render, screen, within } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { AppointmentDayAgenda } from './AppointmentDayAgenda';
import { CalendarFilterBar } from './CalendarFilterBar';
import { CalendarGrid } from './CalendarGrid';

vi.mock('@/lib/http', () => ({
  getJson: vi.fn(() => Promise.resolve({ types: [], resources: [] })),
}));

vi.mock('@/lib/authorization', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/lib/authorization')>();
  return { ...actual, useCan: () => () => false };
});

function octoberDays() {
  const start = new Date(Date.UTC(2026, 8, 27));

  return Array.from({ length: 42 }, (_, index) => {
    const date = new Date(start);
    date.setUTCDate(start.getUTCDate() + index);
    const dateString = date.toISOString().slice(0, 10);

    return {
      date: dateString,
      total: dateString === '2026-10-03' ? 4 : 0,
      services: dateString === '2026-10-03'
        ? [{ type: 'General', count: 3 }, { type: 'Follow-up', count: 1 }]
        : [],
    };
  });
}

beforeEach(() => {
  globalThis.ResizeObserver = class ResizeObserver {
    observe(): void {}
    unobserve(): void {}
    disconnect(): void {}
  };
});

describe('Appointments CalendarGrid', () => {
  it('shows the selected month heading and human-readable day cells without a duplicate header badge', () => {
    render(<CalendarGrid month="2026-10" gridStart="2026-09-27" days={octoberDays()} />);

    expect(screen.getByRole('heading', { name: 'October 2026' })).toBeInTheDocument();
    // Month badge now lives in the page header (PageHeader badge), not the calendar toolbar.
    expect(screen.queryByText('4 appointments this month')).not.toBeInTheDocument();

    const day = screen.getByRole('listitem', {
      name: /Saturday, October 3, 2026: 4 appointments; General: 3, Follow-up: 1/,
    });
    const dayLink = within(day).getByRole('link', { name: /View appointments for Saturday, October 3, 2026/ });
    // Day number is human-readable, machine date stays in dateTime.
    expect(dayLink.querySelector('time')).toHaveAttribute('dateTime', '2026-10-03');
    expect(within(dayLink).getByText('General')).toBeInTheDocument();
    expect(within(dayLink).getByText('Follow-up')).toBeInTheDocument();
    expect(dayLink).toHaveAttribute(
      'href',
      '/workspaces/appointments/calendar?month=2026-10&day=2026-10-03&view=month',
    );
  });

  it('shows one navigable Sunday-first week with October 5 under Monday', () => {
    const { container } = render(
      <CalendarGrid
        month="2026-10"
        gridStart="2026-09-27"
        days={[{ date: '2026-10-05', total: 3, services: [{ type: 'General', count: 3 }] }]}
        view="week"
        weekIndex={1}
      />,
    );

    const grid = container.querySelector('.appointment-calendar__days');
    if (!grid) throw new Error('Calendar week grid was not rendered.');
    const cells = Array.from(grid.children);
    expect(cells).toHaveLength(7);
    expect(cells[1]).toHaveAttribute('aria-label', expect.stringContaining('October 5, 2026'));
    expect(cells[1]?.querySelector('time')).toHaveAttribute('dateTime', '2026-10-05');
    expect(within(cells[1] as HTMLElement).getByRole('link', { name: /View appointments for Monday, October 5, 2026/ })).toHaveAttribute(
      'href',
      '/workspaces/appointments/calendar?month=2026-10&day=2026-10-05&view=week&week=1',
    );
  });

  it('shows an empty week and surfaces calendar query failures', () => {
    const { container, rerender } = render(
      <CalendarGrid month="2026-10" gridStart="2026-09-27" days={[]} view="week" />,
    );
    expect(container.querySelectorAll('.appointment-calendar__day')).toHaveLength(7);
    // Period badge moved to the page header; grid toolbar no longer renders it.
    expect(screen.queryByText('0 appointments this week')).not.toBeInTheDocument();

    rerender(<CalendarGrid month="2026-10" gridStart="2026-09-27" days={[]} view="week" calendarError />);
    expect(screen.getByRole('alert')).toHaveTextContent('Calendar totals could not be loaded.');
  });
});

describe('CalendarFilterBar', () => {
  it('organizes views, date, and appointment filters in the main page toolbar', async () => {
    render(
      <CalendarFilterBar
        month="2026-10"
        days={octoberDays()}
        view="month"
        weekIndex={0}
        focusDate="2026-10-05"
        selectedDay="2026-10-05"
        appointmentTypeFilter={null}
      />,
    );

    expect(screen.getByRole('region', { name: 'Calendar filters' })).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Day' })).toHaveAttribute(
      'href',
      '/workspaces/appointments/calendar?month=2026-10&view=day&date=2026-10-05',
    );
    expect(screen.getByRole('link', { name: 'Week' })).toHaveAttribute(
      'href',
      '/workspaces/appointments/calendar?month=2026-10&view=week&week=0&date=2026-10-05',
    );
    expect(screen.getByRole('link', { name: 'Month' })).toHaveAttribute(
      'href',
      '/workspaces/appointments/calendar?month=2026-10&view=month&date=2026-10-05',
    );
    // Period navigation lives in the calendar header; the toolbar carries no pager.
    expect(screen.queryByRole('link', { name: /Previous month/ })).not.toBeInTheDocument();
    expect(screen.queryByRole('link', { name: /Next month/ })).not.toBeInTheDocument();
    // Type select arrives after the options fetch (Carbon skeleton first).
    expect(await screen.findByRole('combobox', { name: 'Appointment type' })).toBeInTheDocument();
  });
});

describe('AppointmentDayAgenda', () => {
  it('shows appointment context and links back to the patient chart', () => {
    render(
      <AppointmentDayAgenda
        date="2026-10-03"
        error={false}
        appointments={[{
          id: 'appointment-1',
          appointmentNumber: 'APT-001',
          scheduledStart: '2026-10-03T09:00:00+03:00',
          scheduledEnd: '2026-10-03T09:30:00+03:00',
          status: 'CONFIRMED',
          reason: 'Follow-up',
          patient: { patientId: 'patient-1', patientNumber: 'PAT-001', displayName: 'Jane Doe' },
          appointmentTypeName: 'Consultation',
          resourceName: 'Dr. Amina',
        }]}
      />,
    );

    expect(screen.getByRole('dialog', { name: /Saturday, October 3, 2026/ })).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Jane Doe' })).toHaveAttribute('href', '/patient/patient-1/chart');
    expect(screen.getByText(/PAT-001/)).toBeInTheDocument();
    expect(screen.getByText(/APT-001 · Consultation · Dr. Amina/)).toBeInTheDocument();
    expect(screen.getByText('CONFIRMED')).toBeInTheDocument();
    expect(screen.getByText(/Follow-up/)).toBeInTheDocument();
    // Photo comes from the verified patient photo route with initial fallback.
    expect(document.querySelector('.appointment-agenda__photo, .appointment-agenda__photo-fallback')).not.toBeNull();
  });

  it('groups multiple same-day visits under one patient header', () => {
    const patient = { patientId: 'patient-1', patientNumber: 'PAT-001', displayName: 'Jane Doe' };
    render(
      <AppointmentDayAgenda
        date="2026-10-03"
        error={false}
        appointments={[
          {
            id: 'appointment-1',
            appointmentNumber: 'APT-001',
            scheduledStart: '2026-10-03T09:00:00+03:00',
            scheduledEnd: '2026-10-03T09:30:00+03:00',
            status: 'CONFIRMED',
            reason: null,
            patient,
            appointmentTypeName: 'Consultation',
            resourceName: 'Dr. Amina',
          },
          {
            id: 'appointment-2',
            appointmentNumber: 'APT-002',
            scheduledStart: '2026-10-03T11:00:00+03:00',
            scheduledEnd: '2026-10-03T11:30:00+03:00',
            status: 'BOOKED',
            reason: null,
            patient,
            appointmentTypeName: 'Review',
            resourceName: 'Dr. Amina',
          },
        ]}
      />,
    );

    // One patient header, not two repeated identities.
    expect(screen.getAllByRole('link', { name: 'Jane Doe' })).toHaveLength(1);
    expect(screen.getByText(/1 patient/)).toBeInTheDocument();
    expect(screen.getByText(/PAT-001 · 2 visits/)).toBeInTheDocument();
    expect(screen.getByText(/APT-001 · Consultation/)).toBeInTheDocument();
    expect(screen.getByText(/APT-002 · Review/)).toBeInTheDocument();
  });

  it('shows empty and error states for the selected date', () => {
    const { rerender } = render(<AppointmentDayAgenda date="2026-10-03" appointments={[]} error={false} />);
    expect(screen.getByRole('button', { name: /Close/ })).toBeInTheDocument();
    expect(screen.getByRole('status')).toHaveTextContent('No appointments scheduled for this day.');

    rerender(<AppointmentDayAgenda date="2026-10-03" appointments={[]} error />);
    expect(screen.getByRole('alert')).toHaveTextContent('Appointments for this day could not be loaded.');
  });
});

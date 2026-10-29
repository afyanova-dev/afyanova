import type { ReactNode } from 'react';
import { usePage } from '@inertiajs/react';
import { Button, Tag } from '@carbon/react';
import { Add, Calendar } from '@carbon/icons-react';
import { useState } from 'react';
import { ErrorState, PageHeader, StatisticsCards } from '@/components/ui';
import { BookAppointmentPanel } from '@/features/appointments/components/BookAppointmentPanel';
import { AppointmentDayAgenda } from '@/features/appointments/components/AppointmentDayAgenda';
import { CalendarFilterBar } from '@/features/appointments/components/CalendarFilterBar';
import { AppLayout, Breadcrumbs } from '@/layouts/AppLayout';
import { CalendarGrid } from '@/features/appointments/components/CalendarGrid';
import type { AppointmentCalendarPageProps } from '@/features/appointments/types/calendar';
import { permissions, useCan } from '@/lib/authorization';
import type { SharedProps } from '@/types';

function localToday(): string {
  const now = new Date();
  return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`;
}

function formatShortDate(value: string): string {
  const date = new Date(`${value}T12:00:00`);
  if (Number.isNaN(date.getTime())) return value;
  return new Intl.DateTimeFormat(undefined, {
    weekday: 'short', month: 'short', day: 'numeric', year: 'numeric',
  }).format(date);
}

export default function AppointmentsCalendar({
  month,
  gridStart,
  days,
  calendarError,
  view,
  weekIndex,
  appointmentTypeFilter,
  focusDate,
  selectedDay,
  appointments,
  appointmentsError,
}: AppointmentCalendarPageProps): ReactNode {
  const can = useCan();
  const { props } = usePage<SharedProps>();
  const [bookingOpen, setBookingOpen] = useState(false);
  const canBook = can(permissions.scheduling.access)
    && can(permissions.scheduling.book)
    && can(permissions.patient.read);

  const today = localToday();
  const monthTotal = days
    .filter((day) => day.date.startsWith(`${month}-`))
    .reduce((total, day) => {
      if (!appointmentTypeFilter) return total + day.total;
      return total + day.services
        .filter((service) => service.type === appointmentTypeFilter)
        .reduce((serviceTotal, service) => serviceTotal + service.count, 0);
    }, 0);
  const todayTotal = days.find((day) => day.date === today)?.total ?? 0;
  const selectedTotal = selectedDay
    ? (days.find((day) => day.date === selectedDay)?.total ?? appointments.length)
    : null;
  const pageErrors = (props.errors ?? {}) as Record<string, string>;
  const actionError = pageErrors.reschedule ?? pageErrors.cancel ?? pageErrors.confirm ?? pageErrors.arrival ?? pageErrors.no_show ?? null;

  const canAccess = can(permissions.scheduling.access);

  return (
    <AppLayout>
      <div className="afyanova-page">
        <Breadcrumbs items={[{ label: 'Appointments' }, { label: 'Calendar' }]} />
        <PageHeader
          title="Appointments"
          description="Plan and review appointments by day, week, or month."
          badge={calendarError ? undefined : (
            <Tag type={monthTotal > 0 ? 'blue' : 'cool-gray'}>
              {`${monthTotal} ${monthTotal === 1 ? 'appointment' : 'appointments'} this month`}
            </Tag>
          )}
          actions={canBook ? (
            <Button kind="primary" size="lg" renderIcon={Add} onClick={() => setBookingOpen(true)}>
              Book appointment
            </Button>
          ) : undefined}
        />
        <div className="afy-page__content">
          {canAccess ? (
            <>
              <StatisticsCards
                label="Scheduling summary"
                cards={[
                  {
                    id: 'month',
                    label: 'This month',
                    value: calendarError ? '—' : monthTotal,
                    detail: appointmentTypeFilter ? `Filtered · ${appointmentTypeFilter}` : 'All types',
                  },
                  {
                    id: 'today',
                    label: 'Today',
                    value: calendarError ? '—' : todayTotal,
                    detail: formatShortDate(today),
                  },
                  {
                    id: 'selected-day',
                    label: selectedDay ? 'Selected day' : 'Day roster',
                    value: selectedTotal ?? '—',
                    detail: selectedDay ? formatShortDate(selectedDay) : 'Select a date to open its roster',
                  },
                ]}
              />
              <section className="appointment-workspace afy-card" aria-label="Appointment calendar workspace">
                <CalendarFilterBar
                  month={month}
                  days={days}
                  view={view}
                  weekIndex={weekIndex}
                  focusDate={focusDate}
                  selectedDay={selectedDay}
                  appointmentTypeFilter={appointmentTypeFilter}
                />
                <CalendarGrid
                  month={month}
                  gridStart={gridStart}
                  days={days}
                  calendarError={calendarError}
                  view={view}
                  weekIndex={weekIndex}
                  focusDate={focusDate}
                  selectedDay={selectedDay}
                  appointmentTypeFilter={appointmentTypeFilter}
                />
              </section>
            </>
          ) : (
            <ErrorState title="Appointment access is not available" message="You do not have permission to view appointment scheduling." />
          )}
          {canAccess && !selectedDay ? (
            <div className="appointment-calendar__cta" role="status">
              <Calendar size={20} aria-hidden="true" />
              <p>Select a date with appointments to see patient, time, type, resource, and status — then confirm, record arrival, reschedule, cancel, or mark no-show.</p>
            </div>
          ) : null}
        </div>
      </div>
      {canAccess && selectedDay ? (
        <AppointmentDayAgenda date={selectedDay} appointments={appointments} error={appointmentsError} actionError={actionError} />
      ) : null}
      {bookingOpen ? <BookAppointmentPanel month={month} onClose={() => setBookingOpen(false)} /> : null}
    </AppLayout>
  );
}

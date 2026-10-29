import type { ReactNode } from 'react';
import { Link } from '@inertiajs/react';
import { routes } from '@/app/routes';
import type { AppointmentCalendarDay } from '../types/calendar';

interface CalendarGridProps {
  month: string;
  gridStart: string;
  days: AppointmentCalendarDay[];
  calendarError?: boolean;
  view?: 'day' | 'week' | 'month';
  weekIndex?: number;
  focusDate?: string | null;
  selectedDay?: string | null;
  appointmentTypeFilter?: string | null;
}

const WEEKDAY_SHORT = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
const WEEKDAY_FULL = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

function parseMonth(value: string): Date {
  const match = /^(\d{4})-(\d{2})$/.exec(value);
  if (!match) return new Date();
  return new Date(Number(match[1]), Number(match[2]) - 1, 1);
}

function parseDay(value: string): Date {
  const date = new Date(`${value}T12:00:00`);
  return Number.isNaN(date.getTime()) ? new Date() : date;
}

function formatMonth(value: string): string {
  return new Intl.DateTimeFormat(undefined, { month: 'long', year: 'numeric' }).format(parseMonth(value));
}

function formatLongDay(value: string): string {
  return new Intl.DateTimeFormat(undefined, {
    weekday: 'long', month: 'long', day: 'numeric', year: 'numeric',
  }).format(parseDay(value));
}

function formatWeekRange(days: AppointmentCalendarDay[]): string {
  const first = days[0]?.date;
  const last = days[days.length - 1]?.date;
  if (!first || !last) return '';
  const fmt = (value: string, options: Intl.DateTimeFormatOptions) =>
    new Intl.DateTimeFormat(undefined, options).format(new Date(`${value}T12:00:00`));
  return `${fmt(first, { month: 'short', day: 'numeric' })} – ${fmt(last, { month: 'short', day: 'numeric', year: 'numeric' })}`;
}

function shiftMonth(value: string, amount: number): string {
  const [year, month] = value.split('-').map(Number);
  const date = new Date(year ?? new Date().getFullYear(), (month ?? 1) - 1 + amount, 1);
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}`;
}

function shiftDay(value: string, amount: number): string {
  const date = parseDay(value);
  date.setDate(date.getDate() + amount);
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
}

function localToday(): string {
  const now = new Date();
  return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`;
}

function dayHref(
  date: string,
  view: 'day' | 'week' | 'month',
  weekIndex: number,
  appointmentTypeFilter: string | null,
): string {
  const params = new URLSearchParams({ month: date.slice(0, 7), day: date });
  params.set('view', view);
  if (view === 'week') params.set('week', String(weekIndex));
  if (appointmentTypeFilter) params.set('type', appointmentTypeFilter);
  return `${routes.workspaces.appointmentCalendar}?${params.toString()}`;
}

function completeCalendarDays(gridStart: string, days: AppointmentCalendarDay[]): AppointmentCalendarDay[] {
  const daysByDate = new Map(days.map((day) => [day.date, day]));
  const start = new Date(`${gridStart}T00:00:00Z`);
  return Array.from({ length: 42 }, (_, index) => {
    const date = new Date(start);
    date.setUTCDate(start.getUTCDate() + index);
    const dateString = date.toISOString().slice(0, 10);
    return daysByDate.get(dateString) ?? { date: dateString, total: 0, services: [] };
  });
}

function countLabel(total: number): string {
  if (total === 0) return 'No appointments';
  return total === 1 ? '1 appointment' : `${total} appointments`;
}

export function CalendarGrid({
  month,
  gridStart,
  days,
  calendarError = false,
  view = 'month',
  weekIndex = 0,
  focusDate = null,
  selectedDay = null,
  appointmentTypeFilter = null,
}: CalendarGridProps): ReactNode {
  const allDays = completeCalendarDays(gridStart, days);
  const calendarDays = allDays.map((day) => {
    const services = appointmentTypeFilter
      ? day.services.filter((service) => service.type === appointmentTypeFilter)
      : day.services;
    return {
      ...day,
      services,
      total: appointmentTypeFilter
        ? services.reduce((total, service) => total + service.count, 0)
        : day.total,
    };
  });
  const today = localToday();
  const dayDate = focusDate ?? selectedDay ?? today;
  const periodDays = view === 'day'
    ? calendarDays.filter((day) => day.date === dayDate)
    : view === 'week'
      ? calendarDays.slice(weekIndex * 7, weekIndex * 7 + 7)
      : calendarDays;
  const periodLabel = view === 'day' ? formatLongDay(dayDate) : view === 'week' ? formatWeekRange(periodDays) : formatMonth(month);
  const maxTotal = Math.max(1, ...periodDays.map((day) => day.total));

  const baseParams = appointmentTypeFilter ? `&type=${encodeURIComponent(appointmentTypeFilter)}` : '';
  const prevHref = view === 'day'
    ? `${routes.workspaces.appointmentCalendar}?month=${shiftDay(dayDate, -1).slice(0, 7)}&view=day&date=${shiftDay(dayDate, -1)}${baseParams}`
    : view === 'week' && weekIndex > 0
      ? `${routes.workspaces.appointmentCalendar}?month=${month}&view=week&week=${weekIndex - 1}${baseParams}`
      : `${routes.workspaces.appointmentCalendar}?month=${shiftMonth(month, -1)}&view=month${baseParams}`;
  const nextHref = view === 'day'
    ? `${routes.workspaces.appointmentCalendar}?month=${shiftDay(dayDate, 1).slice(0, 7)}&view=day&date=${shiftDay(dayDate, 1)}${baseParams}`
    : view === 'week' && weekIndex < 5
      ? `${routes.workspaces.appointmentCalendar}?month=${month}&view=week&week=${weekIndex + 1}${baseParams}`
      : `${routes.workspaces.appointmentCalendar}?month=${shiftMonth(month, 1)}&view=month${baseParams}`;
  const todayHref = `${routes.workspaces.appointmentCalendar}?month=${today.slice(0, 7)}&view=${view}${view === 'week' ? `&week=${weekIndex}` : ''}&date=${today}${baseParams}`;

  return (
    <section className="appointment-calendar" aria-label="Appointment calendar">
      <header className="appointment-calendar__toolbar">
        <div className="appointment-calendar__title-block">
          <p className="appointment-calendar__eyebrow">
            {view === 'day' ? 'Day schedule' : view === 'week' ? 'Week workload' : 'Month workload'} · {appointmentTypeFilter ?? 'All types'}
          </p>
          <h2 className="appointment-calendar__month">{periodLabel}</h2>
          {appointmentTypeFilter ? (
            <div className="appointment-calendar__summary">
              <span className="appointment-calendar__filter-note">Filtered: {appointmentTypeFilter}</span>
            </div>
          ) : null}
        </div>
        <nav className="appointment-calendar__navigation" aria-label="Calendar period navigation">
          <Link className="appointment-calendar__link" href={prevHref}>← Prev</Link>
          <Link className="appointment-calendar__link" href={todayHref}>Today</Link>
          <Link className="appointment-calendar__link" href={nextHref}>Next →</Link>
        </nav>
      </header>

      {!calendarError ? (
        <>
          {view !== 'day' ? (
            <ol className="appointment-calendar__weekdays" aria-label="Days of week">
              {WEEKDAY_SHORT.map((short, index) => (
                <li key={short} aria-label={WEEKDAY_FULL[index]}>{short}</li>
              ))}
            </ol>
          ) : null}
            <ol
              className={`appointment-calendar__days appointment-calendar__days--${view}`}
              aria-label={`${periodLabel} appointment dates`}
            >
              {periodDays.map((day) => {
                const inMonth = day.date.startsWith(`${month}-`);
                const parsed = parseDay(day.date);
                const dayNumber = parsed.getDate();
                const isToday = day.date === today;
                const isSelected = day.date === (selectedDay ?? focusDate);
                const serviceSummary = day.services.map((service) => `${service.type}: ${service.count}`).join(', ');
                const density = Math.min(100, Math.round((day.total / maxTotal) * 100));
                const topServices = day.services.slice(0, 2);
                const overflow = day.services.length - topServices.length;

                return (
                  <li
                    className={[
                      'appointment-calendar__day',
                      inMonth ? '' : 'appointment-calendar__day--outside',
                      isToday ? 'appointment-calendar__day--today' : '',
                      isSelected ? 'appointment-calendar__day--selected' : '',
                      day.total === 0 ? 'appointment-calendar__day--empty' : '',
                    ].filter(Boolean).join(' ')}
                    key={day.date}
                    aria-label={`${formatLongDay(day.date)}: ${countLabel(day.total)}${serviceSummary ? `; ${serviceSummary}` : ''}`}
                  >
                    <Link
                      className="appointment-calendar__date-link"
                      href={dayHref(day.date, view, weekIndex, appointmentTypeFilter)}
                      aria-label={`View appointments for ${formatLongDay(day.date)}`}
                      aria-current={isSelected ? 'date' : undefined}
                    >
                      <span className="appointment-calendar__day-top">
                        {view === 'day' ? (
                          <span className="appointment-calendar__day-name" aria-hidden="true">
                            {WEEKDAY_FULL[parsed.getDay()]}
                          </span>
                        ) : null}
                        <time className="appointment-calendar__date" dateTime={day.date} aria-hidden="true">{dayNumber}</time>
                        {isToday ? <span className="appointment-calendar__today-dot" aria-hidden="true">Today</span> : null}
                      </span>
                      <span className="appointment-calendar__density" aria-hidden="true">
                        <span style={{ width: `${density}%` }} />
                      </span>
                      {day.total > 0 ? (
                        <span className="appointment-calendar__counts">
                          <strong className="appointment-calendar__total">
                            {day.total} {day.total === 1 ? 'appt' : 'appts'}
                          </strong>
                          {topServices.length > 0 ? (
                            <ul className="appointment-calendar__services" aria-hidden="true">
                              {topServices.map((service) => (
                                <li key={service.type}>
                                  <span className="appointment-calendar__service-name">{service.type}</span>
                                  <span className="appointment-calendar__service-count">{service.count}</span>
                                </li>
                              ))}
                              {overflow > 0 ? <li className="appointment-calendar__more">+{overflow} more</li> : null}
                            </ul>
                          ) : null}
                        </span>
                      ) : (
                        <span className="appointment-calendar__empty">—</span>
                      )}
                    </Link>
                  </li>
                );
              })}
            </ol>
          </>
        ) : (
        <p role="alert" className="appointment-calendar__unavailable">
          Calendar totals could not be loaded. Try again.
        </p>
      )}
      <p className="appointment-calendar__footnote">Select a date to see that day’s roster. Cancelled visits are excluded from counts.</p>
    </section>
  );
}

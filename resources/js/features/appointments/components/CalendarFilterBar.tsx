import { DatePicker, DatePickerInput, Select, SelectItem, SelectSkeleton } from '@carbon/react';
import { Link, router } from '@inertiajs/react';
import { useEffect, useState, type ReactNode } from 'react';
import { routes } from '@/app/routes';
import { getJson } from '@/lib/http';
import type { AppointmentCalendarDay } from '../types/calendar';

type CalendarView = 'day' | 'week' | 'month';

interface CalendarFilterBarProps {
  month: string;
  days: AppointmentCalendarDay[];
  view: CalendarView;
  weekIndex: number;
  focusDate: string | null;
  selectedDay: string | null;
  appointmentTypeFilter: string | null;
}

interface CalendarState {
  month: string;
  view: CalendarView;
  weekIndex: number;
  focusDate: string | null;
  selectedDay: string | null;
  appointmentTypeFilter: string | null;
}

interface AppointmentTypeOption {
  id: string;
  code: string;
  name: string;
  durationMinutes: number;
}

interface AppointmentOptionsResponse {
  types: AppointmentTypeOption[];
  resources: Array<{ id: string; name: string; resourceType: string }>;
}

function calendarHref(state: CalendarState): string {
  const params = new URLSearchParams({ month: state.month, view: state.view });
  if (state.view === 'week') params.set('week', String(state.weekIndex));
  if (state.focusDate) params.set('date', state.focusDate);
  if (state.selectedDay) params.set('day', state.selectedDay);
  if (state.appointmentTypeFilter) params.set('type', state.appointmentTypeFilter);

  return `${routes.workspaces.appointmentCalendar}?${params.toString()}`;
}

function localDate(date: Date): string {
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
}

function dateFromValue(value: string): Date {
  const parsed = new Date(`${value}T00:00:00`);
  return Number.isNaN(parsed.getTime()) ? new Date() : parsed;
}

const VIEW_OPTIONS: ReadonlyArray<{ value: CalendarView; label: string }> = [
  { value: 'day', label: 'Day' },
  { value: 'week', label: 'Week' },
  { value: 'month', label: 'Month' },
];

export function CalendarFilterBar({
  month,
  days,
  view,
  weekIndex,
  focusDate,
  selectedDay,
  appointmentTypeFilter,
}: CalendarFilterBarProps): ReactNode {
  const dateValue = focusDate ?? selectedDay ?? `${month}-01`;
  const [typeOptions, setTypeOptions] = useState<AppointmentTypeOption[]>([]);
  const [typesLoading, setTypesLoading] = useState(true);

  useEffect(() => {
    let active = true;
    setTypesLoading(true);
    void getJson<AppointmentOptionsResponse>(routes.api.appointmentOptions)
      .then((response) => {
        if (active) setTypeOptions(response.types ?? []);
      })
      .catch(() => {
        if (active) setTypeOptions([]);
      })
      .finally(() => {
        if (active) setTypesLoading(false);
      });
    return () => { active = false; };
  }, []);

  // Fallback: derive names from the loaded calendar payload when the
  // options endpoint is unavailable. Canonical list wins when present.
  const fallbackNames = [...new Set(days.flatMap((day) => day.services.map((service) => service.type)))];
  const hasCanonicalList = typeOptions.length > 0;
  const optionNames = hasCanonicalList
    ? typeOptions.map((type) => type.name)
    : fallbackNames;
  const missingSelectedType = appointmentTypeFilter && !optionNames.includes(appointmentTypeFilter)
    ? appointmentTypeFilter
    : null;

  function visit(next: Partial<CalendarState>): void {
    const state: CalendarState = {
      month,
      view,
      weekIndex,
      focusDate,
      selectedDay,
      appointmentTypeFilter,
      ...next,
    };
    router.visit(calendarHref(state), { preserveScroll: true });
  }

  function selectDate(date: string): void {
    visit({ month: date.slice(0, 7), focusDate: date, selectedDay: null });
  }

  function viewHref(option: CalendarView): string {
    const today = localDate(new Date());
    return calendarHref({
      month: option === 'day' ? (selectedDay ?? focusDate ?? today).slice(0, 7) : month,
      view: option,
      weekIndex: option === 'week' ? weekIndex : 0,
      focusDate: option === 'day' ? (focusDate ?? selectedDay ?? today) : (focusDate ?? selectedDay),
      selectedDay: null,
      appointmentTypeFilter,
    });
  }

  const hasActiveFilters = appointmentTypeFilter !== null;
  const clearHref = calendarHref({
    month, view, weekIndex, focusDate, selectedDay: null, appointmentTypeFilter: null,
  });

  return (
    <section className="appointment-toolbar" aria-label="Calendar filters">
      <nav className="appointment-toolbar__view-options" aria-label="Calendar view">
        {VIEW_OPTIONS.map((option) => (
          <Link
            key={option.value}
            className={`appointment-toolbar__view-option${view === option.value ? ' appointment-toolbar__view-option--selected' : ''}`}
            href={viewHref(option.value)}
            aria-current={view === option.value ? 'page' : undefined}
          >
            {option.label}
          </Link>
        ))}
      </nav>

      <div className="appointment-toolbar__fields">
        <div className="appointment-toolbar__date">
          <DatePicker
            datePickerType="single"
            dateFormat="m/d/Y"
            value={dateFromValue(dateValue)}
            onChange={(dates) => {
              const [date] = dates;
              if (date) selectDate(localDate(date));
            }}
          >
            <DatePickerInput
              id="appointments-calendar-date"
              labelText="Jump to date"
              placeholder="mm/dd/yyyy"
            />
          </DatePicker>
        </div>

        <div className="appointment-toolbar__type">
          {typesLoading ? (
            <SelectSkeleton />
          ) : (
            <Select
              id="appointments-calendar-type"
              labelText="Appointment type"
              value={appointmentTypeFilter ?? ''}
              onChange={(event) => visit({ appointmentTypeFilter: event.currentTarget.value || null })}
            >
              <SelectItem value="" text="All appointment types" />
              {hasCanonicalList
                ? typeOptions.map((type) => <SelectItem key={type.id} value={type.name} text={type.name} />)
                : fallbackNames.map((name) => <SelectItem key={name} value={name} text={name} />)}
              {missingSelectedType ? (
                <SelectItem key={missingSelectedType} value={missingSelectedType} text={missingSelectedType} />
              ) : null}
            </Select>
          )}
        </div>

        {hasActiveFilters ? (
          <div className="appointment-toolbar__meta">
            <Link className="appointment-toolbar__clear" href={clearHref}>
              Clear all
            </Link>
          </div>
        ) : null}
      </div>
    </section>
  );
}

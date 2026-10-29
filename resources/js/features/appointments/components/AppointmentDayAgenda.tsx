import type { ReactNode } from 'react';
import { useState } from 'react';
import { Link, router } from '@inertiajs/react';
import { routes } from '@/app/routes';
import { DockedPanel } from '@/components/panels';
import { Status } from '@/components/ui/Status';
import {
  AppointmentLifecycleButtons,
  appointmentStatusKind,
  appointmentStatusLabel,
} from './AppointmentLifecycleButtons';
import type { AppointmentDayItem } from '../types/calendar';

function formatDate(value: string): string {
  return new Intl.DateTimeFormat(undefined, {
    weekday: 'long', month: 'long', day: 'numeric', year: 'numeric',
  }).format(new Date(`${value}T12:00:00`));
}

function formatTime(value: string): string {
  const date = new Date(value);
  return Number.isNaN(date.getTime())
    ? value
    : new Intl.DateTimeFormat(undefined, { hour: 'numeric', minute: '2-digit' }).format(date);
}

function formatTimeRange(start: string, end: string): string {
  return `${formatTime(start)} – ${formatTime(end)}`;
}

function PatientPhoto({ patientId, displayName }: { patientId: string; displayName: string }): ReactNode {
  const [failed, setFailed] = useState(false);
  if (failed) {
    return (
      <span className="appointment-agenda__photo-fallback" aria-hidden="true">
        {displayName.charAt(0).toUpperCase()}
      </span>
    );
  }
  return (
    <img
      className="appointment-agenda__photo"
      src={routes.patient.photo(patientId)}
      alt=""
      width={32}
      height={32}
      loading="lazy"
      onError={() => setFailed(true)}
    />
  );
}

interface PatientGroup {
  key: string;
  patient: AppointmentDayItem['patient'];
  items: AppointmentDayItem[];
}

/**
 * Group a day's appointments by patient so one person with several bookings
 * appears once with nested visits, instead of repeating their name/photo.
 * Restricted (patient: null) rows cannot be proven to be the same person,
 * so each stays in its own group.
 */
function groupByPatient(appointments: AppointmentDayItem[]): PatientGroup[] {
  const groups = new Map<string, PatientGroup>();
  const order: string[] = [];
  for (const appointment of appointments) {
    const key = appointment.patient ? `patient:${appointment.patient.patientId}` : `appointment:${appointment.id}`;
    const existing = groups.get(key);
    if (existing) {
      existing.items.push(appointment);
    } else {
      groups.set(key, { key, patient: appointment.patient, items: [appointment] });
      order.push(key);
    }
  }
  return order
    .map((key) => groups.get(key) as PatientGroup)
    .map((group) => ({
      ...group,
      items: [...group.items].sort((a, b) => a.scheduledStart.localeCompare(b.scheduledStart)),
    }))
    .sort((a, b) => (a.items[0]?.scheduledStart ?? '').localeCompare(b.items[0]?.scheduledStart ?? ''));
}

export function AppointmentDayAgenda({
  date,
  appointments,
  error,
  actionError = null,
}: {
  date: string;
  appointments: AppointmentDayItem[];
  error: boolean;
  actionError?: string | null;
}): ReactNode {
  const clearSelectionHref = `${routes.workspaces.appointmentCalendar}?month=${encodeURIComponent(date.slice(0, 7))}`;
  const close = (): void => router.visit(clearSelectionHref, { preserveScroll: true });
  const groups = groupByPatient(appointments);

  return (
    <DockedPanel heading={formatDate(date)} onClose={close}>
      <div className="appointment-agenda">
        {!error ? (
          <p className="appointment-agenda__count">
            <strong>{appointments.length}</strong> {appointments.length === 1 ? 'appointment' : 'appointments'}
            {groups.length > 0 ? ` · ${groups.length} ${groups.length === 1 ? 'patient' : 'patients'}` : null}
            <span className="appointment-agenda__sub">Arrival is recorded here; check-in happens at Front Desk.</span>
          </p>
        ) : null}
        {actionError ? <p role="alert" className="appointment-agenda__error">{actionError}</p> : null}
        {error ? (
          <p role="alert">Appointments for this day could not be loaded. Try again.</p>
        ) : appointments.length === 0 ? (
          <div role="status" className="appointment-agenda__empty">
            <p>No appointments scheduled for this day.</p>
            <p className="appointment-agenda__hint">Use “Book appointment” to create the first booking for this date.</p>
          </div>
        ) : (
          <ol className="appointment-agenda__groups">
            {groups.map((group) => (
              <li key={group.key} className="appointment-agenda__group">
                <div className="appointment-agenda__patient">
                  {group.patient ? (
                    <>
                      <PatientPhoto patientId={group.patient.patientId} displayName={group.patient.displayName} />
                      <span className="appointment-agenda__patient-info">
                        <Link
                          className="appointment-agenda__patient-name"
                          href={routes.patient.chart(group.patient.patientId)}
                        >
                          {group.patient.displayName}
                        </Link>
                        <span className="appointment-agenda__patient-number">
                          {group.patient.patientNumber}
                          {group.items.length > 1 ? ` · ${group.items.length} visits` : null}
                        </span>
                      </span>
                    </>
                  ) : (
                    <span className="appointment-agenda__patient-info">
                      <span className="appointment-agenda__patient-name">Patient details restricted</span>
                    </span>
                  )}
                </div>
                <ol className="appointment-agenda__visits">
                  {group.items.map((appointment) => (
                    <li key={appointment.id} className="appointment-agenda__visit">
                      <div className="appointment-agenda__visit-top">
                        <time dateTime={appointment.scheduledStart}>
                          {formatTimeRange(appointment.scheduledStart, appointment.scheduledEnd)}
                        </time>
                        <Status kind={appointmentStatusKind(appointment.status)}>{appointmentStatusLabel(appointment.status)}</Status>
                      </div>
                      <p className="appointment-agenda__meta">
                        {appointment.appointmentNumber}
                        {appointment.appointmentTypeName ? ` · ${appointment.appointmentTypeName}` : ''}
                        {appointment.resourceName ? ` · ${appointment.resourceName}` : ''}
                      </p>
                      {appointment.reason ? <p className="appointment-agenda__reason">“{appointment.reason}”</p> : null}
                      <AppointmentLifecycleButtons appointment={appointment} />
                    </li>
                  ))}
                </ol>
              </li>
            ))}
          </ol>
        )}
      </div>
    </DockedPanel>
  );
}

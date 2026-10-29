import type { ReactNode } from 'react';
import { useState } from 'react';
import { Link } from '@inertiajs/react';
import { routes } from '@/app/routes';
import { DockedPanel } from '@/components/panels';
import { Status } from '@/components/ui/Status';
import {
  AppointmentLifecycleButtons,
  appointmentStatusKind,
  appointmentStatusLabel,
} from './AppointmentLifecycleButtons';

export interface AppointmentDetails {
  id: string;
  appointmentNumber: string;
  scheduledStart: string;
  scheduledEnd: string;
  status: string;
  reason: string | null;
  appointmentTypeName: string | null;
  resourceName: string | null;
  patient: {
    patientId: string;
    patientNumber: string;
    displayName: string;
  } | null;
}

function formatDateTime(value: string): string {
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return value;
  return new Intl.DateTimeFormat(undefined, {
    weekday: 'long', month: 'long', day: 'numeric', year: 'numeric',
    hour: 'numeric', minute: '2-digit',
  }).format(date);
}

function DetailsPatientPhoto({ patientId, displayName }: { patientId: string; displayName: string }): ReactNode {
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

/**
 * Appointment Details (foundation §5): the full scheduling context for one
 * appointment — patient, when, type, resource, status, reason — with the
 * canonical lifecycle actions. Opened from calendar appointments and from
 * Patient Chart rows; the content and actions are identical in both places.
 */
export function AppointmentDetailsPanel({
  appointment,
  onClose,
  offsetInlineEnd = '0px',
  returnTo,
}: {
  appointment: AppointmentDetails;
  onClose: () => void;
  offsetInlineEnd?: string;
  returnTo?: string;
}): ReactNode {
  return (
    <DockedPanel
      heading={`Appointment ${appointment.appointmentNumber}`}
      onClose={onClose}
      offsetInlineEnd={offsetInlineEnd}
    >
      <div className="appointment-agenda">
        <div className="appointment-agenda__patient" style={{ padding: 0 }}>
          {appointment.patient ? (
            <>
              <DetailsPatientPhoto patientId={appointment.patient.patientId} displayName={appointment.patient.displayName} />
              <span className="appointment-agenda__patient-info">
                <Link
                  className="appointment-agenda__patient-name"
                  href={routes.patient.chart(appointment.patient.patientId)}
                >
                  {appointment.patient.displayName}
                </Link>
                <span className="appointment-agenda__patient-number">{appointment.patient.patientNumber}</span>
              </span>
            </>
          ) : (
            <span className="appointment-agenda__patient-info">
              <span className="appointment-agenda__patient-name">Patient details restricted</span>
            </span>
          )}
        </div>

        <dl className="appointment-details__facts">
          <div>
            <dt>When</dt>
            <dd>
              <time dateTime={appointment.scheduledStart}>{formatDateTime(appointment.scheduledStart)}</time>
              {' – '}
              <time dateTime={appointment.scheduledEnd}>{formatDateTime(appointment.scheduledEnd)}</time>
            </dd>
          </div>
          <div>
            <dt>Status</dt>
            <dd>
              <Status kind={appointmentStatusKind(appointment.status)}>
                {appointmentStatusLabel(appointment.status)}
              </Status>
            </dd>
          </div>
          {appointment.appointmentTypeName ? (
            <div>
              <dt>Type</dt>
              <dd>{appointment.appointmentTypeName}</dd>
            </div>
          ) : null}
          {appointment.resourceName ? (
            <div>
              <dt>Resource</dt>
              <dd>{appointment.resourceName}</dd>
            </div>
          ) : null}
          {appointment.reason ? (
            <div>
              <dt>Reason</dt>
              <dd>“{appointment.reason}”</dd>
            </div>
          ) : null}
        </dl>

        <p className="appointment-agenda__hint">Arrival is recorded here; check-in happens at Front Desk.</p>

        <AppointmentLifecycleButtons appointment={appointment} returnTo={returnTo} />
      </div>
    </DockedPanel>
  );
}

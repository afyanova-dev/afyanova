import { InlineNotification, Select, SelectItem, Tag } from '@carbon/react';
import { Link } from '@inertiajs/react';
import { useState, type ReactNode } from 'react';
import { formatClinicalDateTime, formatDobDate } from '@/lib/formatting';
import type { PatientChartData, PatientChartPatient } from '@/features/patients/types/chart';
import {
  buildTimelineEvents,
  clinicalTagType,
  humanizeClinicalValue,
  type PatientTimelineEvent,
  type TimelineEventKind,
} from './patientChartData';

const KIND_OPTIONS: Array<{ value: 'all' | TimelineEventKind; label: string }> = [
  { value: 'all', label: 'All events' },
  { value: 'registration', label: 'Registration' },
  { value: 'appointment', label: 'Appointments' },
  { value: 'visit', label: 'Visits' },
  { value: 'vitals', label: 'Vitals' },
  { value: 'problem', label: 'Problems' },
  { value: 'allergy', label: 'Allergies' },
  { value: 'medication', label: 'Medications' },
  { value: 'lab-order', label: 'Lab orders' },
  { value: 'lab-result', label: 'Lab results' },
  { value: 'imaging', label: 'Imaging' },
  { value: 'procedure', label: 'Procedures' },
  { value: 'plan', label: 'Plans' },
  { value: 'program', label: 'Programs' },
  { value: 'billing', label: 'Billing' },
];

function eventKindLabel(event: PatientTimelineEvent): string {
  return humanizeClinicalValue(event.kind.replace('-', ' '));
}

function groupByDay(events: PatientTimelineEvent[]): Array<[string, PatientTimelineEvent[]]> {
  const groups = new Map<string, PatientTimelineEvent[]>();

  for (const event of events) {
    const day = event.at.slice(0, 10);
    const dayEvents = groups.get(day) ?? [];
    dayEvents.push(event);
    groups.set(day, dayEvents);
  }

  return [...groups.entries()];
}

function eventTimeLabel(event: PatientTimelineEvent): string {
  const formatted = formatClinicalDateTime(event.at);
  if (!formatted) return event.at;

  const time = formatted.split(', ').at(-1);
  return time && time !== formatted ? time : 'Time unavailable';
}

/**
 * Chronological care ledger built only from chart reads the controller
 * already provides. It starts at registration and includes visits, orders,
 * results, medications, procedures, programs, and billing events.
 */
export function PatientTimeline({
  patient,
  chart,
}: {
  patient: PatientChartPatient;
  chart: PatientChartData;
}): ReactNode {
  const [kind, setKind] = useState<'all' | TimelineEventKind>('all');
  const events = buildTimelineEvents(patient, chart);
  const visibleEvents = kind === 'all' ? events : events.filter((event) => event.kind === kind);
  const groups = groupByDay(visibleEvents);

  return (
    <div className="patient-chart__timeline">
      <div className="patient-chart__timeline-toolbar">
        <div>
          <div className="patient-chart__timeline-heading">
            <h2>Patient timeline</h2>
          </div>
          {chart.restricted.length > 0 || chart.unavailable.length > 0 ? (
            <InlineNotification
              kind="info"
              lowContrast
              hideCloseButton
              className="patient-chart__timeline-note"
              title="Restricted sections excluded"
              subtitle="Some chart sections are restricted or unavailable and are excluded from this timeline."
            />
          ) : null}
        </div>
        <Select
          id="patient-timeline-filter"
          labelText="Show"
          value={kind}
          onChange={(event) => setKind(event.currentTarget.value as 'all' | TimelineEventKind)}
          className="patient-chart__timeline-filter"
        >
          {KIND_OPTIONS.map((option) => (
            <SelectItem key={option.value} value={option.value} text={option.label} />
          ))}
        </Select>
      </div>

      {visibleEvents.length === 0 ? (
        <p className="patient-chart__timeline-empty">
          No events are available for this filter. Restricted or unavailable sections are excluded from the timeline.
        </p>
      ) : (
        <div className="patient-chart__timeline-groups">
          {groups.map(([day, dayEvents]) => (
            <section key={day} className="patient-chart__timeline-day" aria-label={formatDobDate(day) ?? day}>
              <h3>
                <time dateTime={day}>{formatDobDate(day) ?? day}</time>
                <span>{dayEvents.length} event{dayEvents.length === 1 ? '' : 's'}</span>
              </h3>
              <ol className="patient-chart__timeline-events">
                {dayEvents.map((event) => (
                  <li key={event.id} className={`patient-chart__timeline-event patient-chart__timeline-event--${event.kind}`}>
                    <time className="patient-chart__timeline-time" dateTime={event.at}>
                      {eventTimeLabel(event)}
                    </time>
                    <span className="patient-chart__timeline-marker" aria-hidden="true" />
                    <div className="patient-chart__timeline-copy">
                      <div className="patient-chart__timeline-title">
                        <span>{event.title}</span>
                        {event.status ? <Tag type={clinicalTagType(event.status)}>{humanizeClinicalValue(event.status)}</Tag> : null}
                      </div>
                      {event.detail ? <p>{event.detail}</p> : null}
                      <div className="patient-chart__timeline-meta">
                        <span>{eventKindLabel(event)}</span>
                        <Link href={event.href} aria-label={`Open ${event.title} in its chart section`}>Open section</Link>
                      </div>
                    </div>
                  </li>
                ))}
              </ol>
            </section>
          ))}
        </div>
      )}
    </div>
  );
}

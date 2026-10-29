import { Link } from '@inertiajs/react';
import { Button, Tag } from '@carbon/react';
import { useEffect, useState, type ReactNode } from 'react';
import { ArrowLeft, ArrowRight, UserProfile } from '@/icons';
import type { NurseStationShowProps } from '@/features/nurse-station/types';

interface EncounterBannerContext {
  type: string;
  status: string;
}

interface QueuePatientBannerProps {
  workflow: 'nursing' | 'consultation';
  patient: NurseStationShowProps['patient'];
  entry: NurseStationShowProps['entry'];
  queueNavigation: NurseStationShowProps['queueNavigation'];
  queueHref: string;
  queueLabel: string;
  entryHref: (id: string) => string;
  encounter?: EncounterBannerContext;
  chartHref?: string;
}

function initials(name: string): string {
  return name.split(/\s+/).filter(Boolean).slice(0, 2).map((part) => part[0]).join('').toUpperCase();
}

function PatientPhoto({ name, photoUrl }: { name: string; photoUrl: string | null }): ReactNode {
  const [unavailable, setUnavailable] = useState(photoUrl === null);

  useEffect(() => {
    setUnavailable(photoUrl === null);
  }, [photoUrl]);

  if (photoUrl && !unavailable) {
    return (
      <img
        className="clinical-queue-banner__avatar"
        src={photoUrl}
        alt={`${name} patient photo`}
        width={56}
        height={56}
        onError={() => setUnavailable(true)}
      />
    );
  }

  return (
    <div className="clinical-queue-banner__avatar" role="img" aria-label={`${name} photo placeholder`}>
      {initials(name)}
    </div>
  );
}

export function QueuePatientBanner({
  workflow,
  patient,
  entry,
  queueNavigation,
  queueHref,
  queueLabel,
  entryHref,
  encounter,
  chartHref,
}: QueuePatientBannerProps): ReactNode {
  const isConsultation = workflow === 'consultation';

  return (
    <header
      className={`clinical-queue-banner clinical-queue-banner--${workflow}`}
      aria-label={isConsultation ? 'Patient consultation banner' : 'Patient assessment banner'}
    >
      <div className="clinical-queue-banner__identity">
        <PatientPhoto name={patient.displayName} photoUrl={patient.photoUrl} />
        <div className="clinical-queue-banner__details">
          <div className="clinical-queue-banner__name-row">
            <h1>{patient.displayName}</h1>
            <Tag type={patient.isProvisional ? 'warm-gray' : 'blue'}>
              {patient.isProvisional ? 'Provisional identity' : patient.status}
            </Tag>
            {chartHref ? (
              <Button
                as={Link}
                href={chartHref}
                kind="ghost"
                size="sm"
                hasIconOnly
                renderIcon={UserProfile}
                iconDescription="Open patient chart"
                tooltipPosition="right"
                aria-label="Open patient chart"
              />
            ) : null}
          </div>
          <div className="clinical-queue-banner__meta">
            <strong>{patient.patientNumber}</strong>
            <span>{patient.sex}</span>
            <span>DOB {patient.dateOfBirth ?? 'Not recorded'}</span>
            {isConsultation ? (
              <span>{entry.ticketNumber} · {entry.queue}</span>
            ) : (
              <>
                <span>{entry.ticketNumber} · {entry.queue}</span>
                <Tag type={entry.priority === 'EMERGENCY' ? 'red' : entry.priority === 'URGENT' ? 'purple' : 'gray'}>
                  {entry.priority}
                </Tag>
              </>
            )}
          </div>
        </div>
      </div>
      {isConsultation && encounter ? (
        <div className="clinical-queue-banner__visit" aria-label="Current encounter">
          <span className="clinical-queue-banner__visit-label">Current visit</span>
          <strong>{encounter.type.replaceAll('_', ' ')}</strong>
          <Tag type={encounter.status === 'ACTIVE' ? 'green' : 'blue'}>
            {encounter.status === 'ACTIVE' ? 'In progress' : encounter.status}
          </Tag>
        </div>
      ) : null}
      <div className="clinical-queue-banner__actions">
        <div
          className="clinical-queue-banner__navigation"
          aria-label={isConsultation ? 'Move between consultation patients' : 'Move between triage patients'}
        >
          {queueNavigation.previous ? (
            <Button
              kind="ghost"
              size="sm"
              hasIconOnly
              renderIcon={ArrowLeft}
              iconDescription={`Previous patient in queue · ${queueNavigation.previous.ticketNumber}`}
              tooltipPosition="bottom"
              aria-label={`Previous patient in queue · ${queueNavigation.previous.ticketNumber}`}
              as={Link}
              href={entryHref(queueNavigation.previous.id)}
            />
          ) : null}
          <span className="clinical-queue-banner__position" aria-live="polite" aria-atomic="true">
            {queueNavigation.position} of {queueNavigation.total} in queue
          </span>
          {queueNavigation.next ? (
            <Button
              kind="ghost"
              size="sm"
              hasIconOnly
              renderIcon={ArrowRight}
              iconDescription={`Next patient in queue · ${queueNavigation.next.ticketNumber}`}
              tooltipPosition="bottom"
              aria-label={`Next patient in queue · ${queueNavigation.next.ticketNumber}`}
              as={Link}
              href={entryHref(queueNavigation.next.id)}
            />
          ) : null}
        </div>
        <Button as={Link} href={queueHref} kind="tertiary" size="sm">
          {queueLabel}
        </Button>
      </div>
    </header>
  );
}

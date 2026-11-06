import { Link } from '@inertiajs/react';
import { Button } from '@carbon/react';
import type { ReactNode } from 'react';
import { ArrowLeft, ArrowRight, UserProfile } from '@/icons';
import type { NurseStationShowProps } from '@/features/nurse-station/types';
import { PatientBanner } from '@/features/patients/components/PatientBanner';

interface EncounterBannerContext {
  encounterType: string;
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
  encounter: EncounterBannerContext;
  chartHref?: string;
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
    <div className={`patient-chart patient-chart--patient patient-chart--entry patient-chart--entry-${workflow}`}>
      <PatientBanner
        patient={patient}
        photoUrl={patient.photoUrl}
        activeEncounter={{
          encounterType: encounter.encounterType,
          status: encounter.status,
        }}
        queueEntry={{
          serviceCapability: isConsultation ? 'CONSULTATION' : 'TRIAGE',
          ticketNumber: entry.ticketNumber,
          priority: entry.priority,
          status: entry.status,
        }}
        actions={(
          <div className="clinical-entry-banner__actions">
            {chartHref ? (
              <Button
                as={Link}
                href={chartHref}
                kind="ghost"
                size="sm"
                hasIconOnly
                renderIcon={UserProfile}
                iconDescription="Open patient chart"
                tooltipPosition="bottom"
                aria-label="Open patient chart"
              />
            ) : null}
            <div
              className="clinical-entry-banner__navigation"
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
              <span className="clinical-entry-banner__position" aria-live="polite" aria-atomic="true">
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
        )}
      />
    </div>
  );
}

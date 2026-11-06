import { Popover, PopoverContent, Tag } from '@carbon/react';
import { useEffect, useState, type ReactNode } from 'react';
import { Status } from '@/components/ui/Status';
import { PatientBannerSafety } from '@/features/patients/components/PatientBannerSafety';
import { ageDisplay, formatClinicalDateTime, formatDobDate } from '@/lib/formatting';
import { GenderFemale, GenderMale } from '@/icons';
import type {
  ActivePatientEncounter,
  PatientChartData,
  PatientChartPatient,
  PatientChartProps,
} from '@/features/patients/types/chart';

type PatientBannerPatient = Pick<
  PatientChartPatient,
  'patientNumber' | 'displayName' | 'dateOfBirth' | 'sex' | 'status' | 'isProvisional'
>;
type PatientBannerEncounter = Pick<ActivePatientEncounter, 'encounterType' | 'status'>
  & Partial<Pick<ActivePatientEncounter, 'encounterNumber' | 'arrivedAt' | 'startedAt'>>;
type PatientBannerQueueEntry = Pick<
  NonNullable<PatientChartProps['queueEntry']>,
  'serviceCapability' | 'ticketNumber' | 'priority' | 'status'
>;

function PatientPhoto({ patientName, photoUrl }: { patientName: string; photoUrl: string | null }): ReactNode {
  const [unavailable, setUnavailable] = useState(photoUrl === null);

  useEffect(() => {
    setUnavailable(photoUrl === null);
  }, [photoUrl]);

  if (photoUrl && !unavailable) {
    return (
      <img
        className="patient-chart__photo"
        src={photoUrl}
        alt={`${patientName} patient photo`}
        width={96}
        height={96}
        onError={() => setUnavailable(true)}
      />
    );
  }

  return (
    <div
      role="img"
      aria-label="Patient photo placeholder"
      className="patient-chart__photo patient-chart__photo--empty"
    >
      {patientName.split(/\s+/).filter(Boolean).slice(0, 2).map((part) => part[0]).join('').toUpperCase()}
    </div>
  );
}

function readableLabel(value: string): string {
  return value
    .toLowerCase()
    .split(/[_\s]+/)
    .filter(Boolean)
    .map((part) => part[0]!.toUpperCase() + part.slice(1))
    .join(' ');
}

/**
 * Chart banner: photo, identity, state tags, actions menu. Tags render
 * only when true (OpenMRS banner pattern) — never empty placeholders.
 */
export function PatientBanner({
  patient,
  photoUrl,
  activeEncounter,
  queueEntry,
  chart,
  stickyNote,
  actions,
}: {
  patient: PatientBannerPatient;
  photoUrl: string | null;
  activeEncounter: PatientBannerEncounter | null;
  queueEntry: PatientBannerQueueEntry | null;
  chart?: PatientChartData;
  stickyNote?: ReactNode;
  actions?: ReactNode;
}): ReactNode {
  const [visitSummaryOpen, setVisitSummaryOpen] = useState(false);
  const birthDate = formatDobDate(patient.dateOfBirth) ?? patient.dateOfBirth;
  const age = ageDisplay(patient.dateOfBirth);
  const visitWorkflow = queueEntry?.serviceCapability
    ? readableLabel(queueEntry.serviceCapability)
    : null;
  const normalizedSex = patient.sex.toLowerCase();
  const sexIcon = normalizedSex.includes('female')
    ? <GenderFemale size={16} aria-hidden="true" />
    : normalizedSex.includes('male')
      ? <GenderMale size={16} aria-hidden="true" />
      : null;
  return (
    <header aria-label="Patient header" className="patient-chart__banner">
      <div className="patient-chart__banner-top">
        <div className="patient-chart__identity">
          <div className="patient-chart__identity-main">
            <PatientPhoto patientName={patient.displayName} photoUrl={photoUrl} />
            <div className="patient-chart__identity-copy">
              <div className="patient-chart__name-row">
                <h1>{patient.displayName}</h1>
                <span className="patient-chart__sex">
                  {sexIcon}
                  {readableLabel(patient.sex)}
                </span>
                {activeEncounter ? (
                  <Popover
                    open={visitSummaryOpen}
                    onRequestClose={() => setVisitSummaryOpen(false)}
                    align="bottom-start"
                  >
                    <button
                      type="button"
                      className="patient-chart__visit-badge"
                      aria-expanded={visitSummaryOpen}
                      aria-label={activeEncounter.encounterNumber
                        ? `Visit summary for ${activeEncounter.encounterNumber}`
                        : 'Visit summary'}
                      onClick={() => setVisitSummaryOpen((open) => !open)}
                    >
                      <Status kind="active">Active visit</Status>
                    </button>
                    {/* Mounted only when open so the summary text never
                        duplicates the banner row for assistive tech. */}
                    {visitSummaryOpen ? (
                      <PopoverContent className="patient-chart__visit-summary">
                        <p className="patient-chart__visit-summary-title">Visit summary</p>
                        <dl className="patient-chart__visit-summary-list">
                          {activeEncounter.encounterNumber ? (
                            <div>
                            <dt>Visit number</dt>
                            <dd>{activeEncounter.encounterNumber}</dd>
                            </div>
                          ) : null}
                          <div>
                            <dt>Type</dt>
                            <dd>{readableLabel(activeEncounter.encounterType)}</dd>
                          </div>
                          <div>
                            <dt>Status</dt>
                            <dd>{readableLabel(activeEncounter.status)}</dd>
                          </div>
                          {queueEntry ? (
                            <>
                              <div>
                                <dt>Queue</dt>
                                <dd>{visitWorkflow ?? 'Queue'} · {queueEntry.ticketNumber}</dd>
                              </div>
                              <div>
                                <dt>Queue status</dt>
                                <dd>{readableLabel(queueEntry.status)}</dd>
                              </div>
                              <div>
                                <dt>Priority</dt>
                                <dd>{readableLabel(queueEntry.priority)}</dd>
                              </div>
                            </>
                          ) : null}
                          {activeEncounter.arrivedAt !== undefined ? (
                            <div>
                            <dt>Arrived</dt>
                            <dd>{formatClinicalDateTime(activeEncounter.arrivedAt) ?? '—'}</dd>
                            </div>
                          ) : null}
                          {activeEncounter.startedAt ? (
                            <div>
                              <dt>Started</dt>
                              <dd>{formatClinicalDateTime(activeEncounter.startedAt)}</dd>
                            </div>
                          ) : null}
                        </dl>
                      </PopoverContent>
                    ) : null}
                  </Popover>
                ) : (
                  <Status kind="inactive">No active visit</Status>
                )}
                {queueEntry && (queueEntry.priority === 'EMERGENCY' || queueEntry.priority === 'URGENT') ? (
                  <Tag type={queueEntry.priority === 'EMERGENCY' ? 'red' : 'purple'}>
                    {readableLabel(queueEntry.priority)}
                  </Tag>
                ) : null}
                {patient.isProvisional ? <Tag type="cool-gray">Provisional</Tag> : null}
                {patient.status === 'DECEASED' ? <Tag type="red">Deceased</Tag> : null}
                {patient.status === 'MERGED' ? <Tag type="cool-gray">Merged</Tag> : null}
              </div>
              <div className="patient-chart__identity-meta" aria-label="Patient details">
                {age ? <span className="patient-chart__meta-item">{age}</span> : null}
                {age && birthDate ? <span className="patient-chart__meta-divider" aria-hidden="true">·</span> : null}
                {birthDate ? <span className="patient-chart__meta-item">DOB {birthDate}</span> : null}
                {birthDate ? <span className="patient-chart__meta-divider" aria-hidden="true">·</span> : null}
                <Tag
                  type="cool-gray"
                  className="patient-chart__patient-number-badge"
                  title="Patient number"
                >
                  {patient.patientNumber}
                </Tag>
              </div>
            </div>
          </div>
        </div>
        {chart ? <PatientBannerSafety chart={chart} /> : null}
        <div className="patient-chart__banner-actions">
          <div className="patient-chart__banner-action-row">
            {stickyNote}
            {actions}
          </div>
        </div>
      </div>
    </header>
  );
}

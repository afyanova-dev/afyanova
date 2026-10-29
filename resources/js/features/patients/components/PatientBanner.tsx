import { Button, Popover, PopoverContent, Tag } from '@carbon/react';
import { useEffect, useState, type ReactNode } from 'react';
import { Status } from '@/components/ui/Status';
import { ageDisplay, formatClinicalDateTime, formatDobDate } from '@/lib/formatting';
import { GenderFemale, GenderMale, ChevronDown, ChevronUp } from '@/icons';
import type {
  ActivePatientEncounter,
  PatientChartPatient,
} from '@/features/patients/types/chart';
import { PatientActionsMenu } from './PatientActionsMenu';

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
}: {
  patient: PatientChartPatient;
  photoUrl: string | null;
  activeEncounter: ActivePatientEncounter | null;
}): ReactNode {
  const [detailsExpanded, setDetailsExpanded] = useState(false);
  const [visitSummaryOpen, setVisitSummaryOpen] = useState(false);
  const birthDate = formatDobDate(patient.dateOfBirth) ?? patient.dateOfBirth;
  const age = ageDisplay(patient.dateOfBirth);
  const normalizedSex = patient.sex.toLowerCase();
  const sexIcon = normalizedSex.includes('female')
    ? <GenderFemale size={16} aria-hidden="true" />
    : normalizedSex.includes('male')
      ? <GenderMale size={16} aria-hidden="true" />
      : null;
  const addresses = patient.addresses.flatMap((address, index) => {
    const value = [
      address.streetOrVillage,
      address.ward,
      address.district,
      address.region,
      address.postalCode,
    ].filter(Boolean).join(', ');

    return value ? [{ id: address.id || `address-${index}`, type: address.type, value }] : [];
  });

  return (
    <header aria-label="Patient header" className="patient-chart__banner">
      <div className="patient-chart__banner-top">
        <div className="patient-chart__identity">
          <div className="patient-chart__identity-main">
          <PatientPhoto patientName={patient.displayName} photoUrl={photoUrl} />
          <div className="patient-chart__identity-copy">
            <p className="patient-chart__eyebrow">Patient</p>
            <div className="patient-chart__name-row">
              <h1>{patient.displayName}</h1>
              <span className="patient-chart__sex">
                {sexIcon}
                {readableLabel(patient.sex)}
              </span>
              {activeEncounter ? (
                <>
                  <Popover
                    open={visitSummaryOpen}
                    onRequestClose={() => setVisitSummaryOpen(false)}
                    align="bottom-start"
                  >
                    <button
                      type="button"
                      className="patient-chart__visit-badge"
                      aria-expanded={visitSummaryOpen}
                      aria-label={`Visit summary for ${activeEncounter.encounterNumber}`}
                      onClick={() => setVisitSummaryOpen((open) => !open)}
                    >
                      <Status kind="info">Active visit</Status>
                    </button>
                    {/* Mounted only when open so the summary text never
                        duplicates the banner row for assistive tech. */}
                    {visitSummaryOpen ? (
                      <PopoverContent className="patient-chart__visit-summary">
                      <p className="patient-chart__visit-summary-title">Visit summary</p>
                      <dl className="patient-chart__visit-summary-list">
                        <div>
                          <dt>Visit number</dt>
                          <dd>{activeEncounter.encounterNumber}</dd>
                        </div>
                        <div>
                          <dt>Type</dt>
                          <dd>{readableLabel(activeEncounter.encounterType)}</dd>
                        </div>
                        <div>
                          <dt>Status</dt>
                          <dd>{readableLabel(activeEncounter.status)}</dd>
                        </div>
                        <div>
                          <dt>Arrived</dt>
                          <dd>{formatClinicalDateTime(activeEncounter.arrivedAt) ?? '—'}</dd>
                        </div>
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
                  <span className="patient-chart__encounter-type">
                    {readableLabel(activeEncounter.encounterType)}
                  </span>
                  <span className="patient-chart__encounter-status">
                    {readableLabel(activeEncounter.status)}
                  </span>
                </>
              ) : null}
              {patient.isProvisional ? <Tag type="cool-gray">Provisional</Tag> : null}
              {patient.status === 'DECEASED' ? <Tag type="red">Deceased</Tag> : null}
              {patient.status === 'MERGED' ? <Tag type="cool-gray">Merged</Tag> : null}
            </div>
            <div className="patient-chart__identity-meta" aria-label="Patient details">
              {age ? <span className="patient-chart__meta-item">{age}</span> : null}
              {age && birthDate ? <span className="patient-chart__meta-divider" aria-hidden="true">·</span> : null}
              {birthDate ? <span className="patient-chart__meta-item">{birthDate}</span> : null}
              {birthDate ? <span className="patient-chart__meta-divider" aria-hidden="true">·</span> : null}
              <span className="patient-chart__meta-item patient-chart__meta-item--number">
                Patient number: {patient.patientNumber}
              </span>
            </div>
          </div>
        </div>
        </div>
        <div className="patient-chart__banner-actions">
          <PatientActionsMenu patient={patient} />
          <Button
            className="patient-chart__details-toggle"
            kind="ghost"
            size="lg"
            type="button"
            renderIcon={detailsExpanded ? ChevronUp : ChevronDown}
            aria-expanded={detailsExpanded}
            aria-controls={detailsExpanded ? 'patient-chart-extra-details' : undefined}
            onClick={() => setDetailsExpanded((expanded) => !expanded)}
          >
            {detailsExpanded ? 'Show less' : 'Show more'}
          </Button>
        </div>
      </div>
      {detailsExpanded ? (
        <div
          id="patient-chart-extra-details"
          className="patient-chart__extra-details patient-chart__extra-details--patient"
        >
          <div className="patient-chart__details-grid">
            <section
              className="patient-chart__details-group"
              aria-labelledby="patient-details-demographics"
            >
              <h2 id="patient-details-demographics">Demographics</h2>
              <dl className="patient-chart__details-list">
                <div>
                  <dt>Status</dt>
                  <dd>{readableLabel(patient.status)}{patient.isProvisional ? ' · Provisional' : ''}</dd>
                </div>
                <div>
                  <dt>Date of birth precision</dt>
                  <dd>{readableLabel(patient.dobPrecision)}</dd>
                </div>
              </dl>
            </section>

            {patient.primaryPhone || patient.email ? (
              <section
                className="patient-chart__details-group"
                aria-labelledby="patient-details-contact"
              >
                <h2 id="patient-details-contact">Contact</h2>
                <dl className="patient-chart__details-list">
                  {patient.primaryPhone ? (
                    <div>
                      <dt>Phone</dt>
                      <dd>{patient.primaryPhone}</dd>
                    </div>
                  ) : null}
                  {patient.email ? (
                    <div>
                      <dt>Email</dt>
                      <dd>{patient.email}</dd>
                    </div>
                  ) : null}
                </dl>
              </section>
            ) : null}

            {patient.identifiers.length > 0 || patient.survivingPatientId ? (
              <section
                className="patient-chart__details-group"
                aria-labelledby="patient-details-identifiers"
              >
                <h2 id="patient-details-identifiers">Identifiers</h2>
                <dl className="patient-chart__details-list">
                  {patient.identifiers.map((identifier) => (
                    <div key={identifier.id}>
                      <dt>{readableLabel(identifier.type)}</dt>
                      <dd>{identifier.value}{identifier.isVerified ? ' · Verified' : ''}</dd>
                    </div>
                  ))}
                  {patient.survivingPatientId ? (
                    <div>
                      <dt>Surviving patient ID</dt>
                      <dd>{patient.survivingPatientId}</dd>
                    </div>
                  ) : null}
                </dl>
              </section>
            ) : null}

            {addresses.length > 0 ? (
              <section
                className="patient-chart__details-group"
                aria-labelledby="patient-details-addresses"
              >
                <h2 id="patient-details-addresses">Address</h2>
                <dl className="patient-chart__details-list">
                  {addresses.map((address) => (
                    <div key={address.id}>
                      <dt>{readableLabel(address.type || 'Address')}</dt>
                      <dd>{address.value}</dd>
                    </div>
                  ))}
                </dl>
              </section>
            ) : null}

            {patient.relationships.length > 0 ? (
              <section
                className="patient-chart__details-group"
                aria-labelledby="patient-details-relationships"
              >
                <h2 id="patient-details-relationships">Relationships</h2>
                <dl className="patient-chart__details-list">
                  {patient.relationships.map((relationship) => (
                    <div key={relationship.id}>
                      <dt>{readableLabel(relationship.role)}</dt>
                      <dd>
                        {relationship.name}
                        {relationship.phone ? ` · ${relationship.phone}` : ''}
                        {relationship.label ? ` · ${relationship.label}` : ''}
                      </dd>
                    </div>
                  ))}
                </dl>
              </section>
            ) : null}

            <section
              className="patient-chart__details-group"
              aria-labelledby="patient-details-registration"
            >
              <h2 id="patient-details-registration">Registration</h2>
              <dl className="patient-chart__details-list">
                {patient.registrationSource ? (
                  <div>
                    <dt>Registration source</dt>
                    <dd>{readableLabel(patient.registrationSource)}</dd>
                  </div>
                ) : null}
                {patient.registeredAt ? (
                  <div>
                    <dt>Registered at</dt>
                    <dd>{formatClinicalDateTime(patient.registeredAt)}</dd>
                  </div>
                ) : null}
                <div>
                  <dt>Facility registrations</dt>
                  <dd>{patient.facilityRegistrationCount}</dd>
                </div>
              </dl>
            </section>
          </div>
        </div>
      ) : null}
    </header>
  );
}

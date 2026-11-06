import { Button, Column, Grid, IconButton, Tag, preview__Card } from '@carbon/react';

const CarbonCard = preview__Card.Card;
import { Link } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { routes } from '@/app/routes';
import { Add, ArrowRight, Edit } from '@/icons';
import { formatClinicalDateTime } from '@/lib/formatting';
import type { PatientChartProps } from '@/features/patients/types/chart';
import {
  buildTimelineEvents,
  clinicalTagType,
  humanizeClinicalValue,
  isAbnormalFlag,
  isActiveAllergy,
  isActiveProblem,
  isCurrentMedication,
  latestVital,
  recentLabResults,
  referenceRange,
  vitalFlag,
  type PatientTimelineEvent,
} from './patientChartData';

type SummaryStateKind = 'restricted' | 'unavailable';

function SummaryCard({
  title,
  count,
  href,
  state,
  empty,
  actions,
  children,
}: {
  title: string;
  count?: number;
  href?: string;
  state?: SummaryStateKind;
  empty?: ReactNode;
  actions?: ReactNode;
  children: ReactNode;
}): ReactNode {
  const hasState = state !== undefined;
  const showAction = href && !hasState && !empty;
  return (
    <CarbonCard className="afy-summary-card" aria-label={title} density="productive">
      <CarbonCard.Header>
        <CarbonCard.Title
          titleEnd={
            typeof count === 'number' ? (
              <Tag size="sm" type="cool-gray">{count}</Tag>
            ) : undefined
          }
        >
          {title}
        </CarbonCard.Title>
      </CarbonCard.Header>
      <CarbonCard.Body>
        {hasState ? (
          <SummaryState state={state} />
        ) : empty ?? children}
      </CarbonCard.Body>
      {actions || showAction ? (
        <CarbonCard.Footer>
          {actions}
          {showAction ? (
            <CarbonCard.Action>
              <Button
                as={Link}
                href={href}
                kind="ghost"
                size="sm"
                renderIcon={ArrowRight}
              >
                View all
              </Button>
            </CarbonCard.Action>
          ) : null}
        </CarbonCard.Footer>
      ) : null}
    </CarbonCard>
  );
}

function SummaryState({ state }: { state: SummaryStateKind }): ReactNode {
  return (
    <div className="afy-summary-card__state" role={state === 'unavailable' ? 'alert' : 'status'}>
      <Tag size="sm" type={state === 'restricted' ? 'red' : 'cool-gray'}>
        {state === 'restricted' ? 'Restricted for your role' : 'Temporarily unavailable'}
      </Tag>
    </div>
  );
}

interface SummaryFactProps {
  label: string;
  value: ReactNode;
  detail?: ReactNode;
  flag?: 'high' | 'low' | null;
}

function SummaryFact({ label, value, detail, flag }: SummaryFactProps): ReactNode {
  return (
    <div className={`afy-summary-fact${flag ? ` afy-summary-fact--${flag}` : ''}`}>
      <span className="afy-summary-fact__label">{label}</span>
      <span className="afy-summary-fact__value-row">
        <span className="afy-summary-fact__value">{value}</span>
        {flag ? <Tag size="sm" type="red">{flag === 'high' ? 'High' : 'Low'}</Tag> : null}
      </span>
      {detail ? <span className="afy-summary-fact__detail">{detail}</span> : null}
    </div>
  );
}

interface SummaryRecordProps {
  title: ReactNode;
  meta?: ReactNode;
  status?: ReactNode;
}

function SummaryRecord({ title, meta, status }: SummaryRecordProps): ReactNode {
  return (
    <div className="afy-summary-record">
      <div className="afy-summary-record__main">
        <span className="afy-summary-record__title">{title}</span>
        {meta ? <span className="afy-summary-record__meta">{meta}</span> : null}
      </div>
      {status ? <div className="afy-summary-record__status">{status}</div> : null}
    </div>
  );
}

function PatientDetailsGroup({ title, children }: { title: string; children: ReactNode }): ReactNode {
  return (
    <section className="patient-chart__details-group">
      <h3>{title}</h3>
      <dl className="patient-chart__details-list">{children}</dl>
    </section>
  );
}

function PatientDetail({ label, value }: { label: string; value: ReactNode }): ReactNode {
  return (
    <div>
      <dt>{label}</dt>
      <dd>{value}</dd>
    </div>
  );
}

export function PatientSummary({
  patient,
  chart,
  onEditPatient,
  vitalsEntryHref,
}: Pick<PatientChartProps, 'patient' | 'chart'> & {
  onEditPatient?: () => void;
  vitalsEntryHref?: string;
}): ReactNode {
  const patientId = patient.patientId;
  const results = recentLabResults(chart);
  // Overview shows the most recent slice; the timeline owns the full ledger.
  const activity = buildTimelineEvents(patient, chart).slice(0, 5);
  const latest = latestVital(chart.vitals);
  const activeConditions = chart.conditions.filter((condition) => isActiveProblem(condition.status));
  const currentMedications = chart.medications.filter(isCurrentMedication);
  const activeAllergies = chart.allergies.filter((allergy) => isActiveAllergy(allergy.status));

  const conditionsState: SummaryStateKind | undefined =
    chart.restricted.includes('conditions') ? 'restricted' : chart.unavailable.includes('conditions') ? 'unavailable' : undefined;
  const medicationsState: SummaryStateKind | undefined =
    chart.restricted.includes('medications') ? 'restricted' : chart.unavailable.includes('medications') ? 'unavailable' : undefined;
  const allergiesState: SummaryStateKind | undefined =
    chart.restricted.includes('allergies') ? 'restricted' : chart.unavailable.includes('allergies') ? 'unavailable' : undefined;
  const resultsState: SummaryStateKind | undefined =
    chart.restricted.includes('results') ? 'restricted' : chart.unavailable.includes('results') ? 'unavailable' : undefined;
  const vitalsState: SummaryStateKind | undefined =
    chart.restricted.includes('vitals') ? 'restricted' : chart.unavailable.includes('vitals') ? 'unavailable' : undefined;

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

  function chartHref(tab: string): string {
    return routes.patient.chart(patientId, tab);
  }

  function vitalNumber(value: number | null, unit: string): string {
    return value === null || value === undefined ? '—' : `${value}${unit}`;
  }

  function bloodPressure(systolic: number | null, diastolic: number | null): string {
    if (systolic === null || diastolic === null) return '—';
    return `${systolic}/${diastolic}`;
  }

  function bloodPressureFlag(vital: NonNullable<ReturnType<typeof latestVital>>): 'high' | 'low' | null {
    const flags = [
      vitalFlag('systolicBp', vital.systolicBp),
      vitalFlag('diastolicBp', vital.diastolicBp),
    ];
    if (flags.includes('high')) return 'high';
    if (flags.includes('low')) return 'low';

    return null;
  }

  function severityKind(severity: string | null): 'red' | 'magenta' | 'cool-gray' {
    if (!severity) return 'cool-gray';
    const normalized = severity.toUpperCase();
    if (normalized.includes('SEVERE') || normalized.includes('HIGH') || normalized.includes('LIFE')) {
      return 'red';
    }
    if (normalized.includes('MODERATE') || normalized.includes('MEDIUM')) {
      return 'magenta';
    }
    return 'cool-gray';
  }

  function activityLabel(event: PatientTimelineEvent): string {
    return event.kind
      .split('-')
      .map((part) => part[0]!.toUpperCase() + part.slice(1))
      .join(' ');
  }

  return (
    <div className="afy-patient-summary">
      <Grid className="afy-patient-summary-grid" fullWidth narrow withRowGap>
        <Column lg={5} md={3} sm={4}>
          <section className="patient-chart__summary-details-section" aria-label="Patient details">
            <CarbonCard className="patient-chart__summary-details" density="productive">
              <CarbonCard.Header>
                <CarbonCard.Title>Patient details</CarbonCard.Title>
                {onEditPatient ? (
                  <CarbonCard.Actions>
                    <CarbonCard.Action>
                      <IconButton
                        label="Edit patient information"
                        kind="ghost"
                        size="sm"
                        onClick={onEditPatient}
                      >
                        <Edit />
                      </IconButton>
                    </CarbonCard.Action>
                  </CarbonCard.Actions>
                ) : null}
              </CarbonCard.Header>
              <CarbonCard.Body isFlush>
                <div className="patient-chart__details-grid">
                  <PatientDetailsGroup title="Demographics">
                    <PatientDetail label="Status" value={humanizeClinicalValue(patient.status)} />
                    <PatientDetail label="Date of birth precision" value={humanizeClinicalValue(patient.dobPrecision)} />
                  </PatientDetailsGroup>

                  {patient.primaryPhone || patient.email ? (
                    <PatientDetailsGroup title="Contact">
                      {patient.primaryPhone ? <PatientDetail label="Phone" value={patient.primaryPhone} /> : null}
                      {patient.email ? <PatientDetail label="Email" value={patient.email} /> : null}
                    </PatientDetailsGroup>
                  ) : null}

                  {patient.identifiers.length > 0 || patient.survivingPatientId ? (
                    <PatientDetailsGroup title="Identifiers">
                      {patient.identifiers.map((identifier) => (
                        <PatientDetail
                          key={identifier.id}
                          label={humanizeClinicalValue(identifier.type)}
                          value={`${identifier.value}${identifier.isVerified ? ' · Verified' : ''}`}
                        />
                      ))}
                      {patient.survivingPatientId ? (
                        <PatientDetail label="Surviving patient ID" value={patient.survivingPatientId} />
                      ) : null}
                    </PatientDetailsGroup>
                  ) : null}

                  {addresses.length > 0 ? (
                    <PatientDetailsGroup title="Address">
                      {addresses.map((address) => (
                        <PatientDetail
                          key={address.id}
                          label={humanizeClinicalValue(address.type || 'Address')}
                          value={address.value}
                        />
                      ))}
                    </PatientDetailsGroup>
                  ) : null}

                  {patient.relationships.length > 0 ? (
                    <PatientDetailsGroup title="Relationships">
                      {patient.relationships.map((relationship) => (
                        <PatientDetail
                          key={relationship.id}
                          label={humanizeClinicalValue(relationship.role)}
                          value={[
                            relationship.name,
                            relationship.phone,
                            relationship.label,
                          ].filter(Boolean).join(' · ')}
                        />
                      ))}
                    </PatientDetailsGroup>
                  ) : null}

                  <PatientDetailsGroup title="Registration">
                    {patient.registrationSource ? (
                      <PatientDetail label="Registration source" value={humanizeClinicalValue(patient.registrationSource)} />
                    ) : null}
                    {patient.registeredAt ? (
                      <PatientDetail
                        label="Registered at"
                        value={formatClinicalDateTime(patient.registeredAt)}
                      />
                    ) : null}
                    <PatientDetail label="Facility registrations" value={patient.facilityRegistrationCount} />
                  </PatientDetailsGroup>
                </div>
              </CarbonCard.Body>
            </CarbonCard>
          </section>
        </Column>
        <Column lg={11} md={5} sm={4}>
          <Grid className="afy-summary-card-grid" withRowGap>
        <Column lg={16} md={8} sm={4}>
          <SummaryCard
            title="Vitals"
            count={chart.vitals.length}
            href={chartHref('vitals')}
            state={vitalsState}
            actions={vitalsEntryHref && !vitalsState ? (
              <CarbonCard.Action>
                <Button
                  as={Link}
                  href={vitalsEntryHref}
                  kind="ghost"
                  size="sm"
                  renderIcon={Add}
                >
                  Record vital signs
                </Button>
              </CarbonCard.Action>
            ) : undefined}
            empty={latest === null ? <span>No vitals recorded yet.</span> : null}
          >
            {latest !== null && (
              <div className="afy-summary-facts-grid">
                {latest.temperature !== null ? (
                  <SummaryFact
                    label="Temperature"
                    value={vitalNumber(latest.temperature, '°C')}
                    flag={vitalFlag('temperature', latest.temperature)}
                  />
                ) : null}
                {latest.heartRate !== null ? (
                  <SummaryFact
                    label="Heart rate"
                    value={vitalNumber(latest.heartRate, '/min')}
                    flag={vitalFlag('heartRate', latest.heartRate)}
                  />
                ) : null}
                {latest.respiratoryRate !== null ? (
                  <SummaryFact
                    label="Respiratory rate"
                    value={vitalNumber(latest.respiratoryRate, '/min')}
                    flag={vitalFlag('respiratoryRate', latest.respiratoryRate)}
                  />
                ) : null}
                {(latest.systolicBp !== null || latest.diastolicBp !== null) ? (
                  <SummaryFact
                    label="Blood pressure"
                    value={bloodPressure(latest.systolicBp, latest.diastolicBp)}
                    flag={bloodPressureFlag(latest)}
                  />
                ) : null}
                {latest.oxygenSaturation !== null ? (
                  <SummaryFact
                    label="Oxygen saturation"
                    value={vitalNumber(latest.oxygenSaturation, '%')}
                    flag={vitalFlag('oxygenSaturation', latest.oxygenSaturation)}
                  />
                ) : null}
                {latest.painScore !== null && latest.painScore !== undefined ? (
                  <SummaryFact label="Pain score" value={`${latest.painScore}/10`} />
                ) : null}
                {latest.weight !== null ? (
                  <SummaryFact label="Weight" value={vitalNumber(latest.weight, 'kg')} />
                ) : null}
                {latest.height !== null ? (
                  <SummaryFact label="Height" value={vitalNumber(latest.height, 'cm')} />
                ) : null}
              </div>
            )}
          </SummaryCard>
        </Column>
        <Column lg={8} md={4} sm={4}>
          <SummaryCard
            title="Conditions"
            count={activeConditions.length}
            href={chartHref('conditions')}
            state={conditionsState}
          >
            {conditionsState ? null : activeConditions.length === 0 ? (
              <span>No active problems recorded.</span>
            ) : (
              <div className="afy-summary-record-list">
                {activeConditions.map((condition) => (
                  <SummaryRecord
                    key={condition.id}
                    title={condition.description}
                    meta={condition.onset ? formatClinicalDateTime(condition.onset) ?? condition.onset : null}
                    status={
                      <Tag size="sm" type={clinicalTagType(condition.status)}>
                        {humanizeClinicalValue(condition.status)}
                      </Tag>
                    }
                  />
                ))}
              </div>
            )}
          </SummaryCard>
        </Column>
        <Column lg={8} md={4} sm={4}>
          <SummaryCard
            title="Medications"
            count={currentMedications.length}
            href={chartHref('medications')}
            state={medicationsState}
          >
            {medicationsState ? null : currentMedications.length === 0 ? (
              <span>No current medications recorded.</span>
            ) : (
              <div className="afy-summary-record-list">
                {currentMedications.map((medication) => (
                  <SummaryRecord
                    key={medication.id}
                    title={medication.medication ?? 'Medication'}
                    meta={[medication.dose, medication.frequency].filter(Boolean).join(' · ') || null}
                    status={
                      <Tag size="sm" type={clinicalTagType(medication.status)}>
                        {humanizeClinicalValue(medication.status)}
                      </Tag>
                    }
                  />
                ))}
              </div>
            )}
          </SummaryCard>
        </Column>
        <Column lg={8} md={4} sm={4}>
          <SummaryCard
            title="Allergies"
            count={activeAllergies.length}
            href={chartHref('allergies')}
            state={allergiesState}
          >
            {allergiesState ? null : activeAllergies.length === 0 ? (
              <span>No active allergies recorded.</span>
            ) : (
              <div className="afy-summary-record-list">
                {activeAllergies.map((allergy) => (
                  <SummaryRecord
                    key={allergy.id}
                    title={allergy.substance}
                    meta={[allergy.severity ? humanizeClinicalValue(allergy.severity) : null, allergy.reaction]
                      .filter(Boolean)
                      .join(' · ') || null}
                    status={
                      <Tag size="sm" type={severityKind(allergy.severity)}>
                        {humanizeClinicalValue(allergy.severity ?? '—')}
                      </Tag>
                    }
                  />
                ))}
              </div>
            )}
          </SummaryCard>
        </Column>
        <Column lg={8} md={4} sm={4}>
          <SummaryCard
            title="Recent results"
            count={results.length}
            href={chartHref('lab-results')}
            state={resultsState}
          >
            {resultsState ? null : results.length === 0 ? (
              <span>No released results yet.</span>
            ) : (
              <div className="afy-summary-record-list">
                {results.map(({ id, at, result, status }) => {
                  const value = [result.value, result.unit]
                    .filter((item) => item !== null && item !== '')
                    .join(' ') || '—';
                  const reference = referenceRange(result);
                  return (
                    <SummaryRecord
                      key={id}
                      title={result.parameter}
                      meta={[formatClinicalDateTime(at) ?? at, reference ? `Ref ${reference}` : null]
                        .filter(Boolean)
                        .join(' · ')}
                      status={
                        <div className="afy-summary-record__status-content">
                          <span className="afy-summary-record__result-value">{value}</span>
                          <Tag size="sm" type={isAbnormalFlag(result.flag) ? 'red' : 'green'}>
                            {humanizeClinicalValue(result.flag ?? status)}
                          </Tag>
                        </div>
                      }
                    />
                  );
                })}
              </div>
            )}
          </SummaryCard>
        </Column>
        <Column lg={16} md={8} sm={4}>
          <SummaryCard title="Recent activity" href={chartHref('timeline')}>
            {activity.length === 0 ? (
              <span>No recorded activity yet.</span>
            ) : (
              <ol className="afy-summary-activity-list">
                {activity.map((event) => (
                  <li key={event.id} className="afy-summary-activity-item">
                    <div className="afy-summary-activity-meta">
                      <time className="afy-summary-activity-time" dateTime={event.at}>
                        {formatClinicalDateTime(event.at) ?? event.at}
                      </time>
                      <span className="afy-summary-activity-kind">{activityLabel(event)}</span>
                    </div>
                    <div className="afy-summary-activity-copy">
                      <Link href={event.href}>{event.title}</Link>
                      {event.detail ? <span className="afy-summary-activity-detail">{event.detail}</span> : null}
                    </div>
                  </li>
                ))}
              </ol>
            )}
          </SummaryCard>
        </Column>
          </Grid>
        </Column>
      </Grid>
    </div>
  );
}

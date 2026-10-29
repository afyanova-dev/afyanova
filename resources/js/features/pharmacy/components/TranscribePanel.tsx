import { Button, ComboBox, IconButton, InlineLoading, InlineNotification, Select, SelectItem, TextArea, TextInput } from '@carbon/react';
import { Add, ArrowLeft, Edit, TrashCan } from '@/icons';
import { router, usePage } from '@inertiajs/react';
import { useRef, useState, type MouseEvent, type ReactNode } from 'react';
import { routes } from '@/app/routes';
import {
  MIN_SEARCH_CHARACTERS,
  PatientSearchPhoto,
  usePatientSearch,
  type PatientSearchRow,
} from '@/components/patient-search';
import { DockedPanel } from '@/components/panels';
import { refreshInPlace } from '../pharmacyNavigation';
import type {
  PharmacyPatientAllergy,
  PharmacyTranscribePatient,
  RxItemOption,
} from '../types/pharmacy';

interface BasketLine {
  key: string;
  prescriptionId: string;
  pharmacyOrderId: string;
  catalogItemId: string;
  medicationName: string;
  medicationCode: string;
  dose: string;
  frequency: string;
  frequencyCustom: boolean;
  durationValue: string;
  durationUnit: string;
  quantity: string;
  instructions: string;
  route: string;
  routeCustom: boolean;
  urgency: string;
}

const OTHER_OPTION = 'Other — enter manually';
const FREQUENCY_OPTIONS = [
  'Once',
  'Once daily',
  'BID (twice daily)',
  'TID (three times daily)',
  'QID (four times daily)',
  'Every 4 hours',
  'Every 6 hours',
  'Every 8 hours',
  'Every 12 hours',
  'Weekly',
  'As needed (PRN)',
];
const ROUTE_OPTIONS = [
  'Oral',
  'Topical',
  'Intramuscular',
  'Intravenous',
  'Subcutaneous',
  'Inhalation',
  'Sublingual',
  'Rectal',
  'Ophthalmic',
  'Otic',
  'Nasal',
];

function blankLine(key: string): BasketLine {
  return {
    key,
    prescriptionId: crypto.randomUUID(),
    pharmacyOrderId: crypto.randomUUID(),
    catalogItemId: '',
    medicationName: '',
    medicationCode: '',
    dose: '',
    frequency: '',
    frequencyCustom: false,
    durationValue: '',
    durationUnit: '',
    quantity: '',
    instructions: '',
    route: '',
    routeCustom: false,
    urgency: '',
  };
}

function durationText(line: BasketLine): string {
  if (line.durationValue === '' || line.durationUnit === '') return '';
  const unit = line.durationValue === '1' ? line.durationUnit : `${line.durationUnit}s`;
  return `${line.durationValue} ${unit}`;
}

/**
 * External-paper intake. The active-prescription board is used to find
 * Afyanova prescriptions; this panel creates only a separate record for a
 * prescription issued outside Afyanova and does not require a clinic visit.
 */
export function TranscribePanel({
  patient,
  allergies,
  items,
  canCreatePatient,
  canManageOrders,
  onClose,
}: {
  patient: PharmacyTranscribePatient | null;
  allergies: PharmacyPatientAllergy[];
  items: RxItemOption[];
  canCreatePatient: boolean;
  canManageOrders: boolean;
  onClose: () => void;
}): ReactNode {
  const { url } = usePage();
  const [term, setTerm] = useState('');
  const [selectedPatientSummary, setSelectedPatientSummary] = useState<PatientSearchRow | null>(null);
  const [prescriber, setPrescriber] = useState('');
  const [externalIntakeId, setExternalIntakeId] = useState(() => crypto.randomUUID());
  const [note, setNote] = useState('');
  const [lines, setLines] = useState<BasketLine[]>([]);
  const [draft, setDraft] = useState<BasketLine | null>(null);
  const [submitting, setSubmitting] = useState(false);
  const [completed, setCompleted] = useState(false);
  const [submitError, setSubmitError] = useState<string | null>(null);
  const [creatingPatient, setCreatingPatient] = useState(false);
  const [registeringPatient, setRegisteringPatient] = useState(false);
  const [givenName, setGivenName] = useState('');
  const [familyName, setFamilyName] = useState('');
  const [sex, setSex] = useState('UNKNOWN');
  const [phone, setPhone] = useState('');
  const lineCounter = useRef(0);

  const { rows, loading, errorMessage } = usePatientSearch(term, {
    enabled: patient === null,
    perPage: 5,
    fetchWhenEmpty: false,
  });
  const showResults = patient === null && term.trim().length >= MIN_SEARCH_CHARACTERS;

  const canSubmit = patient !== null
    && prescriber.trim() !== ''
    && lines.length > 0
    && canManageOrders
    && !submitting
    && !completed;

  function backToSearch(): void {
    setLines([]);
    setDraft(null);
    setSubmitError(null);
    setSelectedPatientSummary(null);
    setPrescriber('');
    setExternalIntakeId(crypto.randomUUID());
    setNote('');
    setTerm('');
    setCreatingPatient(false);
    setRegisteringPatient(false);
    refreshInPlace(url, { patient_id: null, view: null, external_intake: null });
  }

  function saveLine(event: MouseEvent<HTMLButtonElement>): void {
    event.preventDefault();
    if (!draft || draft.catalogItemId === '') return;
    setLines((current) => {
      const index = current.findIndex((line) => line.key === draft.key);
      if (index === -1) return [...current, draft];
      return current.map((line, position) => (position === index ? draft : line));
    });
    setDraft(null);
  }

  function submitPrescription(): void {
    if (!patient) return;
    router.post(routes.workspaces.pharmacyTranscribe, {
      patient_id: patient.patientId,
      source: 'EXTERNAL_PAPER',
      prescriber_name: prescriber.trim(),
      transcription_note: note.trim() === '' ? null : note.trim(),
      external_intake_id: externalIntakeId,
      lines: lines.map((line) => ({
        prescription_id: line.prescriptionId,
        pharmacy_order_id: line.pharmacyOrderId,
        inventory_item_id: line.catalogItemId,
        dose: line.dose.trim() === '' ? null : line.dose.trim(),
        frequency: line.frequency.trim() === '' ? null : line.frequency.trim(),
        duration: durationText(line) || null,
        prescribed_quantity: line.quantity.trim() === '' ? null : line.quantity.trim(),
        dosing_instruction: line.instructions.trim() === '' ? null : line.instructions.trim(),
        route: line.route.trim() === '' ? null : line.route.trim(),
        urgency: line.urgency.trim() === '' ? null : line.urgency.trim(),
      })),
    }, {
      preserveScroll: true,
      preserveState: true,
      onSuccess: () => {
        setSubmitting(false);
        setCompleted(true);
      },
      onError: (errors) => {
        setSubmitError(Object.values(errors).join(' '));
        setSubmitting(false);
      },
    });
  }

  function registerPatient(): void {
    if (givenName.trim() === '' || familyName.trim() === '' || registeringPatient) return;
    setRegisteringPatient(true);
    router.post(routes.workspaces.pharmacyExternalPatient, {
      given_name: givenName.trim(),
      family_name: familyName.trim(),
      sex,
      primary_phone: phone.trim() === '' ? null : phone.trim(),
    }, {
      preserveScroll: true,
      preserveState: true,
      onError: () => setRegisteringPatient(false),
    });
  }

  function submit(event: React.FormEvent): void {
    event.preventDefault();
    if (completed) {
      onClose();
      return;
    }
    if (!canSubmit) return;
    setSubmitError(null);
    setSubmitting(true);
    submitPrescription();
  }

  function setDraftField(
    field: keyof Omit<BasketLine, 'key' | 'catalogItemId' | 'medicationName' | 'medicationCode' | 'frequencyCustom' | 'routeCustom'>,
    value: string,
  ): void {
    setDraft((current) => (current ? { ...current, [field]: value } : current));
  }

  function nextLineKey(): string {
    lineCounter.current += 1;
    return `line-${lineCounter.current}`;
  }

  const medicationOptions = items.filter((item) => item.active && item.itemType === 'MEDICATION');
  const selectedMedication = medicationOptions.find((item) => item.itemId === draft?.catalogItemId) ?? null;
  const durationIncomplete = draft !== null
    && ((draft.durationValue === '') !== (draft.durationUnit === ''));

  return (
    <DockedPanel
      heading="External prescription intake"
      headerActions={patient && !completed ? (
        <Button kind="ghost" size="sm" type="button" onClick={backToSearch}>
          Change patient
        </Button>
      ) : null}
      primaryLabel={completed ? 'Done' : submitting ? 'Recording…' : lines.length > 0 ? `Record and queue ${lines.length} medicine${lines.length === 1 ? '' : 's'}` : 'Record prescription'}
      primaryDisabled={completed ? false : !canSubmit}
      secondaryLabel={completed ? 'Close' : 'Cancel'}
      footerVisible={patient !== null && draft === null}
      contentClassName="pharmacy-transcribe-panel"
      actions={draft ? (
        <>
          <Button size="lg" renderIcon={TrashCan} kind="secondary" type="button" onClick={() => setDraft(null)}>
            Discard drug
          </Button>
          <Button size="lg" renderIcon={Add}
            kind="primary"
            type="button"
            disabled={draft.catalogItemId === '' || durationIncomplete}
            onClick={saveLine}
          >
            Add to basket
          </Button>
        </>
      ) : creatingPatient ? (
        <>
          <Button size="lg" renderIcon={Add}
            kind="primary"
            type="button"
            disabled={registeringPatient || givenName.trim() === '' || familyName.trim() === ''}
            onClick={registerPatient}
          >
            {registeringPatient ? 'Creating…' : 'Create record and continue'}
          </Button>
          <Button size="lg" renderIcon={ArrowLeft} kind="secondary" type="button" onClick={() => setCreatingPatient(false)}>
            Back to search
          </Button>
        </>
      ) : null      }
      onClose={onClose}
      onSubmit={submit}
    >
      {completed ? (
        <InlineNotification
          kind="success"
          lowContrast
          hideCloseButton
          title={`${lines.length} medicine${lines.length === 1 ? '' : 's'} recorded and queued`}
          subtitle="The pharmacy order is waiting for pharmacist validation. Choose Done to review the first queued order. No stock is deducted until dispensing."
        />
      ) : !patient ? (
        <>
          {!creatingPatient ? (
            <>
              <TextInput
                id="pharmacy-transcribe-search"
                labelText="Find a patient"
                helperText="Search is limited to patients registered at this facility."
                value={term}
                onChange={(event) => setTerm(event.currentTarget.value)}
                placeholder="Name, patient number, or phone"
              />
              {showResults ? (
                loading && rows.length === 0 ? (
                  <InlineLoading status="active" description="Searching patients" />
                ) : errorMessage !== null ? (
                  <p role="alert">{errorMessage}</p>
                ) : rows.length > 0 ? (
                  <ul className="appointment-booking__patient-results" aria-label="Patient search results">
                    {rows.map((row) => (
                      <li key={row.patientId}>
                        {row.registeredAtActiveFacility === false ? (
                          <div className="appointment-booking__patient-result appointment-booking__patient-result--disabled" aria-disabled>
                            <PatientSearchPhoto row={row} />
                            <span style={{ minInlineSize: 0, display: 'grid', gap: '0.125rem', textAlign: 'start' }}>
                              <strong style={{ overflow: 'hidden', textOverflow: 'ellipsis' }}>{row.displayName}</strong>
                              <span>
                                {[row.sex, row.age].filter(Boolean).join(' · ')}
                                {row.formattedDob ? ` · DOB ${row.formattedDob}` : ''}
                              </span>
                              <span>{row.patientNumber} · Registered at another facility</span>
                            </span>
                            <div style={{ marginInlineStart: 'auto', display: 'flex', gap: '0.5rem' }}>
                              {canCreatePatient ? (
                                <Button kind="secondary" size="sm" onClick={() => setCreatingPatient(true)}>Create local pharmacy record</Button>
                              ) : (
                                <small>Request registration for this patient at this facility.</small>
                              )}
                            </div>
                          </div>
                        ) : (
                          <button
                            className="appointment-booking__patient-result"
                            type="button"
                            aria-label={`Select ${row.displayName}, ${row.patientNumber}`}
                            onClick={() => {
                              setSelectedPatientSummary(row);
                              refreshInPlace(url, { patient_id: row.patientId, external_intake: '1' });
                            }}
                          >
                            <PatientSearchPhoto row={row} />
                            <span style={{ minInlineSize: 0, display: 'grid', gap: '0.125rem', textAlign: 'start' }}>
                              <strong style={{ overflow: 'hidden', textOverflow: 'ellipsis' }}>{row.displayName}</strong>
                              <span>
                                {[row.sex, row.age].filter(Boolean).join(' · ')}
                                {row.formattedDob ? ` · DOB ${row.formattedDob}` : ''}
                              </span>
                              <span>{row.patientNumber}</span>
                            </span>
                          </button>
                        )}
                      </li>
                    ))}
                  </ul>
                ) : (
                  <>
                    <p>No patients found for that search.</p>
                    {canCreatePatient ? (
                      <Button size="lg" renderIcon={Add}
                        kind="secondary"
                        type="button"
                        className="afyanova-pharmacy-create-patient"
                        onClick={() => setCreatingPatient(true)}
                      >
                        Create minimal pharmacy patient record
                      </Button>
                    ) : (
                      <p>Ask an authorized registration user to create a pharmacy-only patient record.</p>
                    )}
                  </>
                )
              ) : null}
            </>
          ) : (
            <section aria-label="Create minimal pharmacy patient record" style={{ display: 'grid', gap: 'var(--cds-spacing-04, 0.75rem)' }}>
              <p>This creates a provisional patient identity at this facility only. It does not create a clinic visit.</p>
              <TextInput id="pharmacy-patient-given-name" labelText="Given name" value={givenName} maxLength={120} onChange={(event) => setGivenName(event.currentTarget.value)} required />
              <TextInput id="pharmacy-patient-family-name" labelText="Family name" value={familyName} maxLength={120} onChange={(event) => setFamilyName(event.currentTarget.value)} required />
              <Select id="pharmacy-patient-sex" labelText="Sex" value={sex} onChange={(event) => setSex(event.currentTarget.value)}>
                <SelectItem value="UNKNOWN" text="Unknown" />
                <SelectItem value="FEMALE" text="Female" />
                <SelectItem value="MALE" text="Male" />
              </Select>
              <TextInput id="pharmacy-patient-phone" labelText="Phone (optional)" value={phone} maxLength={40} onChange={(event) => setPhone(event.currentTarget.value)} />
            </section>
          )}
        </>
      ) : draft ? (
        <>
          <PatientContext
            patient={patient}
            summary={selectedPatientSummary}
            allergies={allergies}
          />
          <section className="pharmacy-drug-draft__section" aria-labelledby="pharmacy-drug-catalog-heading">
            <h3 id="pharmacy-drug-catalog-heading">Drug</h3>
            {medicationOptions.length === 0 ? (
              <p role="alert">No active medicines in the catalog.</p>
            ) : (
              <>
                <ComboBox<RxItemOption>
                  id="pharmacy-transcribe-medication-search"
                  titleText="Search medication catalog"
                  helperText="Search by name or code"
                  items={medicationOptions}
                  selectedItem={selectedMedication}
                  itemToString={(item) => item ? `${item.name} · ${item.code}` : ''}
                  onInputChange={(value) => {
                    setDraft((current) => {
                      if (!current || value === `${current.medicationName} · ${current.medicationCode}`) {
                        return current;
                      }

                      return {
                        ...current,
                        catalogItemId: '',
                        medicationName: '',
                        medicationCode: '',
                      };
                    });
                  }}
                  onChange={({ selectedItem }) => {
                    setDraft((current) => selectedItem && current ? {
                      ...current,
                      catalogItemId: selectedItem.itemId,
                      medicationName: selectedItem.name,
                      medicationCode: selectedItem.code,
                    } : current);
                  }}
                  shouldFilterItem={({ item, inputValue }) => {
                    const query = (inputValue ?? '').trim().toLocaleLowerCase();
                    return query === ''
                      || `${item.name} ${item.code}`.toLocaleLowerCase().includes(query);
                  }}
                  placeholder="Type medicine name or catalog code"
                  required
                />
                {draft.catalogItemId !== '' && selectedMedication ? (
                  <div className="pharmacy-drug-draft__selected" role="status">
                    <strong>{draft.medicationName}</strong>
                    <span>{draft.medicationCode}</span>
                  </div>
                ) : null}
              </>
            )}
          </section>
          <section className="pharmacy-drug-draft__section" aria-labelledby="pharmacy-drug-directions-heading">
            <h3 id="pharmacy-drug-directions-heading">Dosage instructions</h3>
            <div className="pharmacy-drug-draft__grid">
              <TextInput
                id="pharmacy-transcribe-dose"
                labelText="Dose (optional)"
                value={draft.dose}
                maxLength={120}
                onChange={(event) => setDraftField('dose', event.currentTarget.value)}
              />
              <div className="pharmacy-drug-draft__frequency">
                <ComboBox
                  id="pharmacy-transcribe-frequency"
                  titleText="Frequency (optional)"
                  items={[...FREQUENCY_OPTIONS, OTHER_OPTION]}
                  selectedItem={draft.frequencyCustom ? OTHER_OPTION : draft.frequency || null}
                  itemToString={(item) => item ?? ''}
                  onChange={({ selectedItem }) => {
                    setDraft((current) => current ? {
                      ...current,
                      frequency: selectedItem === OTHER_OPTION ? '' : selectedItem ?? '',
                      frequencyCustom: selectedItem === OTHER_OPTION,
                    } : current);
                  }}
                  shouldFilterItem={({ item, inputValue }) => (
                    item === OTHER_OPTION
                    || item.toLocaleLowerCase().includes((inputValue ?? '').trim().toLocaleLowerCase())
                  )}
                />
              </div>
              {draft.frequencyCustom ? (
                <TextInput
                  id="pharmacy-transcribe-frequency-custom"
                  className="pharmacy-drug-draft__custom-frequency"
                  labelText="Custom frequency"
                  helperText="Enter the frequency as written on the prescription."
                  value={draft.frequency}
                  maxLength={120}
                  onChange={(event) => setDraftField('frequency', event.currentTarget.value)}
                />
              ) : null}
              <div className="pharmacy-drug-draft__duration">
                <TextInput
                  id="pharmacy-transcribe-duration"
                  labelText="Duration (optional)"
                  type="number"
                  min="1"
                  step="1"
                  value={draft.durationValue}
                  onChange={(event) => setDraftField('durationValue', event.currentTarget.value)}
                />
                <Select
                  id="pharmacy-transcribe-duration-unit"
                  labelText="Unit"
                  value={draft.durationUnit}
                  onChange={(event) => setDraftField('durationUnit', event.currentTarget.value)}
                >
                  <SelectItem value="" text="Select a unit" />
                  <SelectItem value="hour" text="Hours" />
                  <SelectItem value="day" text="Days" />
                  <SelectItem value="week" text="Weeks" />
                  <SelectItem value="month" text="Months" />
                </Select>
              </div>
              <TextInput
                id="pharmacy-transcribe-quantity"
                labelText="Prescribed quantity (optional)"
                value={draft.quantity}
                maxLength={60}
                onChange={(event) => setDraftField('quantity', event.currentTarget.value)}
              />
              <div>
                <ComboBox
                  id="pharmacy-transcribe-route"
                  titleText="Route (optional)"
                  items={[...ROUTE_OPTIONS, OTHER_OPTION]}
                  selectedItem={draft.routeCustom ? OTHER_OPTION : draft.route || null}
                  itemToString={(item) => item ?? ''}
                  onChange={({ selectedItem }) => {
                    setDraft((current) => current ? {
                      ...current,
                      route: selectedItem === OTHER_OPTION ? '' : selectedItem ?? '',
                      routeCustom: selectedItem === OTHER_OPTION,
                    } : current);
                  }}
                  shouldFilterItem={({ item, inputValue }) => (
                    item === OTHER_OPTION
                    || item.toLocaleLowerCase().includes((inputValue ?? '').trim().toLocaleLowerCase())
                  )}
                />
                {draft.routeCustom ? (
                  <TextInput
                    id="pharmacy-transcribe-route-custom"
                    labelText="Custom route"
                    helperText="Enter the route as written on the prescription."
                    value={draft.route}
                    maxLength={60}
                    onChange={(event) => setDraftField('route', event.currentTarget.value)}
                  />
                ) : null}
              </div>
              <TextInput
                id="pharmacy-transcribe-urgency"
                labelText="Urgency (optional)"
                value={draft.urgency}
                maxLength={20}
                onChange={(event) => setDraftField('urgency', event.currentTarget.value)}
              />
            </div>
            <TextArea
              id="pharmacy-transcribe-instructions"
              labelText="Additional patient instructions (optional)"
              value={draft.instructions}
              maxLength={1000}
              rows={3}
              onChange={(event) => setDraftField('instructions', event.currentTarget.value)}
            />
            {durationIncomplete ? (
              <p role="alert">Enter both a duration and unit, or leave both blank.</p>
            ) : null}
          </section>
        </>
      ) : (
        <>
          {!canManageOrders ? (
            <InlineNotification
              kind="error"
              lowContrast
              hideCloseButton
              title="Order-management access required"
              subtitle="Your account can transcribe prescriptions but cannot create pharmacy orders. Ask an administrator to grant pharmacy order-management access."
            />
          ) : null}
          {submitError ? (
            <InlineNotification
              kind="error"
              lowContrast
              title="Intake was not completed"
              subtitle={submitError}
            />
          ) : null}
          <PatientContext
            patient={patient}
            summary={selectedPatientSummary}
            allergies={allergies}
          />
          <TextInput
            id="pharmacy-transcribe-prescriber"
            labelText="Prescriber name"
            value={prescriber}
            maxLength={200}
            onChange={(event) => setPrescriber(event.currentTarget.value)}
            required
          />
          <TextArea
            id="pharmacy-transcribe-note"
            labelText="Transcription note (optional)"
            value={note}
            maxLength={1000}
            rows={3}
            onChange={(event) => setNote(event.currentTarget.value)}
          />
          <section className="pharmacy-transcribe-basket" aria-label="Prescription drug basket">
            <div className="pharmacy-transcribe-basket__header">
              <h3>Prescription medicines ({lines.length})</h3>
              <Button
                kind="ghost"
                size="sm"
                type="button"
                aria-label="Add drug"
                renderIcon={(props) => <Add size={16} {...props} />}
                onClick={() => setDraft(blankLine(nextLineKey()))}
              >
                Add
              </Button>
            </div>
            {lines.length > 0 ? (
              <ul aria-label="Prescription medicines to record">
                {lines.map((line) => (
                  <li key={line.key} className="pharmacy-transcribe-basket__item">
                    <div className="pharmacy-transcribe-basket__details">
                      <strong>{line.medicationName}</strong>
                      <p>
                        {[line.dose, line.frequency, durationText(line), line.quantity ? `Qty ${line.quantity}` : '']
                          .filter(Boolean)
                          .join(' · ') || 'Directions not recorded'}
                      </p>
                      {line.route || line.instructions ? (
                        <p>
                          {[line.route, line.instructions].filter(Boolean).join(' · ')}
                        </p>
                      ) : null}
                    </div>
                    <div className="pharmacy-transcribe-basket__item-actions">
                      <IconButton
                        kind="ghost"
                        size="sm"
                        label={`Edit ${line.medicationName}`}
                        onClick={() => setDraft(line)}
                      >
                        <Edit />
                      </IconButton>
                      <IconButton
                        kind="ghost"
                        size="sm"
                        label={`Remove ${line.medicationName}`}
                        onClick={() => setLines((current) => current.filter((item) => item.key !== line.key))}
                      >
                        <TrashCan />
                      </IconButton>
                    </div>
                  </li>
                ))}
              </ul>
            ) : (
              <p className="pharmacy-transcribe-basket__empty">No medicines added yet. Choose Add to transcribe a medicine.</p>
            )}
          </section>
        </>
      )}
    </DockedPanel>
  );
}

function PatientIdentity({
  patient,
  summary,
}: {
  patient: PharmacyTranscribePatient;
  summary: PatientSearchRow | null;
}): ReactNode {
  const demographics = [
    summary?.sex,
    summary?.age,
    summary?.formattedDob ? `DOB ${summary.formattedDob}` : null,
  ].filter(Boolean);

  return (
    <div className="pharmacy-patient-identity">
      <PatientSearchPhoto row={summary ?? { displayName: patient.displayName, photoUrl: null }} />
      <div className="pharmacy-patient-identity__details">
        <strong className="pharmacy-patient-identity__name">{patient.displayName}</strong>
        <span className="pharmacy-patient-identity__number">{patient.patientNumber}</span>
        {demographics.length > 0 ? (
          <span className="pharmacy-patient-identity__demographics">{demographics.join(' · ')}</span>
        ) : null}
      </div>
    </div>
  );
}

function PatientContext({
  patient,
  summary,
  allergies,
}: {
  patient: PharmacyTranscribePatient;
  summary: PatientSearchRow | null;
  allergies: PharmacyPatientAllergy[];
}): ReactNode {
  return (
    <section aria-label="Selected patient" className="pharmacy-patient-context">
      <PatientIdentity patient={patient} summary={summary} />
      {allergies.length > 0 ? (
        <InlineNotification
          kind="warning"
          title="Recorded allergies"
          subtitle={allergies.map((allergy) => (
            `${allergy.substanceName} (${allergy.severity}${allergy.reaction ? `, ${allergy.reaction}` : ''})`
          )).join('; ')}
          lowContrast
          role="alert"
        />
      ) : (
        <p className="pharmacy-patient-context__no-allergies">No allergy details provided.</p>
      )}
    </section>
  );
}

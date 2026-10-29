import { Button, InlineLoading, InlineNotification, Search } from '@carbon/react';
import { router } from '@inertiajs/react';
import { useEffect, useRef, useState, type FormEvent, type ReactNode } from 'react';
import { DockedPanel } from '@/components/panels';
import { FormErrors } from '@/components/ui';
import { PatientSearchPhoto, PatientSearchText } from '@/components/patient-search/PatientSearchRow';
import { usePatientSearch } from '@/components/patient-search/usePatientSearch';
import { routes } from '@/app/routes';
import { permissions, useCan } from '@/lib/authorization';
import {
  CheckInFields,
  useCheckInForm,
  type CheckInProtocolProcedure,
  type CheckInQueueOption,
  type CheckInTarget,
} from './CheckInAction';
import { Add, Checkmark, Close, Renew } from '@/icons';

const EMPTY_WALK_IN_TARGET: CheckInTarget = { patientId: '', displayName: '', patientNumber: '' };

/**
 * Single walk-in workspace: search → select → form stages swap inside one
 * mounted `DockedPanel`, so the panel never closes and reopens mid-flow.
 * The form stage reuses the canonical check-in state and fields (same
 * validation, same endpoint as `CheckInAction`), and its footer is the
 * panel's own footer — identical structure to the patient-profile flow.
 */
function WalkInWorkspace({
  availableQueues,
  protocolProcedures,
  canRegister,
  registerHref,
  initialTarget = null,
  onClose,
  onDirtyChange,
}: {
  availableQueues: CheckInQueueOption[];
  protocolProcedures: CheckInProtocolProcedure[];
  canRegister: boolean;
  registerHref: string;
  initialTarget?: CheckInTarget | null;
  onClose: () => void;
  /** Reports dirty state so the parent can guard workspace switches. */
  onDirtyChange?: (dirty: boolean) => void;
}): ReactNode {
  const [target, setTarget] = useState<CheckInTarget | null>(initialTarget);
  const [query, setQuery] = useState('');
  const [completedPatient, setCompletedPatient] = useState<CheckInTarget | null>(null);
  const { rows, loading, isPending, errorMessage } = usePatientSearch(query, {
    enabled: target === null && query.trim().length >= 3,
    perPage: 5,
    fetchWhenEmpty: false,
  });
  const form = useCheckInForm(target ?? EMPTY_WALK_IN_TARGET);
  const formRef = useRef(form);
  formRef.current = form;

  useEffect(() => {
    onDirtyChange?.(form.isDirty);
  }, [form.isDirty, onDirtyChange]);

  // Name fragments need at least 3 characters — shorter input never
  // searches, never reports "no patients", and never offers registration.
  const canSearch = query.trim().length >= 3;
  const searching = loading || isPending;
  const showNoResults = target === null && canSearch && !searching && errorMessage === null && rows.length === 0;

  // The form state outlives stage switches within one opening; keep the
  // patient binding in sync when a (different) patient is picked.
  useEffect(() => {
    const patientId = target?.patientId ?? '';
    formRef.current.setData('patient_id', patientId);
    formRef.current.setDefaults('patient_id', patientId);
  }, [target?.patientId]);

  function submit(event: FormEvent): void {
    event.preventDefault();
    form.post(routes.workspaces.receptionCheckIn, {
      preserveScroll: true,
      onSuccess: () => {
        setCompletedPatient(target);
        form.reset();
        setTarget(null);
        setQuery('');
      },
    });
  }

  return (
    <DockedPanel
      heading="Check in walk-in patient"
      width="min(28rem, 100%)"
      onClose={onClose}
      onSubmit={target ? submit : undefined}
      primaryLabel={target ? (form.processing ? 'Checking in…' : 'Check in') : undefined}
      primaryDisabled={form.processing || form.data.encounter_type === '' || Boolean(target?.activeEncounter)}
      secondaryLabel={target ? 'Cancel' : undefined}
      onSecondary={target ? onClose : undefined}
      actions={completedPatient ? (
        <>
          <Button size="lg" renderIcon={Renew}
            kind="secondary"
            type="button"
            onClick={() => {
              setCompletedPatient(null);
              setQuery('');
            }}
          >
            Check in another patient
          </Button>
          <Button size="lg" renderIcon={Checkmark} kind="primary" type="button" onClick={onClose}>
            Done
          </Button>
        </>
      ) : showNoResults && canRegister ? (
        <>
          <Button size="lg" renderIcon={Close} kind="secondary" type="button" onClick={onClose}>
            Cancel
          </Button>
          <Button size="lg" renderIcon={Add}
            kind="primary"
            type="button"
            onClick={() => router.visit(registerHref)}
          >
            Register new patient
          </Button>
        </>
      ) : undefined}
    >
      {completedPatient ? (
        <section aria-label="Check-in confirmation">
          <InlineNotification
            kind="success"
            lowContrast
            hideCloseButton
            title="Check-in complete"
            subtitle={`${completedPatient.displayName} (${completedPatient.patientNumber}) is now on the Front Desk roster.`}
          />
        </section>
      ) : target === null ? (
        <>
          <Search
            id="front-desk-walk-in-search"
            labelText="Search patients"
            placeholder="Name or patient number"
            value={query}
            onChange={(event) => setQuery(event.currentTarget.value)}
          />
          {searching ? <InlineLoading description="Searching patients" /> : null}
          {!searching && errorMessage !== null ? <p role="alert">{errorMessage}</p> : null}
          {query.trim() === '' && !searching ? (
            <p>
              Start typing to find the walk-in patient.
            </p>
          ) : null}
          {query.trim() !== '' && !canSearch && !searching ? (
            <p role="status">Type at least 3 characters to search.</p>
          ) : null}
          {showNoResults ? (
            <p role="status">
              No matching patient found. Check the spelling or try the patient number before registering a new record.
            </p>
          ) : null}
          {canSearch && !searching && errorMessage === null && rows.length > 0 ? (
            <ul style={{ listStyle: 'none', margin: '1rem 0 0', padding: 0 }}>
              {rows.map((row) => (
                <li key={row.patientId} style={{ marginBlockEnd: '0.25rem' }}>
                  <button
                    type="button"
                    onClick={() => {
                      setTarget({
                        patientId: row.patientId,
                        displayName: row.displayName,
                        patientNumber: row.patientNumber,
                        sex: row.sex,
                        formattedDob: row.formattedDob,
                        age: row.age,
                        photoUrl: row.photoUrl,
                        activeEncounter: row.activeEncounter,
                      });
                      setQuery('');
                    }}
                    style={{
                      display: 'flex',
                      inlineSize: '100%',
                      alignItems: 'center',
                      gap: '0.75rem',
                      padding: '0.5rem',
                      textAlign: 'start',
                    }}
                  >
                    <PatientSearchPhoto row={row} />
                    <PatientSearchText row={row} />
                  </button>
                </li>
              ))}
            </ul>
          ) : null}
        </>
      ) : (
        <>
          <FormErrors errors={form.errors} fields={[
            'patient_id', 'appointment_id', 'encounter_type', 'department_id', 'unit_id',
            'queue_id', 'priority', 'protocol_procedure_definition_id',
            'protocol_procedure_indication', 'return_to',
          ]} />
          <CheckInFields
            form={form}
            target={target}
            availableQueues={availableQueues}
            protocolProcedures={protocolProcedures}
          />
          {form.processing ? <InlineLoading description="Checking in" /> : null}
        </>
      )}
    </DockedPanel>
  );
}

/**
 * Arrival actions live in the page flow so reception's primary task is
 * explicit, labelled, and easy to find without relying on an icon rail.
 */
export function FrontDeskQuickActions({
  availableQueues,
  protocolProcedures = [],
  registerHref,
  initialTarget = null,
  walkinOpen,
  onToggleWalkin,
  onCloseWalkin,
  onDirtyChange,
}: {
  availableQueues: CheckInQueueOption[];
  protocolProcedures?: CheckInProtocolProcedure[];
  registerHref: string;
  /** Registration return (?patient_id=…): open check-in-ready on arrival. */
  initialTarget?: CheckInTarget | null;
  /** Controlled by the board's single workspace — no local open state. */
  walkinOpen: boolean;
  onToggleWalkin: () => void;
  onCloseWalkin: () => void;
  /** Reports dirty state so the parent can guard workspace switches. */
  onDirtyChange?: (dirty: boolean) => void;
}): ReactNode {
  const can = useCan();
  const canWalkIn = can(permissions.encounter.create) && can(permissions.patient.read);
  const canRegister = can(permissions.patient.create);

  if (!canWalkIn && !canRegister) return null;

  return (
    <>
      <div className="front-desk-header-actions">
        {canWalkIn ? (
          <Button
            kind="primary"
            size="lg" renderIcon={Checkmark}
            onClick={onToggleWalkin}
            aria-expanded={walkinOpen}
          >
            Check in walk-in patient
          </Button>
        ) : null}
        {canRegister && !canWalkIn ? (
          <Button kind="tertiary" size="lg" renderIcon={Add} onClick={() => router.visit(registerHref)}>
            Register patient
          </Button>
        ) : null}
      </div>
      {walkinOpen ? (
        <WalkInWorkspace
          key={initialTarget ? `walkin:${initialTarget.patientId}` : 'walkin:search'}
          availableQueues={availableQueues}
          protocolProcedures={protocolProcedures}
          canRegister={canRegister}
          registerHref={registerHref}
          initialTarget={initialTarget}
          onClose={onCloseWalkin}
          onDirtyChange={onDirtyChange}
        />
      ) : null}
    </>
  );
}
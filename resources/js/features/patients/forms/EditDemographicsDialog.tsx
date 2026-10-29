import { useForm } from '@inertiajs/react';
import { Button, Select, SelectItem, TextArea, TextInput } from '@carbon/react';
import { useEffect, useRef, useState, type FormEvent, type ReactNode } from 'react';
import { FormErrors } from '@/components/ui';
import { DockedPanel } from '@/components/panels';
import { useCan } from '@/lib/authorization';
import { routes } from '@/app/routes';
import type { PatientChartPatient } from '@/features/patients/types/chart';

type EditableSex = 'MALE' | 'FEMALE' | 'UNKNOWN' | '';
type EditablePrecision = 'KNOWN' | 'ESTIMATED' | 'UNKNOWN' | '';

interface DemographicsForm {
  given_name: string;
  family_name: string;
  middle_name: string;
  sex: EditableSex;
  dob_precision: EditablePrecision;
  date_of_birth: string;
  primary_phone: string;
  email: string;
  reason: string;
}

type DirtyKey = Exclude<keyof DemographicsForm, 'reason'>;

const SENSITIVE: ReadonlySet<DirtyKey> = new Set([
  'given_name',
  'family_name',
  'middle_name',
  'sex',
  'date_of_birth',
  'dob_precision',
]);

function initialFor(patient: PatientChartPatient): DemographicsForm {
  return {
    given_name: patient.givenName,
    family_name: patient.familyName,
    middle_name: patient.middleName ?? '',
    sex: patient.sex === 'MALE' || patient.sex === 'FEMALE' || patient.sex === 'UNKNOWN' ? patient.sex : '',
    dob_precision:
      patient.dobPrecision === 'KNOWN' || patient.dobPrecision === 'ESTIMATED' || patient.dobPrecision === 'UNKNOWN'
        ? patient.dobPrecision
        : '',
    date_of_birth: patient.dateOfBirth ?? '',
    primary_phone: patient.primaryPhone ?? '',
    email: patient.email ?? '',
    reason: '',
  };
}

/**
 * Demographics edit for the chart Overview section (Rule: PATCH dirty fields
 * only; `reason` appears iff a sensitive field changed — the backend decides
 * whether it is required). Rendered only for `patient.patients.update`.
 */
export function EditDemographicsDialog({
  patient,
  renderTrigger,
  open: controlledOpen,
  onClose,
  offsetInlineEnd,
  onDirtyChange,
}: {
  patient: PatientChartPatient;
  renderTrigger?: (open: () => void) => ReactNode;
  open?: boolean;
  onClose?: () => void;
  /** Right offset reserving the chart action rail beside the panel. */
  offsetInlineEnd?: string;
  /** Reports dirty state so the parent can guard workspace switches. */
  onDirtyChange?: (dirty: boolean) => void;
}): ReactNode {
  const can = useCan();
  const [internalOpen, setInternalOpen] = useState(false);
  const open = controlledOpen ?? internalOpen;
  const [baseline] = useState<DemographicsForm>(() => initialFor(patient));
  const payloadRef = useRef<Partial<DemographicsForm>>({});
  const { data, setData, patch, processing, errors, reset, transform } = useForm<DemographicsForm>(baseline);

  const dirty = (Object.keys(baseline) as Array<keyof DemographicsForm>).filter(
    (key): key is DirtyKey => key !== 'reason' && data[key] !== baseline[key],
  );
  const touchesSensitive = dirty.some((key) => SENSITIVE.has(key));
  const isDirty = dirty.length > 0;

  useEffect(() => {
    onDirtyChange?.(isDirty);
  }, [isDirty, onDirtyChange]);

  if (!can('patient.patients.update')) return null;

  function close(): void {
    reset();
    if (controlledOpen === undefined) setInternalOpen(false);
    onClose?.();
  }

  function submit(event: FormEvent): void {
    event.preventDefault();
    // Dirty-only payload: the backend treats every present key as an
    // intended change (sensitive keys demand `reason`), so unchanged values
    // must not be sent. transform() is re-set on every submit.
    payloadRef.current = Object.fromEntries(
      dirty.map((key) => [key, data[key]]),
    ) as Partial<DemographicsForm>;
    if (touchesSensitive) payloadRef.current.reason = data.reason;
    transform(() => payloadRef.current as DemographicsForm);
    patch(routes.patient.update(patient.patientId));
  }

  return (
    <>
      {renderTrigger ? (
        renderTrigger(() => setInternalOpen(true))
      ) : controlledOpen === undefined ? (
        <Button kind="ghost" size="sm" onClick={() => setInternalOpen(true)}>
          Edit demographics
        </Button>
      ) : null}
      {/* Mounted only when open: a closed dialog must not leak headings
          into queries or screen-reader navigation (same rule as row dialogs). */}
      {open ? (
      <DockedPanel
        heading="Edit demographics"
        primaryLabel={processing ? 'Saving…' : 'Save changes'}
        primaryDisabled={processing}
        secondaryLabel="Cancel"
        offsetInlineEnd={offsetInlineEnd}
        onClose={close}
        onSubmit={submit}
      >
        <FormErrors
          errors={errors}
          fields={['given_name', 'family_name', 'middle_name', 'sex', 'dob_precision', 'date_of_birth', 'primary_phone', 'email', 'reason']}
        />
        <TextInput
          id="edit-given_name"
          labelText="Given name"
          value={data.given_name}
          onChange={(event) => setData('given_name', event.currentTarget.value)}
          invalid={Boolean(errors.given_name)}
          invalidText={errors.given_name}
        />
        <TextInput
          id="edit-family_name"
          labelText="Family name"
          value={data.family_name}
          onChange={(event) => setData('family_name', event.currentTarget.value)}
          invalid={Boolean(errors.family_name)}
          invalidText={errors.family_name}
        />
        <TextInput
          id="edit-middle_name"
          labelText="Middle name"
          value={data.middle_name}
          onChange={(event) => setData('middle_name', event.currentTarget.value)}
          invalid={Boolean(errors.middle_name)}
          invalidText={errors.middle_name}
        />
        <Select
          id="edit-sex"
          labelText="Sex"
          value={data.sex}
          onChange={(event) => setData('sex', event.currentTarget.value as EditableSex)}
          invalid={Boolean(errors.sex)}
          invalidText={errors.sex}
        >
          <SelectItem value="" text="Select sex" />
          <SelectItem value="MALE" text="Male" />
          <SelectItem value="FEMALE" text="Female" />
          <SelectItem value="UNKNOWN" text="Unknown" />
        </Select>
        <Select
          id="edit-dob_precision"
          labelText="Date of birth precision"
          value={data.dob_precision}
          onChange={(event) => setData('dob_precision', event.currentTarget.value as EditablePrecision)}
          invalid={Boolean(errors.dob_precision)}
          invalidText={errors.dob_precision}
        >
          <SelectItem value="" text="Select precision" />
          <SelectItem value="KNOWN" text="Known" />
          <SelectItem value="ESTIMATED" text="Estimated" />
          <SelectItem value="UNKNOWN" text="Unknown" />
        </Select>
        {data.dob_precision === 'KNOWN' ? (
          <TextInput
            id="edit-date_of_birth"
            labelText="Date of birth"
            type="date"
            value={data.date_of_birth}
            onChange={(event) => setData('date_of_birth', event.currentTarget.value)}
            invalid={Boolean(errors.date_of_birth)}
            invalidText={errors.date_of_birth}
          />
        ) : (
          <>
            <TextInput
              id="edit-date_of_birth"
              labelText="Date of birth"
              type="date"
              value={data.date_of_birth}
              readOnly
            />
            <p>Only exact dates of birth can be changed here; estimated or unknown dates stay as recorded.</p>
          </>
        )}
        <TextInput
          id="edit-primary_phone"
          labelText="Phone"
          type="tel"
          value={data.primary_phone}
          onChange={(event) => setData('primary_phone', event.currentTarget.value)}
          invalid={Boolean(errors.primary_phone)}
          invalidText={errors.primary_phone}
        />
        <TextInput
          id="edit-email"
          labelText="Email"
          type="email"
          value={data.email}
          onChange={(event) => setData('email', event.currentTarget.value)}
          invalid={Boolean(errors.email)}
          invalidText={errors.email}
        />
        {touchesSensitive ? (
          <TextArea
            id="edit-reason"
            labelText="Reason for change"
            helperText="Required when changing identity demographics."
            value={data.reason}
            onChange={(event) => setData('reason', event.currentTarget.value)}
            invalid={Boolean(errors.reason)}
            invalidText={errors.reason}
          />
        ) : null}
      </DockedPanel>
      ) : null}
    </>
  );
}

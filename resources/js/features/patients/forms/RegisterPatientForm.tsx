import { useForm, router } from '@inertiajs/react';
import {
  Button,
  Checkbox,
  Form,
  RadioButton,
  RadioButtonGroup,
  Select,
  SelectItem,
  TextArea,
  TextInput,
} from '@carbon/react';
import { useEffect, useRef, useState, type FormEvent, type ReactNode } from 'react';
import { FormErrors, PageHeader } from '@/components/ui';
import { Add, Close, Edit, TrashCan, UserAvatar } from '@/icons';
import { routes } from '@/app/routes';
import { sendJson } from '@/lib/http/json';
import type {
  DobPrecision,
  DuplicateReview,
  AfterRegistrationDestination,
  PatientRegistrationData,
} from '@/features/patients/types/registration';

interface RegisterPatientFormProps {
  duplicateReview?: DuplicateReview | null;
  /** Values echoed by the backend review render — repopulation, not state. */
  initialValues?: Record<string, unknown> | null;
  hasSavedDraft?: boolean;
  defaultAfterRegistration?: AfterRegistrationDestination;
  canOverrideDuplicate: boolean;
  canManageIdentifiers: boolean;
  canRecordAddress: boolean;
}

const initialData: PatientRegistrationData = {
  given_name: '',
  middle_name: '',
  family_name: '',
  display_name: '',
  sex: '',
  dob_precision: '',
  date_of_birth: '',
  estimated_years: '',
  estimated_months: '',
  estimated_days: '',
  primary_phone: '',
  email: '',
  national_id: '',
  insurance_member_number: '',
  region: '',
  district: '',
  ward: '',
  street_or_village: '',
  postal_code: '',
  provisional: false,
  override_duplicate: false,
  override_reason: '',
  after_registration: 'PATIENT_LIST',
  return_to_reception: false,
  return_date: '',
  return_status: '',
  return_filter: '',
  photo: null,
};

const returnContextFields = ['return_date', 'return_status', 'return_filter'] as const;

const textFields = [
  'given_name',
  'middle_name',
  'family_name',
  'display_name',
  'date_of_birth',
  'estimated_years',
  'estimated_months',
  'estimated_days',
  'primary_phone',
  'email',
  'national_id',
  'insurance_member_number',
  'override_reason',
  'region',
  'district',
  'ward',
  'street_or_village',
  'postal_code',
] as const;

const booleanFields = ['provisional', 'override_duplicate', 'return_to_reception'] as const;
const draftMetadataFields = new Set(['after_registration', 'return_to_reception', ...returnContextFields]);

/**
 * Merges backend-echoed values into form state. Only known keys, coerced to
 * field types — never spreads the raw payload (no _token, no surprises).
 * Photo is never repopulated (files can't round-trip).
 */
function pickKnownFields(values: Record<string, unknown> | null | undefined): Partial<PatientRegistrationData> {
  if (!values) return {};
  const picked: Partial<PatientRegistrationData> = {};
  for (const key of textFields) {
    const value = values[key];
    if (typeof value === 'string') picked[key] = value;
  }
  if (values.sex === 'MALE' || values.sex === 'FEMALE' || values.sex === 'UNKNOWN') {
    picked.sex = values.sex;
  }
  if (values.dob_precision === 'KNOWN' || values.dob_precision === 'ESTIMATED' || values.dob_precision === 'UNKNOWN') {
    picked.dob_precision = values.dob_precision;
  }
  if (values.after_registration === 'FRONT_DESK'
    || values.after_registration === 'PATIENT_CHART'
    || values.after_registration === 'PATIENT_LIST') {
    picked.after_registration = values.after_registration;
  }
  for (const key of booleanFields) {
    const value = values[key];
    if (value === true || value === 1 || value === '1' || value === 'true') picked[key] = true;
  }
  // Front Desk return context: opaque round-trip strings, re-validated by
  // the backend on save. Carried in form data, never rendered as inputs.
  for (const key of returnContextFields) {
    const value = values[key];
    if (typeof value === 'string' && value !== '') picked[key] = value;
  }
  return picked;
}

const formErrorFields = [  'given_name',
  'middle_name',
  'family_name',
  'display_name',
  'sex',
  'dob_precision',
  'date_of_birth',
  'estimated_years',
  'estimated_months',
  'estimated_days',
  'primary_phone',
  'email',
  'national_id',
  'insurance_member_number',
  'region',
  'district',
  'ward',
  'street_or_village',
  'postal_code',
  'after_registration',
  'provisional',
  'override_duplicate',
  'override_reason',
  'return_to_reception',
  'photo',
];

function photoPreviewUrl(photo: File | null): string | null {
  if (!(photo instanceof File)) return null;
  if (typeof URL.createObjectURL !== 'function') return null;
  return URL.createObjectURL(photo);
}

/** The registration endpoint accepts photos up to 2 MB; keep a little headroom. */
const PHOTO_UPLOAD_TARGET_BYTES = Math.round(1.8 * 1024 * 1024);
const PHOTO_COMPRESS_THRESHOLD_BYTES = 1.5 * 1024 * 1024;
const PHOTO_MAX_DIMENSION = 1600;

function loadPhotoImage(file: File): Promise<HTMLImageElement> {
  return new Promise((resolve, reject) => {
    if (typeof URL.createObjectURL !== 'function') {
      reject(new Error('Photo preview is unavailable.'));
      return;
    }
    const url = URL.createObjectURL(file);
    const image = new Image();
    image.onload = () => {
      URL.revokeObjectURL(url);
      resolve(image);
    };
    image.onerror = () => {
      URL.revokeObjectURL(url);
      reject(new Error('Photo could not be read.'));
    };
    image.src = url;
  });
}

function renderPhotoBlob(
  image: HTMLImageElement,
  maxDimension: number,
  quality: number,
): Promise<Blob> {
  const scale = Math.min(1, maxDimension / Math.max(image.naturalWidth, image.naturalHeight));
  const width = Math.max(1, Math.round(image.naturalWidth * scale));
  const height = Math.max(1, Math.round(image.naturalHeight * scale));
  const canvas = document.createElement('canvas');
  canvas.width = width;
  canvas.height = height;
  const context = canvas.getContext('2d');
  if (!context) return Promise.reject(new Error('Photo could not be prepared.'));

  context.fillStyle = '#ffffff';
  context.fillRect(0, 0, width, height);
  context.drawImage(image, 0, 0, width, height);

  return new Promise((resolve, reject) => {
    canvas.toBlob(
      (blob) => (blob ? resolve(blob) : reject(new Error('Photo could not be prepared.'))),
      'image/jpeg',
      quality,
    );
  });
}

async function preparePatientPhoto(file: File): Promise<File> {
  const image = await loadPhotoImage(file);
  let maxDimension = PHOTO_MAX_DIMENSION;
  let quality = 0.85;
  let blob: Blob | null = null;

  for (let attempt = 0; attempt < 6; attempt += 1) {
    blob = await renderPhotoBlob(image, maxDimension, quality);
    if (blob.size <= PHOTO_UPLOAD_TARGET_BYTES) break;
    if (quality > 0.55) {
      quality = Math.max(0.55, quality - 0.15);
    } else {
      maxDimension = Math.max(480, Math.round(maxDimension * 0.8));
    }
  }

  if (!blob || blob.size > PHOTO_UPLOAD_TARGET_BYTES) {
    throw new Error('Photo is too large.');
  }

  const baseName = file.name.replace(/\.[^.]+$/, '') || 'patient-photo';
  return new File([blob], `${baseName}.jpg`, {
    type: 'image/jpeg',
    lastModified: file.lastModified,
  });
}

function hasDraftContent(values: Record<string, unknown>): boolean {
  return Object.entries(values).some(([key, value]) => (
    !draftMetadataFields.has(key)
    && (typeof value === 'string' ? value.trim() !== '' : value === true)
  ));
}

export function RegisterPatientForm({
  duplicateReview = null,
  initialValues = null,
  hasSavedDraft = false,
  defaultAfterRegistration = 'PATIENT_LIST',
  canOverrideDuplicate,
  canManageIdentifiers,
  canRecordAddress,
}: RegisterPatientFormProps): ReactNode {
  const { data, setData, post, processing, errors } = useForm<PatientRegistrationData>({
    ...initialData,
    after_registration: defaultAfterRegistration,
    ...pickKnownFields(initialValues),
  });
  const photoInputRef = useRef<HTMLInputElement>(null);
  const photoPreparationId = useRef(0);
  const latestData = useRef(data);
  const submitting = useRef(false);
  const registrationSucceeded = useRef(false);
  const draftSaveTimer = useRef<number | null>(null);
  const draftSaveInFlight = useRef<Promise<void> | null>(null);
  const draftExists = useRef(hasSavedDraft);
  const [previewUrl, setPreviewUrl] = useState<string | null>(null);
  const [photoProcessing, setPhotoProcessing] = useState(false);
  const [photoError, setPhotoError] = useState<string | null>(null);
  const [draftStatus, setDraftStatus] = useState<'saved' | 'saving' | 'unsaved' | 'error'>(
    hasSavedDraft ? 'saved' : 'unsaved',
  );

  useEffect(() => {
    const url = photoPreviewUrl(data.photo);
    setPreviewUrl(url);
    return () => {
      if (url) URL.revokeObjectURL(url);
    };
  }, [data.photo]);

  useEffect(() => {
    latestData.current = data;
  }, [data]);

  useEffect(() => {
    const values = Object.fromEntries(Object.entries(data).filter(([key]) => key !== 'photo'));
    if (!hasDraftContent(values) && !draftExists.current) return undefined;

    const timeout = window.setTimeout(() => {
      draftSaveTimer.current = null;
      setDraftStatus('saving');
      const previousSave = draftSaveInFlight.current ?? Promise.resolve();
      const currentSave = previousSave.then(async () => {
        try {
          await sendJson<{ savedAt: string; expiresAt: string }>(
            routes.workspaces.patientRegistrationDraft,
            'POST',
            { values },
          );
          draftExists.current = true;
          setDraftStatus('saved');
        } catch (error: unknown) {
          console.error('Patient registration draft could not be saved.', error);
          setDraftStatus('error');
        }
      });
      draftSaveInFlight.current = currentSave;
      void currentSave.finally(() => {
        if (draftSaveInFlight.current === currentSave) draftSaveInFlight.current = null;
      });
    }, 700);
    draftSaveTimer.current = timeout;

    return () => {
      window.clearTimeout(timeout);
      if (draftSaveTimer.current === timeout) draftSaveTimer.current = null;
    };
  }, [data]);

  useEffect(() => {
    function saveWhenLeaving(): void {
      if (submitting.current || !navigator.sendBeacon) return;
      const values = Object.fromEntries(Object.entries(latestData.current).filter(([key]) => key !== 'photo'));
      if (!hasDraftContent(values)) return;

      const csrfToken = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content;
      const body = new Blob([JSON.stringify({ _token: csrfToken, values })], {
        type: 'application/json',
      });
      if (!navigator.sendBeacon(routes.workspaces.patientRegistrationDraft, body)) {
        console.error('Patient registration draft could not be queued for saving when leaving the page.');
      }
    }

    window.addEventListener('pagehide', saveWhenLeaving);
    return () => window.removeEventListener('pagehide', saveWhenLeaving);
  }, []);

  function submit(event: FormEvent): void {
    event.preventDefault();
    if (photoProcessing) return;
    submitting.current = true;
    if (draftSaveTimer.current !== null) {
      window.clearTimeout(draftSaveTimer.current);
      draftSaveTimer.current = null;
    }

    const registerPatient = (): void => {
      post(routes.workspaces.patientRegister, {
        replace: true,
        onSuccess: (page) => {
          if (new URL(page.url, window.location.origin).pathname === routes.workspaces.patientRegister) return;
          registrationSucceeded.current = true;
          draftExists.current = false;
        },
        onError: () => {
          const values = Object.fromEntries(Object.entries(data).filter(([key]) => key !== 'photo'));
          setDraftStatus('saving');
          void sendJson<{ savedAt: string; expiresAt: string }>(
            routes.workspaces.patientRegistrationDraft,
            'POST',
            { values },
          ).then(() => setDraftStatus('saved')).catch((error: unknown) => {
            console.error('Patient registration draft could not be saved.', error);
            setDraftStatus('error');
          });
        },
        onFinish: () => {
          if (!registrationSucceeded.current) submitting.current = false;
        },
      });
    };
    const pendingSave = draftSaveInFlight.current;
    if (pendingSave) {
      void pendingSave.then(registerPatient);
    } else {
      registerPatient();
    }
  }

  async function discardDraft(): Promise<void> {
    setDraftStatus('saving');
    submitting.current = true;
    if (draftSaveTimer.current !== null) {
      window.clearTimeout(draftSaveTimer.current);
      draftSaveTimer.current = null;
    }
    try {
      await draftSaveInFlight.current;
      await sendJson<{ deleted: boolean }>(routes.workspaces.patientRegistrationDraft, 'DELETE');
      draftExists.current = false;
      router.visit(`${routes.workspaces.patientRegister}${window.location.search}`, { replace: true });
    } catch (error: unknown) {
      submitting.current = false;
      console.error('Patient registration draft could not be discarded.', error);
      setDraftStatus('error');
    }
  }

  function setDobPrecision(value: string): void {
    const precision = value as DobPrecision | '';
    setData('dob_precision', precision);
    if (precision !== 'KNOWN') setData('date_of_birth', '');
    if (precision !== 'ESTIMATED') {
      setData('estimated_years', '');
      setData('estimated_months', '');
      setData('estimated_days', '');
    }
  }

  function addPhoto(files: FileList | null): void {
    const file = files?.[0] ?? null;
    const preparationId = photoPreparationId.current + 1;
    photoPreparationId.current = preparationId;
    setPhotoError(null);

    // Allow choosing the same file again after a remove or failed preparation.
    if (photoInputRef.current) photoInputRef.current.value = '';

    if (!file) {
      setPhotoProcessing(false);
      setData('photo', null);
      return;
    }

    if (file.size <= PHOTO_COMPRESS_THRESHOLD_BYTES) {
      setPhotoProcessing(false);
      setData('photo', file);
      return;
    }

    setPhotoProcessing(true);
    setData('photo', null);
    void preparePatientPhoto(file)
      .then((prepared) => {
        if (photoPreparationId.current === preparationId) setData('photo', prepared);
      })
      .catch((error: unknown) => {
        if (photoPreparationId.current !== preparationId) return;
        console.error('Patient photo could not be prepared.', error);
        setPhotoError(
          'This photo could not be prepared. Choose a JPG, PNG, or WebP image and try again.',
        );
      })
      .finally(() => {
        if (photoPreparationId.current === preparationId) setPhotoProcessing(false);
      });
  }

  return (
    <>
      <PageHeader
        title="Register patient"
        description="All fields are required unless marked optional."
        actions={(
          <div className="patient-register__draft-actions">
            {draftStatus === 'saved' ? (
              <Button
                kind="ghost"
                size="sm"
                hasIconOnly
                renderIcon={TrashCan}
                iconDescription="Discard saved draft"
                tooltipPosition="left"
                type="button"
                onClick={() => void discardDraft()}
              />
            ) : null}
            <span className="patient-register__draft-status" role="status" aria-live="polite">
              {draftStatus === 'saved' ? 'Draft saved securely. It expires after 7 days.' : null}
              {draftStatus === 'saving' ? 'Saving draft…' : null}
              {draftStatus === 'unsaved' ? 'Changes are saved automatically as you work.' : null}
              {draftStatus === 'error' ? 'Draft could not be saved. Keep this page open and try again.' : null}
              {data.photo ? ' Photos must be added again if you leave this page.' : null}
            </span>
          </div>
        )}
      />
      <Form noValidate onSubmit={submit} aria-label="Patient registration">
      <FormErrors errors={errors} fields={formErrorFields} />

      {duplicateReview ? (
        <section aria-labelledby="duplicate-review-title">
          <h2 id="duplicate-review-title">Possible duplicate patients</h2>
          <p>{duplicateReview.message}</p>
          {duplicateReview.confidenceBand ? <p>Confidence: {duplicateReview.confidenceBand}</p> : null}
          {duplicateReview.candidates.length > 0 ? (
            <ul>
              {duplicateReview.candidates.map((candidate) => (
                <li key={candidate.patientId}>
                  {candidate.displayName} — {candidate.patientNumber}
                  {candidate.dateOfBirth ? ` — ${candidate.dateOfBirth}` : ''}
                </li>
              ))}
            </ul>
          ) : null}
          {canOverrideDuplicate ? (
            <div>
              <Checkbox
                id="override_duplicate"
                labelText="Continue registration despite this review"
                checked={data.override_duplicate}
                onChange={(_, { checked }) => {
                  setData('override_duplicate', checked);
                  if (!checked) setData('override_reason', '');
                }}
              />
              {data.override_duplicate ? (
                <TextArea
                  id="override_reason"
                  labelText="Reason for override"
                  value={data.override_reason}
                  onChange={(event) => setData('override_reason', event.currentTarget.value)}
                  invalid={Boolean(errors.override_reason)}
                  invalidText={errors.override_reason}
                  required
                />
              ) : null}
            </div>
          ) : null}
        </section>
      ) : null}

      <div className="patient-register">
        <div className="patient-register__layout">
          <div className="patient-register__fields">
            <div className="patient-register__names">
                <TextInput
                  id="given_name"
                  labelText="First Name"
                  value={data.given_name}
                  onChange={(event) => setData('given_name', event.currentTarget.value)}
                  invalid={Boolean(errors.given_name)}
                  invalidText={errors.given_name}
                  required
                />
                <TextInput
                  id="middle_name"
                  labelText="Middle Name (optional)"
                  value={data.middle_name}
                  onChange={(event) => setData('middle_name', event.currentTarget.value)}
                  invalid={Boolean(errors.middle_name)}
                  invalidText={errors.middle_name}
                />
                <TextInput
                  id="family_name"
                  labelText="Family Name"
                  value={data.family_name}
                  onChange={(event) => setData('family_name', event.currentTarget.value)}
                  invalid={Boolean(errors.family_name)}
                  invalidText={errors.family_name}
                  required
                />
                <TextInput
                  id="display_name"
                  labelText="Display name (optional)"
                  value={data.display_name}
                  onChange={(event) => setData('display_name', event.currentTarget.value)}
                  invalid={Boolean(errors.display_name)}
                  invalidText={errors.display_name}
                />
              </div>

            <RadioButtonGroup
              name="sex"
              legendText="Sex"
              orientation="horizontal"
              valueSelected={data.sex === '' ? undefined : data.sex}
              onChange={(value) => setData('sex', value as PatientRegistrationData['sex'])}
              invalid={Boolean(errors.sex)}
              invalidText={errors.sex}
              required
            >
              <RadioButton id="sex-male" labelText="Male" value="MALE" />
              <RadioButton id="sex-female" labelText="Female" value="FEMALE" />
              <RadioButton id="sex-unknown" labelText="Unknown" value="UNKNOWN" />
            </RadioButtonGroup>

            <Select
              id="dob_precision"
              labelText="Date of birth precision"
              value={data.dob_precision}
              onChange={(event) => setDobPrecision(event.currentTarget.value)}
              invalid={Boolean(errors.dob_precision)}
              invalidText={errors.dob_precision}
              required
            >
              <SelectItem value="" text="Select precision" />
              <SelectItem value="KNOWN" text="Known" />
              <SelectItem value="ESTIMATED" text="Estimated" />
              <SelectItem value="UNKNOWN" text="Unknown" />
            </Select>
            {data.dob_precision === 'KNOWN' ? (
              <TextInput
                id="date_of_birth"
                labelText="Date of birth"
                type="date"
                value={data.date_of_birth}
                onChange={(event) => setData('date_of_birth', event.currentTarget.value)}
                invalid={Boolean(errors.date_of_birth)}
                invalidText={errors.date_of_birth}
                required
              />
            ) : null}
            {data.dob_precision === 'ESTIMATED' ? (
              <div className="patient-register__estimated-dob">
                <TextInput
                  id="estimated_years"
                  labelText="Estimated years"
                  type="number"
                  value={data.estimated_years}
                  onChange={(event) => setData('estimated_years', event.currentTarget.value)}
                  invalid={Boolean(errors.estimated_years)}
                  invalidText={errors.estimated_years}
                />
                <TextInput
                  id="estimated_months"
                  labelText="Estimated months"
                  type="number"
                  value={data.estimated_months}
                  onChange={(event) => setData('estimated_months', event.currentTarget.value)}
                  invalid={Boolean(errors.estimated_months)}
                  invalidText={errors.estimated_months}
                />
                <TextInput
                  id="estimated_days"
                  labelText="Estimated days"
                  type="number"
                  value={data.estimated_days}
                  onChange={(event) => setData('estimated_days', event.currentTarget.value)}
                  invalid={Boolean(errors.estimated_days)}
                  invalidText={errors.estimated_days}
                />
              </div>
            ) : null}

            <div className="patient-register__grid">
            <TextInput
              id="primary_phone"
              labelText="Phone"
              type="tel"
              value={data.primary_phone}
              onChange={(event) => setData('primary_phone', event.currentTarget.value)}
              invalid={Boolean(errors.primary_phone)}
              invalidText={errors.primary_phone}
            />
            <TextInput
              id="email"
              labelText="Email"
              type="email"
              value={data.email}
              onChange={(event) => setData('email', event.currentTarget.value)}
              invalid={Boolean(errors.email)}
              invalidText={errors.email}
            />

            {canManageIdentifiers ? (
              <p className="patient-register__span">Identifiers can be recorded with this registration.</p>
            ) : (
              <p className="patient-register__span">Identifiers need an additional permission; without it they will not be stored.</p>
            )}
            <TextInput
              id="national_id"
              labelText="National ID"
              value={data.national_id}
              onChange={(event) => setData('national_id', event.currentTarget.value)}
              invalid={Boolean(errors.national_id)}
              invalidText={errors.national_id}
            />
            <TextInput
              id="insurance_member_number"
              labelText="Insurance member number"
              value={data.insurance_member_number}
              onChange={(event) => setData('insurance_member_number', event.currentTarget.value)}
              invalid={Boolean(errors.insurance_member_number)}
              invalidText={errors.insurance_member_number}
            />

            {canRecordAddress ? null : (
              <p className="patient-register__span">Address details need an additional permission; without it they will not be stored.</p>
            )}
            <TextInput
              id="region"
              labelText="Region"
              value={data.region}
              onChange={(event) => setData('region', event.currentTarget.value)}
              invalid={Boolean(errors.region)}
              invalidText={errors.region}
            />
            <TextInput
              id="district"
              labelText="District"
              value={data.district}
              onChange={(event) => setData('district', event.currentTarget.value)}
              invalid={Boolean(errors.district)}
              invalidText={errors.district}
            />
            <div className="patient-register__span">
            <TextInput
              id="street_or_village"
              labelText="Street or village"
              value={data.street_or_village}
              onChange={(event) => setData('street_or_village', event.currentTarget.value)}
              invalid={Boolean(errors.street_or_village)}
              invalidText={errors.street_or_village}
            />
            </div>
            <TextInput
              id="ward"
              labelText="Ward"
              value={data.ward}
              onChange={(event) => setData('ward', event.currentTarget.value)}
              invalid={Boolean(errors.ward)}
              invalidText={errors.ward}
            />
            <TextInput
              id="postal_code"
              labelText="Postal code"
              value={data.postal_code}
              onChange={(event) => setData('postal_code', event.currentTarget.value)}
              invalid={Boolean(errors.postal_code)}
              invalidText={errors.postal_code}
            />
            </div>

            <Checkbox
              id="provisional"
              labelText="Provisional registration"
              checked={data.provisional}
              onChange={(_, { checked }) => setData('provisional', checked)}
            />
            <RadioButtonGroup
              name="after_registration"
              legendText="After registration"
              orientation="vertical"
              valueSelected={data.after_registration || defaultAfterRegistration}
              onChange={(value) => setData('after_registration', value as AfterRegistrationDestination)}
            >
              <RadioButton id="after-registration-front-desk" labelText="Go to Front Desk to check in" value="FRONT_DESK" />
              <RadioButton id="after-registration-chart" labelText="Open patient chart" value="PATIENT_CHART" />
              <RadioButton id="after-registration-list" labelText="Return to patient list" value="PATIENT_LIST" />
            </RadioButtonGroup>

        <div className="patient-register__actions">
          <Button size="lg" renderIcon={Add} type="submit" disabled={processing || photoProcessing}>
            {photoProcessing ? 'Preparing photo…' : processing ? 'Registering…' : 'Register patient'}
          </Button>
          <Button size="lg" renderIcon={Close} type="button" kind="ghost" onClick={() => router.visit(routes.dashboard)}>
            Cancel
          </Button>
        </div>
          </div>
          <div className="patient-register__photo">
            <span className="cds--label patient-register__photo-label">Photo</span>
            <div className={`patient-register__photo-frame${previewUrl ? '' : ' patient-register__photo-frame--empty'}`}>
              {previewUrl ? (
                <img
                  className="patient-register__photo-image"
                  src={previewUrl}
                  alt="Patient photo preview"
                />
              ) : (
                <div className="patient-register__photo-empty">
                  <UserAvatar size={32} />
                  <span>No image to display</span>
                  <small>JPG, PNG or WebP</small>
                </div>
              )}
            </div>
            <p id="patient-photo-help" className="patient-register__photo-help" aria-live="polite">
              {photoProcessing
                ? 'Preparing photo…'
                : 'Drafts don’t include photos. Add it again before registering.'}
            </p>
            <input
              ref={photoInputRef}
              id="photo-upload"
              type="file"
              accept=".jpeg,.jpg,.png,.webp"
              hidden
              aria-label="Patient photo file"
              aria-describedby="patient-photo-help"
              onChange={(event) => addPhoto(event.currentTarget.files)}
            />
            <div className="patient-register__photo-actions">
              <Button
                kind="tertiary"
                size="sm"
                type="button"
                disabled={photoProcessing}
                renderIcon={previewUrl ? Edit : Add}
                onClick={() => photoInputRef.current?.click()}
              >
                {previewUrl ? 'Change photo' : 'Add photo'}
              </Button>
              {previewUrl ? (
                <Button
                  kind="secondary"
                  size="sm"
                  type="button"
                  renderIcon={TrashCan}
                  onClick={() => {
                    setPhotoError(null);
                    setData('photo', null);
                  }}
                >
                  Remove
                </Button>
              ) : null}
            </div>
            {photoError || errors.photo ? <p role="alert">{photoError ?? errors.photo}</p> : null}
          </div>
        </div>
      </div>
      </Form>
    </>
  );
}

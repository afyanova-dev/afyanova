import { Button, Checkbox, DatePicker, DatePickerInput, Select, SelectItem, TextArea, TextInput } from '@carbon/react';
import { router, useForm } from '@inertiajs/react';
import { useEffect, useRef, useState, type FormEvent } from 'react';
import { routes } from '@/app/routes';
import { FormErrors } from '@/components/ui';
import { Add, Close, Edit, TrashCan, UserAvatar } from '@/icons';
import type { DepartmentOption, PositionOption, UnitOption } from '../types';

function localDate(date: Date): string {
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
}

function dateFromValue(value: string): Date | undefined {
  if (!value) return undefined;
  const parts = value.split('-').map(Number);
  if (parts.length !== 3 || parts.some((part) => !Number.isInteger(part))) return undefined;
  const [year, month, day] = parts;
  if (year === undefined || month === undefined || day === undefined) return undefined;
  const date = new Date(year, month - 1, day);
  return date.getFullYear() === year && date.getMonth() === month - 1 && date.getDate() === day
    ? date
    : undefined;
}

function dateValueFromInput(value: string): string {
  const parts = value.split('/');
  if (parts.length !== 3 || !parts.every((part) => /^\d+$/.test(part))) return '';
  const [month, day, year] = parts.map(Number);
  if (month === undefined || day === undefined || year === undefined) return '';
  const date = new Date(year, month - 1, day);
  return date.getFullYear() === year && date.getMonth() === month - 1 && date.getDate() === day
    ? localDate(date)
    : '';
}

interface StaffCreateFormProps {
  departments: DepartmentOption[];
  units: UnitOption[];
  positions: PositionOption[];
  canManageEmployment: boolean;
  invitationRoles: { key: string; label: string }[];
  canInviteUser: boolean;
}

export function StaffCreateForm({
  departments,
  units,
  positions,
  canManageEmployment,
  invitationRoles,
  canInviteUser,
}: StaffCreateFormProps): React.JSX.Element {
  const photoInputRef = useRef<HTMLInputElement>(null);
  const [photoPreviewUrl, setPhotoPreviewUrl] = useState<string | null>(null);
  const form = useForm({
    first_name: '',
    middle_name: '',
    last_name: '',
    date_of_birth: '',
    gender: 'UNKNOWN',
    phone: '',
    email: '',
    request_login: false,
    primary_role_key: '',
    secondary_role_keys: [] as string[],
    change_reference: '',
    access_reason: '',
    department_id: '',
    unit_id: '',
    effective_from: localDate(new Date()),
    position_id: '',
    contract_type: '',
    employment_start_date: '',
    fte: '1.00',
    photo: null as File | null,
  });

  useEffect(() => {
    const photo = form.data.photo;
    if (typeof File === 'undefined' || !(photo instanceof File) || typeof URL.createObjectURL !== 'function') {
      setPhotoPreviewUrl(null);
      return undefined;
    }

    const previewUrl = URL.createObjectURL(photo);
    setPhotoPreviewUrl(previewUrl);
    return () => URL.revokeObjectURL(previewUrl);
  }, [form.data.photo]);

  function submit(event: FormEvent<HTMLFormElement>): void {
    event.preventDefault();
    form.clearErrors();
    const errors: Partial<Record<
      | 'first_name'
      | 'last_name'
      | 'date_of_birth'
      | 'email'
      | 'primary_role_key'
      | 'change_reference'
      | 'access_reason'
      | 'effective_from'
      | 'contract_type'
      | 'employment_start_date',
      string
    >> = {};
    const today = localDate(new Date());
    if (!form.data.first_name.trim()) {
      errors.first_name = "Enter the staff member's first name.";
    }
    if (!form.data.last_name.trim()) {
      errors.last_name = "Enter the staff member's last name.";
    }
    if (!dateFromValue(form.data.date_of_birth) || form.data.date_of_birth >= today) {
      errors.date_of_birth = 'Enter a valid date of birth before today.';
    }
    if (form.data.email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(form.data.email)) {
      errors.email = 'Enter a valid email address.';
    } else if (form.data.request_login && !form.data.email.trim()) {
      errors.email = 'Enter an email address for account setup.';
    }
    if (form.data.request_login && !form.data.primary_role_key) {
      errors.primary_role_key = 'Select a primary AfyaNova access role.';
    }
    if (form.data.request_login && !/^[A-Za-z0-9][A-Za-z0-9._:-]{2,99}$/.test(form.data.change_reference)) {
      errors.change_reference = 'Enter a valid non-secret change or ticket reference (3-100 characters).';
    }
    if (form.data.request_login && (form.data.access_reason.trim().length < 8 || form.data.access_reason.length > 500)) {
      errors.access_reason = 'Enter a reason between 8 and 500 characters.';
    }
    if (!form.data.effective_from || !dateFromValue(form.data.effective_from)) {
      errors.effective_from = 'Enter a valid assignment start date.';
    }
    if (form.data.position_id && !form.data.contract_type) {
      errors.contract_type = 'Select a contract type for the selected position.';
    }
    if (form.data.position_id && !dateFromValue(form.data.employment_start_date)) {
      errors.employment_start_date = 'Enter an employment start date for the selected position.';
    }
    for (const field of [
      'first_name',
      'last_name',
      'date_of_birth',
      'email',
      'primary_role_key',
      'change_reference',
      'access_reason',
      'effective_from',
      'contract_type',
      'employment_start_date',
    ] as const) {
      const message = errors[field];
      if (message !== undefined) form.setError(field, message);
    }
    if (Object.keys(errors).length > 0) return;
    form.post(routes.workspaces.hrStaffCreate, { preserveScroll: true });
  }

  return (
    <div className="facility-staff__create">
      <FormErrors
        errors={form.errors}
        fields={[
          'first_name', 'middle_name', 'last_name', 'date_of_birth', 'gender', 'phone', 'email',
          'request_login', 'primary_role_key', 'secondary_role_keys', 'change_reference', 'access_reason', 'department_id',
          'unit_id', 'effective_from', 'position_id', 'contract_type', 'employment_start_date', 'fte', 'photo',
        ]}
      />
      <form className="facility-staff__create-form" noValidate onSubmit={submit}>
        <div className="facility-staff__create-layout">
          <div className="facility-staff__create-fields">
        <section className="facility-staff__create-section" aria-labelledby="staff-personal-heading">
          <div className="facility-staff__create-heading">
            <h2 id="staff-personal-heading">Personal details</h2>
          </div>
          <div className="facility-staff__create-names">
            <TextInput
              id="staff-first-name"
              labelText="First name"
              value={form.data.first_name}
              onChange={(event) => form.setData('first_name', event.currentTarget.value)}
              invalid={Boolean(form.errors.first_name)}
              invalidText={form.errors.first_name}
              required
            />
            <TextInput
              id="staff-middle-name"
              labelText="Middle name (optional)"
              value={form.data.middle_name}
              onChange={(event) => form.setData('middle_name', event.currentTarget.value)}
              invalid={Boolean(form.errors.middle_name)}
              invalidText={form.errors.middle_name}
            />
            <TextInput
              id="staff-last-name"
              labelText="Last name"
              value={form.data.last_name}
              onChange={(event) => form.setData('last_name', event.currentTarget.value)}
              invalid={Boolean(form.errors.last_name)}
              invalidText={form.errors.last_name}
              required
            />
          </div>
          <div className="facility-staff__create-grid">
            <DatePicker
              datePickerType="single"
              dateFormat="m/d/Y"
              value={dateFromValue(form.data.date_of_birth)}
              maxDate={dateFromValue(localDate(new Date()))}
              onChange={(dates) => form.setData('date_of_birth', dates[0] ? localDate(dates[0]) : '')}
            >
              <DatePickerInput
                id="staff-date-of-birth"
                labelText="Date of birth"
                placeholder="mm/dd/yyyy"
                onChange={(event) => form.setData('date_of_birth', dateValueFromInput(event.currentTarget.value))}
                invalid={Boolean(form.errors.date_of_birth)}
                invalidText={form.errors.date_of_birth}
                aria-required="true"
              />
            </DatePicker>
            <Select
              id="staff-gender"
              labelText="Gender"
              value={form.data.gender}
              onChange={(event) => form.setData('gender', event.currentTarget.value)}
              invalid={Boolean(form.errors.gender)}
              invalidText={form.errors.gender}
              required
            >
              <SelectItem value="UNKNOWN" text="Prefer not to say" />
              <SelectItem value="FEMALE" text="Female" />
              <SelectItem value="MALE" text="Male" />
              <SelectItem value="OTHER" text="Other" />
            </Select>
            <TextInput
              id="staff-phone"
              labelText="Phone (optional)"
              value={form.data.phone}
              onChange={(event) => form.setData('phone', event.currentTarget.value)}
              invalid={Boolean(form.errors.phone)}
              invalidText={form.errors.phone}
            />
            <TextInput
              id="staff-email"
              type="email"
              labelText={form.data.request_login ? 'Staff email for account setup' : 'Contact email (optional)'}
              value={form.data.email}
              onChange={(event) => form.setData('email', event.currentTarget.value)}
              invalid={Boolean(form.errors.email)}
              invalidText={form.errors.email}
              required={form.data.request_login}
            />
          </div>
        </section>

        <section className="facility-staff__create-section" aria-labelledby="staff-placement-heading">
          <div className="facility-staff__create-heading">
            <h2 id="staff-placement-heading">Requested placement</h2>
          </div>
          <div className="facility-staff__create-grid facility-staff__create-grid--three">
            <Select
              id="staff-department"
              labelText="Department (optional)"
              value={form.data.department_id}
              onChange={(event) => {
                form.setData('department_id', event.currentTarget.value);
                form.setData('unit_id', '');
              }}
              invalid={Boolean(form.errors.department_id)}
              invalidText={form.errors.department_id}
            >
              <SelectItem value="" text="Facility-wide" />
              {departments.map((department) => (
                <SelectItem key={department.id} value={department.id} text={department.name} />
              ))}
            </Select>
            <Select
              id="staff-unit"
              labelText="Unit (optional)"
              value={form.data.unit_id}
              disabled={!form.data.department_id}
              onChange={(event) => form.setData('unit_id', event.currentTarget.value)}
              invalid={Boolean(form.errors.unit_id)}
              invalidText={form.errors.unit_id}
            >
              <SelectItem value="" text="Department-wide" />
              {units.filter((unit) => unit.departmentId === form.data.department_id).map((unit) => (
                <SelectItem key={unit.id} value={unit.id} text={unit.name} />
              ))}
            </Select>
            <DatePicker
              datePickerType="single"
              dateFormat="m/d/Y"
              value={dateFromValue(form.data.effective_from)}
              onChange={(dates) => form.setData('effective_from', dates[0] ? localDate(dates[0]) : '')}
            >
              <DatePickerInput
                id="staff-assignment-start"
                labelText="Requested assignment start"
                placeholder="mm/dd/yyyy"
                onChange={(event) => form.setData('effective_from', dateValueFromInput(event.currentTarget.value))}
                invalid={Boolean(form.errors.effective_from)}
                invalidText={form.errors.effective_from}
                aria-required="true"
              />
            </DatePicker>
          </div>
        </section>

        {canManageEmployment ? (
          <section className="facility-staff__create-section" aria-labelledby="staff-employment-heading">
            <div className="facility-staff__create-heading">
              <h2 id="staff-employment-heading">Employment details</h2>
            </div>
            <div className="facility-staff__create-grid">
              <Select
                id="staff-position"
                labelText="Position (optional)"
                value={form.data.position_id}
                onChange={(event) => {
                  form.setData('position_id', event.currentTarget.value);
                  if (!event.currentTarget.value) {
                    form.setData('contract_type', '');
                    form.setData('employment_start_date', '');
                  }
                }}
                invalid={Boolean(form.errors.position_id)}
                invalidText={form.errors.position_id}
              >
                <SelectItem value="" text="No position selected" />
                {positions.map((position) => (
                  <SelectItem key={position.id} value={position.id} text={position.title} />
                ))}
              </Select>
              <Select
                id="staff-contract-type"
                labelText="Contract type"
                value={form.data.contract_type}
                disabled={!form.data.position_id}
                onChange={(event) => form.setData('contract_type', event.currentTarget.value)}
                invalid={Boolean(form.errors.contract_type)}
                invalidText={form.errors.contract_type}
                aria-required={form.data.position_id ? 'true' : undefined}
              >
                <SelectItem value="" text="Select contract type" />
                <SelectItem value="PERMANENT" text="Permanent" />
                <SelectItem value="FIXED_TERM" text="Fixed term" />
                <SelectItem value="CASUAL" text="Casual" />
                <SelectItem value="LOCUM" text="Locum" />
                <SelectItem value="VOLUNTEER" text="Volunteer" />
                <SelectItem value="INTERN" text="Intern" />
              </Select>
              <DatePicker
                datePickerType="single"
                dateFormat="m/d/Y"
                value={dateFromValue(form.data.employment_start_date)}
                onChange={(dates) => form.setData('employment_start_date', dates[0] ? localDate(dates[0]) : '')}
              >
                <DatePickerInput
                  id="staff-employment-start"
                  labelText="Employment start date"
                  placeholder="mm/dd/yyyy"
                  disabled={!form.data.position_id}
                  onChange={(event) => form.setData('employment_start_date', dateValueFromInput(event.currentTarget.value))}
                  invalid={Boolean(form.errors.employment_start_date)}
                  invalidText={form.errors.employment_start_date}
                  aria-required={form.data.position_id ? 'true' : undefined}
                />
              </DatePicker>
              <TextInput
                id="staff-employment-fte"
                type="number"
                labelText="Full-time equivalent"
                min="0.01"
                max="9.99"
                step="0.01"
                value={form.data.fte}
                disabled={!form.data.position_id}
                onChange={(event) => form.setData('fte', event.currentTarget.value)}
                invalid={Boolean(form.errors.fte)}
                invalidText={form.errors.fte}
              />
            </div>
          </section>
        ) : null}

        {canInviteUser ? (
          <section className="facility-staff__create-section" aria-labelledby="staff-access-heading">
            <div className="facility-staff__create-heading">
              <h2 id="staff-access-heading">AfyaNova access roles</h2>
            </div>
            <p className="facility-staff__create-help">
              These roles control what the person can do in AfyaNova. They are separate from their professional cadre and job position.
            </p>
            <div className="facility-staff__create-grid">
              <div className="facility-staff__create-wide">
                <Checkbox
                  id="staff-request-login"
                  labelText="Invite this staff member to use AfyaNova"
                  checked={form.data.request_login}
                  onChange={(_, { checked }) => {
                    form.setData('request_login', checked);
                    if (!checked) {
                      form.setData('primary_role_key', '');
                      form.setData('secondary_role_keys', []);
                      form.setData('change_reference', '');
                      form.setData('access_reason', '');
                    }
                  }}
                />
              </div>
              {form.data.request_login ? (
                <>
                  <Select
                    id="staff-requested-role"
                    labelText="Primary AfyaNova access role"
                    value={form.data.primary_role_key}
                    onChange={(event) => {
                      const primaryRole = event.currentTarget.value;
                      form.setData('primary_role_key', primaryRole);
                      form.setData(
                        'secondary_role_keys',
                        form.data.secondary_role_keys.filter((key) => key !== primaryRole),
                      );
                    }}
                    invalid={Boolean(form.errors.primary_role_key)}
                    invalidText={form.errors.primary_role_key}
                    required
                  >
                    <SelectItem value="" text="Select a primary role" />
                    {invitationRoles.map((role) => (
                      <SelectItem key={role.key} value={role.key} text={role.label} />
                    ))}
                  </Select>
                  <TextInput
                    id="staff-access-reference"
                    labelText="Non-secret change/ticket reference"
                    value={form.data.change_reference}
                    onChange={(event) => form.setData('change_reference', event.currentTarget.value)}
                    invalid={Boolean(form.errors.change_reference)}
                    invalidText={form.errors.change_reference}
                    required
                  />
                  <div className="facility-staff__create-wide">
                    <span className="cds--label">Secondary AfyaNova access roles (optional, up to two)</span>
                    <p className="facility-staff__create-help">
                      Select additional application permissions the staff member needs.
                    </p>
                    <div className="facility-staff__create-roles">
                      {invitationRoles
                        .filter((role) => role.key !== form.data.primary_role_key)
                        .map((role) => {
                          const checked = form.data.secondary_role_keys.includes(role.key);
                          const atLimit = form.data.secondary_role_keys.length >= 2 && !checked;
                          return (
                            <Checkbox
                              key={role.key}
                              id={`staff-secondary-role-${role.key}`}
                              labelText={role.label}
                              checked={checked}
                              disabled={atLimit}
                              onChange={(_, { checked: selected }) => {
                                const roles = selected
                                  ? [...form.data.secondary_role_keys, role.key]
                                  : form.data.secondary_role_keys.filter((key) => key !== role.key);
                                form.setData('secondary_role_keys', roles);
                              }}
                            />
                          );
                        })}
                    </div>
                    {form.errors.secondary_role_keys ? (
                      <div className="cds--form__error-message" role="alert">{form.errors.secondary_role_keys}</div>
                    ) : null}
                  </div>
                  <div className="facility-staff__create-wide">
                    <TextArea
                      id="staff-access-reason"
                      labelText="Reason for requested access"
                      value={form.data.access_reason}
                      onChange={(event) => form.setData('access_reason', event.currentTarget.value)}
                      invalid={Boolean(form.errors.access_reason)}
                      invalidText={form.errors.access_reason}
                      rows={3}
                      maxLength={500}
                      required
                    />
                  </div>
                </>
              ) : null}
            </div>
          </section>
        ) : null}

          </div>
          <aside className="facility-staff__create-photo">
            <span className="cds--label facility-staff__create-photo-label">Staff photo (optional)</span>
            <div className={`facility-staff__create-photo-frame${photoPreviewUrl ? '' : ' facility-staff__create-photo-frame--empty'}`}>
              {photoPreviewUrl ? (
                <img
                  className="facility-staff__create-photo-image"
                  src={photoPreviewUrl}
                  alt="Staff photo preview"
                />
              ) : (
                <div className="facility-staff__create-photo-empty">
                  <UserAvatar size={32} />
                  <span>No image to display</span>
                  <small>JPG, PNG or WebP</small>
                </div>
              )}
            </div>
            <p id="staff-photo-help" className="facility-staff__create-photo-help">
              Upload a clear, recent photo to help identify this staff member.
            </p>
            <input
              ref={photoInputRef}
              id="staff-photo-upload"
              type="file"
              accept=".jpeg,.jpg,.png,.webp"
              hidden
              aria-label="Staff photo file"
              aria-describedby={form.errors.photo ? 'staff-photo-help staff-photo-error' : 'staff-photo-help'}
              onChange={(event) => form.setData('photo', event.currentTarget.files?.[0] ?? null)}
            />
            <div className="facility-staff__create-photo-actions">
              <Button
                kind="tertiary"
                size="sm"
                type="button"
                renderIcon={form.data.photo ? Edit : Add}
                onClick={() => photoInputRef.current?.click()}
              >
                {form.data.photo ? 'Change photo' : 'Add photo'}
              </Button>
              {form.data.photo ? (
                <Button
                  kind="secondary"
                  size="sm"
                  type="button"
                  renderIcon={TrashCan}
                  onClick={() => {
                    form.setData('photo', null);
                    if (photoInputRef.current) photoInputRef.current.value = '';
                  }}
                >
                  Remove
                </Button>
              ) : null}
            </div>
            {form.data.photo ? (
              <span className="facility-staff__create-photo-meta">{form.data.photo.name}</span>
            ) : null}
            {form.errors.photo ? (
              <p id="staff-photo-error" className="facility-staff__create-photo-error" role="alert">
                {form.errors.photo}
              </p>
            ) : null}
          </aside>
        </div>
        <div className="facility-staff__create-actions">
          <Button size="lg" renderIcon={Add}
            type="submit"
            disabled={form.processing}
          >
            {form.processing
              ? 'Creating…'
              : form.data.request_login
                ? 'Create & invite'
                : 'Create staff'}
          </Button>
          <Button
            kind="secondary"
            size="lg"
            type="button"
            renderIcon={Close}
            onClick={() => router.visit(routes.workspaces.hrStaff)}
          >
            Cancel
          </Button>
        </div>
      </form>
    </div>
  );
}

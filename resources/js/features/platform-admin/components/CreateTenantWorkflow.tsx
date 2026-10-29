import { InlineNotification, Select, SelectItem, TextInput } from '@carbon/react';
import { type FormEvent, type ReactNode, useState } from 'react';
import { FormErrors } from '@/components/ui';
import { DockedPanel } from '@/components/panels';
import { routes } from '@/app/routes';
import { useForm } from '@inertiajs/react';
import type { FacilityReferences } from '../types/platform-admin';

interface FormValues {
  tenant_name: string;
  tenant_slug: string;
  organization_name: string;
  organization_code: string;
  facility_name: string;
  facility_code: string;
  facility_type: string;
  care_level: string;
  ownership_type: string;
}

const FORM_FIELDS = [
  'tenant_name',
  'tenant_slug',
  'organization_name',
  'organization_code',
  'facility_name',
  'facility_code',
  'facility_type',
  'care_level',
  'ownership_type',
] as const;

const FIELD_MESSAGES: Partial<Record<keyof FormValues, string>> = {
  tenant_name: 'Enter a tenant name.',
  tenant_slug: 'Enter a tenant URL slug.',
  organization_name: 'Enter a healthcare organization name.',
  organization_code: 'Enter an organization code.',
  facility_name: 'Enter a facility name.',
  facility_code: 'Enter a facility code.',
  facility_type: 'Choose a facility type.',
  care_level: 'Choose a care level.',
  ownership_type: 'Choose the ownership type.',
};

export function CreateTenantWorkflow({
  facilityReferences,
  onClose,
}: {
  facilityReferences: FacilityReferences;
  onClose: () => void;
}): ReactNode {
  const [formError, setFormError] = useState<string | null>(null);
  const [invalidFields, setInvalidFields] = useState<Partial<Record<keyof FormValues, string>>>({});
  const { data, setData, post, processing, errors } = useForm<FormValues>({
    tenant_name: '',
    tenant_slug: '',
    organization_name: '',
    organization_code: '',
    facility_name: '',
    facility_code: '',
    facility_type: '',
    care_level: '',
    ownership_type: '',
  });

  function updateField(field: keyof FormValues, value: string): void {
    setData(field, value);
    if (field in invalidFields) {
      setFormError(null);
      setInvalidFields((current) => {
        const next = { ...current };
        delete next[field];
        return next;
      });
    }
  }

  function validateFields(): Partial<Record<keyof FormValues, string>> {
    const fieldErrors: Partial<Record<keyof FormValues, string>> = {};
    const requiredFields: (keyof FormValues)[] = [
      'tenant_name',
      'tenant_slug',
      'organization_name',
      'organization_code',
      'facility_name',
      'facility_code',
      'facility_type',
      'care_level',
      'ownership_type',
    ];
    for (const field of requiredFields) {
      if (data[field].trim() === '') {
        fieldErrors[field] = FIELD_MESSAGES[field] ?? 'Complete this field.';
      }
    }
    if (data.tenant_slug.trim() !== '' && !/^[a-z0-9]+(?:-[a-z0-9]+)*$/.test(data.tenant_slug)) {
      fieldErrors.tenant_slug = 'Use lowercase letters, numbers, and single hyphens for the tenant slug.';
    }
    if (data.organization_code.trim() !== '' && !/^[A-Z0-9]+(?:_[A-Z0-9]+)*$/.test(data.organization_code)) {
      fieldErrors.organization_code = 'Use uppercase letters, numbers, and underscores for the organization code.';
    }
    if (data.facility_code.trim() !== '' && !/^[A-Z0-9]+(?:_[A-Z0-9]+)*$/.test(data.facility_code)) {
      fieldErrors.facility_code = 'Use uppercase letters, numbers, and underscores for the facility code.';
    }
    return fieldErrors;
  }

  function submit(event: FormEvent): void {
    event.preventDefault();
    const fieldErrors = validateFields();
    if (Object.keys(fieldErrors).length > 0) {
      setInvalidFields(fieldErrors);
      setFormError('Complete the highlighted fields before creating the tenant.');
      return;
    }
    setInvalidFields({});
    setFormError(null);
    post(routes.platformAdmin.storeTenant, { preserveScroll: true });
  }

  return (
    <DockedPanel
      heading="Create tenant"
      description="Set up the tenant, its healthcare organization, and first facility together."
      placement="right"
      width="min(44rem, 100%)"
      expandable
      noValidate
      onClose={onClose}
      onSubmit={submit}
      primaryLabel={processing ? 'Creating tenant…' : 'Create tenant and facility'}
      primaryDisabled={processing}
      secondaryLabel="Cancel"
    >
      <FormErrors errors={errors} fields={[...FORM_FIELDS]} />
      {formError ? (
        <InlineNotification
          kind="error"
          lowContrast
          title="The tenant form is not complete"
          subtitle={formError}
        />
      ) : null}

      <div className="platform-admin__wizard-fields">
        <section aria-labelledby="tenant-identity-heading" className="platform-admin__wizard-section">
          <header className="platform-admin__create-heading">
            <h2 id="tenant-identity-heading">Tenant identity</h2>
          </header>
          <div className="platform-admin__form-grid">
            <TextInput
              id="tenant-name"
              labelText="Tenant name"
              value={data.tenant_name}
              onChange={(event) => updateField('tenant_name', event.target.value)}
              invalid={Boolean(errors.tenant_name) || Boolean(invalidFields.tenant_name)}
              invalidText={errors.tenant_name ?? invalidFields.tenant_name}
              required
            />
            <TextInput
              id="tenant-slug"
              labelText="Tenant URL slug"
              helperText="Lowercase letters, numbers, and hyphens."
              value={data.tenant_slug}
              onChange={(event) => updateField('tenant_slug', event.target.value)}
              invalid={Boolean(errors.tenant_slug) || Boolean(invalidFields.tenant_slug)}
              invalidText={errors.tenant_slug ?? invalidFields.tenant_slug}
              required
            />
          </div>
        </section>

        <section aria-labelledby="organization-heading" className="platform-admin__wizard-section">
          <header className="platform-admin__create-heading">
            <h2 id="organization-heading">Healthcare organization</h2>
          </header>
          <div className="platform-admin__form-grid">
            <TextInput
              id="organization-name"
              labelText="Organization name"
              value={data.organization_name}
              onChange={(event) => updateField('organization_name', event.target.value)}
              invalid={Boolean(errors.organization_name) || Boolean(invalidFields.organization_name)}
              invalidText={errors.organization_name ?? invalidFields.organization_name}
              required
            />
            <TextInput
              id="organization-code"
              labelText="Organization code"
              helperText="Use uppercase letters, numbers, and underscores."
              value={data.organization_code}
              onChange={(event) => updateField('organization_code', event.target.value)}
              invalid={Boolean(errors.organization_code) || Boolean(invalidFields.organization_code)}
              invalidText={errors.organization_code ?? invalidFields.organization_code}
              required
            />
          </div>
        </section>

        <section aria-labelledby="facility-heading" className="platform-admin__wizard-section">
          <header className="platform-admin__create-heading">
            <h2 id="facility-heading">First facility</h2>
          </header>
          <div className="platform-admin__form-grid">
            <TextInput
              id="facility-name"
              labelText="Facility name"
              value={data.facility_name}
              onChange={(event) => updateField('facility_name', event.target.value)}
              invalid={Boolean(errors.facility_name) || Boolean(invalidFields.facility_name)}
              invalidText={errors.facility_name ?? invalidFields.facility_name}
              required
            />
            <TextInput
              id="facility-code"
              labelText="Facility code"
              helperText="Use uppercase letters, numbers, and underscores."
              value={data.facility_code}
              onChange={(event) => updateField('facility_code', event.target.value)}
              invalid={Boolean(errors.facility_code) || Boolean(invalidFields.facility_code)}
              invalidText={errors.facility_code ?? invalidFields.facility_code}
              required
            />
          </div>
          <div className="platform-admin__form-grid platform-admin__form-grid--three">
            <Select
              id="facility-type"
              labelText="Facility type"
              value={data.facility_type}
              onChange={(event) => updateField('facility_type', event.target.value)}
              invalid={Boolean(errors.facility_type) || Boolean(invalidFields.facility_type)}
              invalidText={errors.facility_type ?? invalidFields.facility_type}
              required
            >
              <SelectItem value="" text="Select a facility type" />
              {facilityReferences.facilityTypes.map((item) => <SelectItem key={item.code} value={item.code} text={item.name} />)}
            </Select>
            <Select
              id="care-level"
              labelText="Care level"
              value={data.care_level}
              onChange={(event) => updateField('care_level', event.target.value)}
              invalid={Boolean(errors.care_level) || Boolean(invalidFields.care_level)}
              invalidText={errors.care_level ?? invalidFields.care_level}
              required
            >
              <SelectItem value="" text="Select a care level" />
              {facilityReferences.careLevels.map((item) => <SelectItem key={item.code} value={item.code} text={item.name} />)}
            </Select>
            <Select
              id="ownership-type"
              labelText="Ownership"
              value={data.ownership_type}
              onChange={(event) => updateField('ownership_type', event.target.value)}
              invalid={Boolean(errors.ownership_type) || Boolean(invalidFields.ownership_type)}
              invalidText={errors.ownership_type ?? invalidFields.ownership_type}
              required
            >
              <SelectItem value="" text="Select an ownership type" />
              {facilityReferences.ownershipTypes.map((item) => <SelectItem key={item.code} value={item.code} text={item.name} />)}
            </Select>
          </div>
        </section>
      </div>
    </DockedPanel>
  );
}

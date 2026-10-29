import { InlineNotification, TextInput } from '@carbon/react';
import { useForm } from '@inertiajs/react';
import type { FormEvent, ReactNode } from 'react';
import { routes } from '@/app/routes';
import { DockedPanel } from '@/components/panels';

export interface StockLocationTypeFormData {
  code: string;
  name: string;
}

export interface AddLocationTypePanelProps {
  open: boolean;
  onClose: () => void;
  onSuccess?: () => void;
  isGeneral?: boolean;
}

export function AddLocationTypePanel({ open, onClose, onSuccess, isGeneral = false }: AddLocationTypePanelProps): ReactNode {
  const locationTypeForm = useForm<StockLocationTypeFormData>({
    code: '',
    name: '',
  });

  function handleSubmit(event: FormEvent): void {
    event.preventDefault();
    locationTypeForm.post(routes.workspaces.inventoryLocationTypesStore, {
      preserveScroll: true,
      onSuccess: () => {
        locationTypeForm.reset();
        onSuccess?.();
        onClose();
      },
    });
  }

  return (
    <DockedPanel
      heading="Add a location type"
      description={isGeneral ? 'Define a custom type shared across this tenant.' : 'Define a custom type for the active facility.'}
      hidden={!open}
      onClose={onClose}
      onSubmit={handleSubmit}
      primaryLabel={locationTypeForm.processing ? 'Saving…' : 'Save type'}
      primaryDisabled={locationTypeForm.processing || !locationTypeForm.data.code.trim() || !locationTypeForm.data.name.trim()}
      secondaryLabel="Cancel"
      width="min(30rem, 100%)"
    >
      <div style={{ display: 'flex', flexDirection: 'column', gap: '1.25rem' }}>
        <InlineNotification
          kind="info"
          title="Scope Note"
          subtitle={isGeneral
            ? 'This type will be available in every facility in the tenant.'
            : 'This type will be available only in the active facility.'}
          hideCloseButton
        />
        <TextInput
          id="inventory-location-type-code"
          labelText="Type code"
          helperText="Letters, numbers, hyphens, and underscores; e.g. LABORATORY, MOBILE_CLINIC"
          placeholder="LABORATORY"
          value={locationTypeForm.data.code}
          onChange={(event) => locationTypeForm.setData('code', event.currentTarget.value)}
          invalid={Boolean(locationTypeForm.errors.code)}
          invalidText={locationTypeForm.errors.code}
          required
        />
        <TextInput
          id="inventory-location-type-name"
          labelText="Type name"
          helperText="Human-readable label, e.g. Laboratory, Mobile Clinic"
          placeholder="Laboratory"
          value={locationTypeForm.data.name}
          onChange={(event) => locationTypeForm.setData('name', event.currentTarget.value)}
          invalid={Boolean(locationTypeForm.errors.name)}
          invalidText={locationTypeForm.errors.name}
          required
        />
      </div>
    </DockedPanel>
  );
}

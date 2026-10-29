import { Button, Select, SelectItem, TextInput } from '@carbon/react';
import { useForm } from '@inertiajs/react';
import type { FormEvent, ReactNode } from 'react';
import { routes } from '@/app/routes';
import { DockedPanel } from '@/components/panels';
import type { StockLocationType } from '../../types';

export interface StockLocationFormData {
  code: string;
  name: string;
  location_type: string;
}

export interface AddLocationPanelProps {
  open: boolean;
  onClose: () => void;
  onSuccess?: () => void;
  locationTypes: StockLocationType[];
  canManageLocationTypes?: boolean;
  onOpenAddType?: () => void;
}

export function AddLocationPanel({
  open,
  onClose,
  onSuccess,
  locationTypes,
  canManageLocationTypes,
  onOpenAddType,
}: AddLocationPanelProps): ReactNode {
  const locationForm = useForm<StockLocationFormData>({
    code: '',
    name: '',
    location_type: 'STORE',
  });

  function handleSubmit(event: FormEvent): void {
    event.preventDefault();
    locationForm.post(routes.workspaces.inventoryLocationsStore, {
      preserveScroll: true,
      onSuccess: () => {
        locationForm.reset();
        onSuccess?.();
        onClose();
      },
    });
  }

  return (
    <DockedPanel
      heading="Add location at this facility"
      description="Create a storage location for inventory, pharmacy, wards, or stores in the active facility."
      hidden={!open}
      onClose={onClose}
      onSubmit={handleSubmit}
      primaryLabel={locationForm.processing ? 'Adding…' : 'Add location'}
      primaryDisabled={locationForm.processing || !locationForm.data.code.trim() || !locationForm.data.name.trim()}
      secondaryLabel="Cancel"
      width="min(32rem, 100%)"
    >
      <div style={{ display: 'flex', flexDirection: 'column', gap: '1.25rem' }}>
        <TextInput
          id="inventory-location-code"
          labelText="Location code"
          placeholder="e.g. PHARM-MAIN, WARD-3-STOCK, STORE-CENTRAL"
          value={locationForm.data.code}
          onChange={(event) => locationForm.setData('code', event.currentTarget.value)}
          invalid={Boolean(locationForm.errors.code)}
          invalidText={locationForm.errors.code}
          required
        />
        <TextInput
          id="inventory-location-name"
          labelText="Location name"
          placeholder="e.g. Main Pharmacy Store, Emergency Room Kit"
          value={locationForm.data.name}
          onChange={(event) => locationForm.setData('name', event.currentTarget.value)}
          invalid={Boolean(locationForm.errors.name)}
          invalidText={locationForm.errors.name}
          required
        />
        <div>
          <Select
            id="inventory-location-type"
            labelText="Location type"
            value={locationForm.data.location_type}
            onChange={(event) => locationForm.setData('location_type', event.currentTarget.value)}
            invalid={Boolean(locationForm.errors.location_type)}
            invalidText={locationForm.errors.location_type}
          >
            {locationTypes.map((type) => (
              <SelectItem key={type.code} value={type.code} text={`${type.name} (${type.code})`} />
            ))}
          </Select>
          {canManageLocationTypes && onOpenAddType ? (
            <div style={{ marginBlockStart: '0.5rem' }}>
              <Button kind="ghost" size="sm" type="button" onClick={onOpenAddType}>
                + Define new location type
              </Button>
            </div>
          ) : null}
        </div>
      </div>
    </DockedPanel>
  );
}

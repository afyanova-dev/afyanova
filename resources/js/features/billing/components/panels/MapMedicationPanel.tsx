import { InlineNotification, Select, SelectItem } from '@carbon/react';
import { useForm } from '@inertiajs/react';
import { useEffect, type FormEvent, type ReactNode } from 'react';
import { routes } from '@/app/routes';
import { DockedPanel } from '@/components/panels';
import type { CatalogItemMapItem, CatalogServiceItem } from '../../types';

export interface MapMedicationPanelProps {
  open: boolean;
  medications: CatalogItemMapItem[];
  /** The full catalog — the panel offers only ACTIVE services as targets. */
  services: CatalogServiceItem[];
  initialItemId: string | null;
  onClose: () => void;
}

interface MapFormData {
  item_id: string;
  billing_service_id: string;
}

/**
 * Maps one medication (`inv_items.id`) to an ACTIVE billing service. Superseding
 * an existing mapping is safe: the prior row becomes immutable history and the
 * handler sweeps any unmapped pending events for the item.
 */
export function MapMedicationPanel({
  open,
  medications,
  services,
  initialItemId,
  onClose,
}: MapMedicationPanelProps): ReactNode {
  const form = useForm<MapFormData>({ item_id: '', billing_service_id: '' });
  const activeServices = services.filter((service) => service.status === 'ACTIVE');
  const selected = medications.find((medication) => medication.invItemId === form.data.item_id) ?? null;

  useEffect(() => {
    if (open) {
      form.setData('item_id', initialItemId ?? '');
      form.setData('billing_service_id', '');
      form.clearErrors();
    }
    // Re-seed only when the panel opens for a new item.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open, initialItemId]);

  function handleSubmit(event: FormEvent): void {
    event.preventDefault();
    form.post(routes.workspaces.billingCatalogItemMaps, {
      preserveScroll: true,
      onSuccess: () => {
        form.reset();
        onClose();
      },
    });
  }

  return (
    <DockedPanel
      heading={selected?.mapped ? 'Change medication mapping' : 'Map medication to a service'}
      description="Dispensing this medication then charges the mapped active service at its effective tariff."
      hidden={!open}
      onClose={onClose}
      onSubmit={handleSubmit}
      primaryLabel={form.processing ? 'Mapping…' : 'Save mapping'}
      primaryDisabled={form.processing || !form.data.item_id || !form.data.billing_service_id}
      secondaryLabel="Cancel"
      width="min(32rem, 100%)"
    >
      <div style={{ display: 'flex', flexDirection: 'column', gap: '1.25rem' }}>
        <Select
          id="billing-map-item"
          labelText="Medication"
          value={form.data.item_id}
          onChange={(event) => form.setData('item_id', event.currentTarget.value)}
          invalid={Boolean(form.errors.item_id)}
          invalidText={form.errors.item_id}
        >
          <SelectItem value="" text="Select a medication…" />
          {medications.map((medication) => (
            <SelectItem
              key={medication.invItemId}
              value={medication.invItemId}
              text={`${medication.code} — ${medication.name}${medication.mapped ? ' (mapped)' : ''}`}
            />
          ))}
        </Select>

        <Select
          id="billing-map-service"
          labelText="Active billing service"
          value={form.data.billing_service_id}
          onChange={(event) => form.setData('billing_service_id', event.currentTarget.value)}
          invalid={Boolean(form.errors.billing_service_id)}
          invalidText={form.errors.billing_service_id}
        >
          <SelectItem value="" text="Select a service…" />
          {activeServices.map((service) => (
            <SelectItem
              key={service.billingServiceId}
              value={service.billingServiceId}
              text={`${service.code} — ${service.name}`}
            />
          ))}
        </Select>

        {activeServices.length === 0 ? (
          <InlineNotification
            kind="warning"
            lowContrast
            hideCloseButton
            title="No active services"
            subtitle="Add a billing service and set its tariff before mapping medications."
          />
        ) : null}

        {selected?.mapped && selected.serviceName ? (
          <InlineNotification
            kind="info"
            lowContrast
            hideCloseButton
            title="Currently mapped"
            subtitle={`${selected.serviceName} (${selected.serviceCode ?? '—'}). Saving supersedes it; the old row is kept as history.`}
          />
        ) : null}

      </div>
    </DockedPanel>
  );
}

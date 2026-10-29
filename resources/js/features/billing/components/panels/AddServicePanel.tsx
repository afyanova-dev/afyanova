import { Select, SelectItem, TextInput } from '@carbon/react';
import { useForm } from '@inertiajs/react';
import type { FormEvent, ReactNode } from 'react';
import { routes } from '@/app/routes';
import { DockedPanel } from '@/components/panels';
import { CATALOG_CATEGORIES } from '../../types';

export interface AddServicePanelProps {
  open: boolean;
  onClose: () => void;
}

interface AddServiceFormData {
  code: string;
  name: string;
  category: string;
}

/**
 * Adds a service to the canonical Billing price list. The new service starts
 * ACTIVE but unpriced — the tariff panel is the second, deliberate step.
 */
export function AddServicePanel({ open, onClose }: AddServicePanelProps): ReactNode {
  const form = useForm<AddServiceFormData>({
    code: '',
    name: '',
    category: 'CONSULTATION',
  });

  function handleSubmit(event: FormEvent): void {
    event.preventDefault();
    form.post(routes.workspaces.billingCatalogServices, {
      preserveScroll: true,
      onSuccess: () => {
        form.reset();
        onClose();
      },
    });
  }

  return (
    <DockedPanel
      heading="Add billing service"
      description="Every billable act is a coded service. Add the service, then set its tariff."
      hidden={!open}
      onClose={onClose}
      onSubmit={handleSubmit}
      primaryLabel={form.processing ? 'Adding…' : 'Add service'}
      primaryDisabled={form.processing || !form.data.code.trim() || !form.data.name.trim()}
      secondaryLabel="Cancel"
      width="min(32rem, 100%)"
    >
      <div style={{ display: 'flex', flexDirection: 'column', gap: '1.25rem' }}>
        <TextInput
          id="billing-service-code"
          labelText="Service code"
          placeholder="e.g. CONSULT, LAB-CBC, MED-PARA500"
          value={form.data.code}
          onChange={(event) => form.setData('code', event.currentTarget.value)}
          invalid={Boolean(form.errors.code)}
          invalidText={form.errors.code}
          required
        />
        <TextInput
          id="billing-service-name"
          labelText="Service name"
          placeholder="e.g. General consultation, Complete blood count"
          value={form.data.name}
          onChange={(event) => form.setData('name', event.currentTarget.value)}
          invalid={Boolean(form.errors.name)}
          invalidText={form.errors.name}
          required
        />
        <Select
          id="billing-service-category"
          labelText="Category"
          value={form.data.category}
          onChange={(event) => form.setData('category', event.currentTarget.value)}
          invalid={Boolean(form.errors.category)}
          invalidText={form.errors.category}
        >
          {CATALOG_CATEGORIES.map((category) => (
            <SelectItem key={category} value={category} text={category} />
          ))}
        </Select>
      </div>
    </DockedPanel>
  );
}

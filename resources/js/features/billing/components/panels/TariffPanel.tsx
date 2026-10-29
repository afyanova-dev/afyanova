import { Checkbox, TextInput } from '@carbon/react';
import { useForm } from '@inertiajs/react';
import type { FormEvent, ReactNode } from 'react';
import { routes } from '@/app/routes';
import { DockedPanel } from '@/components/panels';

export interface TariffPanelProps {
  open: boolean;
  mode: 'set' | 'end';
  serviceId: string | null;
  serviceName: string | null;
  currency: string;
  onClose: () => void;
}

interface TariffFormData {
  amount: string;
  effective_from: string;
  effective_to: string;
  facility_scope: boolean;
}

/**
 * Sets a NEW immutable tariff row or ends the open one. There is no "edit price"
 * — a price change is always a new row with a later `effective_from`, so the
 * history rail stays a truthful record.
 */
export function TariffPanel({
  open,
  mode,
  serviceId,
  serviceName,
  currency,
  onClose,
}: TariffPanelProps): ReactNode {
  const form = useForm<TariffFormData>({
    amount: '',
    effective_from: '',
    effective_to: '',
    facility_scope: true,
  });

  function handleSubmit(event: FormEvent): void {
    event.preventDefault();
    const url = mode === 'set'
      ? routes.workspaces.billingCatalogTariffs
      : routes.workspaces.billingCatalogTariffsEnd;
    const payload = mode === 'set'
      ? {
          billing_service_id: serviceId,
          amount: form.data.amount,
          effective_from: form.data.effective_from,
          facility_scope: form.data.facility_scope,
        }
      : {
          billing_service_id: serviceId,
          effective_to: form.data.effective_to,
          facility_scope: form.data.facility_scope,
        };

    form.transform(() => payload);
    form.post(url, {
      preserveScroll: true,
      onSuccess: () => {
        form.reset();
        onClose();
      },
    });
  }

  const isSet = mode === 'set';

  return (
    <DockedPanel
      heading={isSet ? 'Set a new price' : 'End the open price'}
      description={
        serviceName
          ? `${isSet ? 'A new tariff row for' : 'Close the open tariff for'} ${serviceName}.`
          : undefined
      }
      hidden={!open}
      onClose={onClose}
      onSubmit={handleSubmit}
      primaryLabel={form.processing ? 'Saving…' : isSet ? 'Set price' : 'End price'}
      primaryDisabled={isSet
        ? form.processing || !form.data.amount.trim() || !form.data.effective_from
        : form.processing || !form.data.effective_to}
      secondaryLabel="Cancel"
      width="min(32rem, 100%)"
    >
      <div style={{ display: 'flex', flexDirection: 'column', gap: '1.25rem' }}>
        {isSet ? (
          <TextInput
            id="billing-tariff-amount"
            labelText={`Amount (${currency})`}
            placeholder="e.g. 25000.00"
            value={form.data.amount}
            onChange={(event) => form.setData('amount', event.currentTarget.value)}
            invalid={Boolean(form.errors.amount)}
            invalidText={form.errors.amount}
            required
          />
        ) : null}
        {isSet ? (
          <TextInput
            id="billing-tariff-effective-from"
            type="date"
            labelText="Effective from"
            helperText="The price applies from this date. A later date supersedes the current row."
            value={form.data.effective_from}
            onChange={(event) => form.setData('effective_from', event.currentTarget.value)}
            invalid={Boolean(form.errors.effective_from)}
            invalidText={form.errors.effective_from}
            required
          />
        ) : (
          <TextInput
            id="billing-tariff-effective-to"
            type="date"
            labelText="Effective to"
            helperText="The open row stops charging on this date."
            value={form.data.effective_to}
            onChange={(event) => form.setData('effective_to', event.currentTarget.value)}
            invalid={Boolean(form.errors.effective_to)}
            invalidText={form.errors.effective_to}
            required
          />
        )}
        <Checkbox
          id="billing-tariff-facility-scope"
          labelText={`Scope to the active facility (otherwise the tenant-wide default)`}
          checked={form.data.facility_scope}
          onChange={(_, { checked }) => form.setData('facility_scope', checked)}
        />
      </div>
    </DockedPanel>
  );
}

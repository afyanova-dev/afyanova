import { Select, SelectItem } from '@carbon/react';
import { router } from '@inertiajs/react';
import { useState, type ReactNode } from 'react';
import { routes } from '@/app/routes';
import { DockedPanel } from '@/components/panels';
import { ORDER_PRIORITIES } from '../pharmacyCodes';
import type { RxPrescriptionItem } from '../types/pharmacy';

/**
 * Create-order panel for one ACTIVE prescription. Priority only — the
 * backend owns validation (active prescription, single open order) and
 * surfaces failures through the `order` error bag on the page.
 */
export function CreateOrderPanel({
  prescription,
  onClose,
}: {
  prescription: RxPrescriptionItem;
  onClose: () => void;
}): ReactNode {
  const [priority, setPriority] = useState('ROUTINE');
  const [submitting, setSubmitting] = useState(false);

  function submit(event: React.FormEvent): void {
    event.preventDefault();
    if (submitting) return;
    setSubmitting(true);
    router.post(routes.workspaces.pharmacyOrdersStore, {
      prescription_id: prescription.prescriptionId,
      priority,
    }, {
      preserveScroll: true,
      preserveState: true,
      onFinish: () => {
        setSubmitting(false);
        onClose();
      },
    });
  }

  return (
    <DockedPanel
      heading={`Create order for ${prescription.prescriptionNumber}`}
      primaryLabel={submitting ? 'Creating…' : 'Create order'}
      secondaryLabel="Cancel"
      onClose={onClose}
      onSubmit={submit}
    >
      <p>{prescription.patientDisplayName} · {prescription.patientNumber}</p>
      <Select
        id="pharmacy-order-priority"
        labelText="Priority"
        value={priority}
        onChange={(event) => setPriority(event.currentTarget.value)}
        required
      >
        {ORDER_PRIORITIES.map((option) => (
          <SelectItem key={option.value} value={option.value} text={option.label} />
        ))}
      </Select>
    </DockedPanel>
  );
}

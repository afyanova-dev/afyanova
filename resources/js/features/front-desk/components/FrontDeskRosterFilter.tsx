import type { ReactNode } from 'react';
import { CheckboxFilterPopover } from '@/components/ui';

const STATUS_OPTIONS = [
  { value: 'BOOKED', label: 'Booked' },
  { value: 'ARRIVED', label: 'Arrived' },
  { value: 'ACTIVE', label: 'In service' },
  { value: 'COMPLETED', label: 'Completed' },
] as const;

export function FrontDeskRosterFilter({
  selectedStatuses,
  onApply,
  onReset,
}: {
  selectedStatuses: string[];
  onApply: (statuses: string[]) => void;
  onReset: () => void;
}): ReactNode {
  return (
    <CheckboxFilterPopover
      ariaLabel="Filter roster by status"
      legend="Roster status"
      description="Filters appointments and encounters by status."
      options={STATUS_OPTIONS.map((option) => ({ id: option.value, label: option.label }))}
      selectedIds={selectedStatuses}
      onApply={onApply}
      onReset={onReset}
    />
  );
}

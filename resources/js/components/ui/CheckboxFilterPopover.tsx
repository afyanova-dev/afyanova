import { Button, Checkbox, Layer, Popover, PopoverContent } from '@carbon/react';
import { useId, useState, type ReactNode } from 'react';
import { Filter, Renew } from '@/icons';

export interface CheckboxFilterOption {
  id: string;
  label: string;
}

export function CheckboxFilterPopover({
  ariaLabel,
  legend,
  options,
  selectedIds,
  onApply,
  onReset,
  description,
  resetLabel = 'Reset filters',
  applyLabel = 'Apply filter',
}: {
  ariaLabel: string;
  legend: string;
  options: CheckboxFilterOption[];
  selectedIds: string[];
  onApply: (ids: string[]) => void;
  onReset: () => void;
  description?: ReactNode;
  resetLabel?: string;
  applyLabel?: string;
}): React.JSX.Element {
  const [open, setOpen] = useState(false);
  const [draftIds, setDraftIds] = useState<string[]>(selectedIds);
  const contentId = useId();

  function openFilter(): void {
    setDraftIds([...selectedIds]);
    setOpen(true);
  }

  function applyFilter(): void {
    setOpen(false);
    onApply(draftIds);
  }

  function resetFilter(): void {
    setDraftIds([]);
    setOpen(false);
    onReset();
  }

  return (
    <Layer>
      <Popover open={open} isTabTip align="bottom-end" onRequestClose={() => setOpen(false)}>
        <Button
          kind="ghost"
          size="md"
          hasIconOnly
          renderIcon={Filter}
          iconDescription={ariaLabel}
          tooltipPosition="left"
          aria-label={ariaLabel}
          aria-expanded={open}
          aria-controls={contentId}
          onClick={open ? () => setOpen(false) : openFilter}
        />
        <PopoverContent id={contentId} className="afy-filter-popover">
          <fieldset className="afy-filter-popover__options">
            <legend>{legend}</legend>
            {description ? <p>{description}</p> : null}
            {options.map((option) => (
              <Checkbox
                key={option.id}
                id={`${contentId}-${option.id}`}
                labelText={option.label}
                checked={draftIds.includes(option.id)}
                onChange={(_, { checked }) => {
                  setDraftIds((current) => checked
                    ? [...current, option.id]
                    : current.filter((id) => id !== option.id));
                }}
              />
            ))}
          </fieldset>
          <div className="afy-filter-popover__actions">
            <Button size="lg" renderIcon={Renew} kind="secondary" type="button" onClick={resetFilter}>{resetLabel}</Button>
            <Button size="lg" renderIcon={Filter} kind="primary" type="button" onClick={applyFilter}>{applyLabel}</Button>
          </div>
        </PopoverContent>
      </Popover>
    </Layer>
  );
}

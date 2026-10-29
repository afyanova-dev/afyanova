import { ComboBox } from '@carbon/react';
import type { ReactNode } from 'react';

export interface SearchableComboBoxItem {
  id: string;
  label: string;
}

interface SearchableComboBoxProps {
  id: string;
  titleText: string;
  items: SearchableComboBoxItem[];
  selectedId: string;
  onChange: (id: string) => void;
  helperText?: string;
  invalid?: boolean;
  invalidText?: string;
  required?: boolean;
  size?: 'sm' | 'md' | 'lg';
  showAllOnOpen?: boolean;
}

export function SearchableComboBox({
  id,
  titleText,
  items,
  selectedId,
  onChange,
  helperText,
  invalid,
  invalidText,
  required,
  size,
  showAllOnOpen = false,
}: SearchableComboBoxProps): ReactNode {
  const selectedItem = items.find((item) => item.id === selectedId) ?? null;

  return (
    <ComboBox<SearchableComboBoxItem>
      id={id}
      titleText={titleText}
      helperText={helperText}
      items={items}
      selectedItem={selectedItem}
      itemToString={(item) => item?.label ?? ''}
      onChange={({ selectedItem }) => onChange(selectedItem?.id ?? '')}
      shouldFilterItem={({ item, inputValue }) => {
        const query = (inputValue ?? '').trim().toLocaleLowerCase();

        return (showAllOnOpen && query === selectedItem?.label.trim().toLocaleLowerCase())
          || item.label.toLocaleLowerCase().includes(query);
      }}
      placeholder="Type to search"
      invalid={invalid}
      invalidText={invalidText}
      required={required}
      size={size}
    />
  );
}

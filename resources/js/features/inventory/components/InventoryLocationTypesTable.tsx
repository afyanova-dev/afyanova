import { Button, TableToolbarSearch, Tag } from '@carbon/react';
import type { ReactNode } from 'react';
import { CarbonDataTable } from '@/components/ui/CarbonDataTable';
import type { StockLocationType } from '../types';

interface InventoryLocationTypesTableProps {
  locationTypes: StockLocationType[];
  canManageLocationTypes: boolean;
  onAddLocationType: () => void;
}

export function InventoryLocationTypesTable({
  locationTypes,
  canManageLocationTypes,
  onAddLocationType,
}: InventoryLocationTypesTableProps): ReactNode {
  const rows = locationTypes.map((type) => ({
    id: type.code,
    code: type.code,
    name: type.name,
    scope: type.scope === 'BUILT_IN' ? 'Built-in' : type.scope === 'TENANT' ? 'Tenant-wide' : 'Facility-local',
  }));
  const headers = [
    { key: 'code', header: 'Code' },
    { key: 'name', header: 'Name' },
    { key: 'scope', header: 'Scope' },
  ];

  return (
    <section aria-label="Inventory location types">
      <CarbonDataTable
        rows={rows}
        headers={headers}
        title="Location types"
        ariaLabel="Inventory location types"
        emptyState={<p>No location types configured.</p>}
        toolbar={(onInputChange) => (
          <>
            <TableToolbarSearch
              persistent
              onChange={onInputChange}
              placeholder="Search location types"
              aria-label="Search location types"
            />
            {canManageLocationTypes ? (
              <Button size="lg" onClick={onAddLocationType}>Add location type</Button>
            ) : null}
          </>
        )}
        renderCell={(cell) => cell.info.header === 'scope' ? (
          <Tag type="cool-gray" size="sm">{String(cell.value)}</Tag>
        ) : cell.info.header === 'code' ? (
          <code>{String(cell.value)}</code>
        ) : cell.value}
      />
    </section>
  );
}

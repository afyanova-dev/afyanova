import {
  OverflowMenuItem,
  Pagination,
  TableToolbarMenu,
  TableToolbarSearch,
  type DataTableSortState,
} from '@carbon/react';
import { useEffect, useMemo, useState, type ReactNode } from 'react';
import { CarbonDataTable, EmptyState } from '@/components/ui';
import { Filter } from '@/icons';
import type {
  FacilityStockPagination,
  FacilityStockQuery,
  FacilityStockRow,
  StockLocation,
} from '../types';

export interface FacilityStockSectionProps {
  rows?: FacilityStockRow[];
  pagination?: FacilityStockPagination;
  locations: StockLocation[];
  onQueryChange: (query: FacilityStockQuery) => void;
}

const SORT_BY_HEADER = {
  item: 'code',
  location: 'location',
  onHand: 'on_hand',
  reserved: 'reserved',
  available: 'available',
} as const;

/**
 * Facility-wide stock-on-hand (`GetStockByFacility`) — one row per
 * (item, location, batch) across the active facility. This is the facility
 * altitude: it does not replace the location-first drill-down in the Stock
 * lookup section, and available quantity here does not by itself confirm
 * dispensing eligibility.
 */
export function FacilityStockSection({
  rows,
  pagination,
  locations,
  onQueryChange,
}: FacilityStockSectionProps): ReactNode {
  const [search, setSearch] = useState(pagination?.search ?? '');

  useEffect(() => {
    setSearch(pagination?.search ?? '');
  }, [pagination?.search]);

  const locationNames = useMemo(
    () => new Map(locations.map((location) => [location.id, location.name])),
    [locations],
  );

  const tableRows = useMemo(
    () => (rows ?? []).map((row) => {
      const name = locationNames.get(row.locationId);
      return {
        id: row.balanceId,
        item: `${row.itemCode} — ${row.itemName}`,
        location: name ? `${row.locationCode} — ${name}` : row.locationCode,
        batch: row.batchNumber ?? 'Not batch-tracked',
        expiry: row.expiryDate ?? '—',
        onHand: `${row.onHand} ${row.uom}`,
        reserved: `${row.reserved} ${row.uom}`,
        available: `${row.available} ${row.uom}`,
      };
    }),
    [rows, locationNames],
  );

  const headers = [
    { key: 'item', header: 'Item' },
    { key: 'location', header: 'Location' },
    { key: 'batch', header: 'Batch', isSortable: false },
    { key: 'expiry', header: 'Expiry', isSortable: false },
    { key: 'onHand', header: 'On hand' },
    { key: 'reserved', header: 'Reserved' },
    { key: 'available', header: 'Available' },
  ];

  const page = pagination ?? {
    currentPage: 1,
    lastPage: 1,
    perPage: 15,
    total: rows?.length ?? 0,
    sortBy: 'code' as const,
    sortDirection: 'asc' as const,
    search: '',
    locationId: null,
  };

  const currentLocationLabel = page.locationId
    ? locations.find((location) => location.id === page.locationId)?.code ?? 'Selected location'
    : 'All locations';

  function updateQuery(changes: Partial<FacilityStockQuery>): void {
    onQueryChange({
      page: changes.page ?? page.currentPage,
      perPage: changes.perPage ?? page.perPage,
      sortBy: changes.sortBy ?? page.sortBy,
      sortDirection: changes.sortDirection ?? page.sortDirection,
      search: changes.search ?? page.search,
      locationId: changes.locationId ?? page.locationId,
    });
  }

  function handleSort(headerKey: string, direction: DataTableSortState): void {
    const sortBy = SORT_BY_HEADER[headerKey as keyof typeof SORT_BY_HEADER];
    if (!sortBy) return;

    updateQuery({
      page: 1,
      sortBy,
      sortDirection: direction === 'NONE' ? 'asc' : direction.toLowerCase() as 'asc' | 'desc',
    });
  }

  return (
    <div style={{ marginBlockEnd: '2rem' }}>
      <div style={{ marginBlockEnd: '1.25rem' }}>
        <h2 style={{ fontSize: '1.25rem', fontWeight: 600 }}>
          Stock on hand
        </h2>
        <p style={{ color: 'var(--cds-text-secondary, #525252)' }}>
          Every recorded on-hand, reserved, and available balance at this facility, by item, location,
          and batch. Use Stock lookup to drill into one item at one location.
        </p>
      </div>

      <CarbonDataTable
        rows={tableRows}
        headers={headers}
        title={`Stock on hand (${page.total})`}
        ariaLabel="Stock on hand across the facility"
        isSortable
        onSort={handleSort}
        emptyState={(
          <EmptyState
            title="No stock on hand matches the current filter"
            description="Receive or adjust stock, or clear the search and location filter, to see balances here."
          />
        )}
        toolbar={() => (
          <>
            <TableToolbarSearch
              persistent
              value={search}
              onChange={(_event, value) => setSearch(value ?? '')}
              onClear={() => {
                setSearch('');
                updateQuery({ page: 1, search: '' });
              }}
              onKeyDown={(event) => {
                if (event.key === 'Enter') {
                  event.preventDefault();
                  updateQuery({ page: 1, search: search.trim() });
                }
              }}
              placeholder="Search items and locations"
              aria-label="Search stock on hand"
            />
            <TableToolbarMenu
              renderIcon={Filter}
              iconDescription={`Filter by location: ${currentLocationLabel}`}
            >
              <OverflowMenuItem
                itemText="All locations"
                isDelete={false}
                onClick={() => updateQuery({ page: 1, locationId: null })}
              />
              {locations.map((location) => (
                <OverflowMenuItem
                  key={location.id}
                  itemText={`${location.code} — ${location.name}`}
                  isDelete={false}
                  onClick={() => updateQuery({ page: 1, locationId: location.id })}
                />
              ))}
            </TableToolbarMenu>
          </>
        )}
      />

      {page.total > 0 ? (
        <Pagination
          id="facility-stock-pagination"
          aria-label="Stock on hand pages"
          page={page.currentPage}
          pageSize={page.perPage}
          pageSizes={[15, 30, 50, 100]}
          totalItems={page.total}
          isLastPage={page.currentPage >= page.lastPage}
          size="lg"
          onChange={({ page: nextPage, pageSize }) => updateQuery({ page: nextPage, perPage: pageSize })}
        />
      ) : null}

      <p style={{ fontSize: '0.875rem', color: 'var(--cds-text-secondary, #525252)', marginBlockStart: '0.5rem' }}>
        Available quantity is on-hand less reserved stock. It may include expired batches and does not
        by itself confirm that stock is eligible for dispensing.
      </p>
    </div>
  );
}

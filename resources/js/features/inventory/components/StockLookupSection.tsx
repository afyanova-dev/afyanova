import {
  Button,
  Pagination,
  Select,
  SelectItem,
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
  TableToolbarSearch,
  Tag,
  type DataTableSortState,
} from '@carbon/react';
import { useEffect, useMemo, useState, type FormEvent, type ReactNode } from 'react';
import { CarbonDataTable } from '@/components/ui/CarbonDataTable';
import { SearchableComboBox } from '@/components/ui/SearchableComboBox';
import type {
  InventoryItem,
  LocationItemListQuery,
  LocationItemPagination,
  StockBalance,
  StockLocation,
  LocationItemSummary,
} from '../types';

export interface StockLookupSectionProps {
  items: InventoryItem[];
  locations: StockLocation[];
  selectedItemId: string | null;
  selectedLocationId: string | null;
  departmentScoped?: boolean;
  departmentName?: string | null;
  balances: StockBalance[];
  locationItems?: LocationItemSummary[];
  locationItemPagination?: LocationItemPagination;
  onSelectStock: (itemId: string, locationId: string) => void;
  onLocationStockQueryChange: (query: LocationItemListQuery) => void;
  onViewLedger?: (itemId: string, locationId: string) => void;
}

export function StockLookupSection({
  items,
  locations,
  selectedItemId,
  selectedLocationId,
  departmentScoped = false,
  departmentName = null,
  balances,
  locationItems,
  locationItemPagination,
  onSelectStock,
  onLocationStockQueryChange,
  onViewLedger,
}: StockLookupSectionProps): ReactNode {
  const [itemId, setItemId] = useState(selectedItemId ?? '');
  const [locationId, setLocationId] = useState(selectedLocationId ?? '');
  const [locationSearch, setLocationSearch] = useState(locationItemPagination?.search ?? '');

  useEffect(() => {
    setItemId(selectedItemId ?? '');
    setLocationId(selectedLocationId ?? '');
  }, [selectedItemId, selectedLocationId]);

  useEffect(() => {
    setLocationSearch(locationItemPagination?.search ?? '');
  }, [locationItemPagination?.search, selectedLocationId]);

  const currentItem = useMemo(
    () => items.find((item) => item.id === (selectedItemId || itemId)),
    [items, selectedItemId, itemId],
  );

  const currentLocation = useMemo(
    () => locations.find((loc) => loc.id === (selectedLocationId || locationId)),
    [locations, selectedLocationId, locationId],
  );
  const soleLocation = locations.length === 1 ? locations[0] : undefined;

  // Aggregated quantities across batches
  const totals = useMemo(() => {
    let onHand = 0;
    let reserved = 0;
    let available = 0;
    for (const b of balances) {
      onHand += parseFloat(b.onHand) || 0;
      reserved += parseFloat(b.reserved) || 0;
      available += parseFloat(b.available) || 0;
    }
    return { onHand, reserved, available };
  }, [balances]);

  function handleSubmit(event: FormEvent<HTMLFormElement>): void {
    event.preventDefault();
    if (itemId && locationId) {
      onSelectStock(itemId, locationId);
    }
  }

  const rows = useMemo(() => {
    return balances.map((balance, index) => ({
      id: `${balance.batchNumber ?? 'unbatched'}-${index}`,
      batch: balance.batchNumber ?? 'Not batch-tracked',
      expiry: balance.expiryDate ?? '—',
      onHand: `${balance.onHand} ${balance.unitOfMeasure}`,
      reserved: `${balance.reserved} ${balance.unitOfMeasure}`,
      available: `${balance.available} ${balance.unitOfMeasure}`,
    }));
  }, [balances]);

  const headers = [
    { key: 'batch', header: 'Batch' },
    { key: 'expiry', header: 'Expiry' },
    { key: 'onHand', header: 'On hand' },
    { key: 'reserved', header: 'Reserved' },
    { key: 'available', header: 'Available' },
  ];

  // Location-first table (per-item totals)
  const locationRows = useMemo(() => {
    return (locationItems ?? []).map((it) => ({
      id: it.itemId,
      code: it.itemCode,
      name: it.itemName,
      onHand: `${it.onHand} ${it.uom}`,
      reserved: `${it.reserved} ${it.uom}`,
      available: `${it.available} ${it.uom}`,
    }));
  }, [locationItems]);

  const locationItemsById = useMemo(
    () => new Map((locationItems ?? []).map((it) => [it.itemId, it])),
    [locationItems],
  );

  const locationHeaders = [
    { key: 'code', header: 'Code' },
    { key: 'name', header: 'Name' },
    { key: 'onHand', header: 'On hand' },
    { key: 'reserved', header: 'Reserved' },
    { key: 'available', header: 'Available' },
    { key: 'actions', header: 'Actions', isSortable: false },
  ];
  const pagination = locationItemPagination ?? {
    currentPage: 1,
    lastPage: 1,
    perPage: 15,
    total: locationItems?.length ?? 0,
    sortBy: 'code' as const,
    sortDirection: 'asc' as const,
    search: '',
  };

  function updateLocationStockQuery(changes: Partial<LocationItemListQuery>): void {
    onLocationStockQueryChange({
      page: changes.page ?? pagination.currentPage,
      perPage: changes.perPage ?? pagination.perPage,
      sortBy: changes.sortBy ?? pagination.sortBy,
      sortDirection: changes.sortDirection ?? pagination.sortDirection,
      search: changes.search ?? pagination.search,
    });
  }

  function setLocationSearchAndQuery(search: string): void {
    setLocationSearch(search);
    updateLocationStockQuery({ page: 1, search });
  }

  function handleLocationStockSort(headerKey: string, direction: DataTableSortState): void {
    const sortBy = ({
      code: 'code',
      name: 'name',
      onHand: 'on_hand',
      reserved: 'reserved',
      available: 'available',
    } as const)[headerKey as 'code' | 'name' | 'onHand' | 'reserved' | 'available'];

    if (!sortBy) return;

    updateLocationStockQuery({
      page: 1,
      sortBy,
      sortDirection: direction === 'NONE' ? 'asc' : direction.toLowerCase() as 'asc' | 'desc',
    });
  }

  return (
    <section aria-labelledby="stock-lookup-heading" style={{ marginBlockEnd: '2rem' }}>
      <div style={{ marginBlockEnd: '1.25rem' }}>
        <h2 id="stock-lookup-heading" style={{ fontSize: '1.25rem', fontWeight: 600 }}>
          Stock by location
        </h2>
        <p style={{ color: 'var(--cds-text-secondary, #525252)' }}>
          Inspect recorded on-hand, reserved, and available balances for an item at a selected location, including batch details.
        </p>
      </div>

      <form
        onSubmit={handleSubmit}
        style={{
          display: 'flex',
          flexWrap: 'wrap',
          gap: '1rem',
          alignItems: 'flex-end',
          background: 'var(--cds-layer-01, #f4f4f4)',
          padding: '1.25rem',
          borderRadius: '4px',
          marginBlockEnd: '1.5rem',
        }}
      >
        <div style={{ flex: '1 1 18rem', minWidth: '16rem' }}>
          <SearchableComboBox
            id="stock-item"
            titleText="Item"
            items={items.map((item) => ({
              id: item.id,
              label: `${item.code} — ${item.name} (${item.itemType})`,
            }))}
            selectedId={itemId}
            onChange={setItemId}
          />
        </div>

        {departmentScoped && soleLocation ? (
          <div style={{ flex: '1 1 18rem', minWidth: '16rem' }}>
            <span className="cds--label">Department</span>
            <p style={{ margin: 0, minHeight: '2.5rem', display: 'flex', alignItems: 'center' }}>
              {departmentName || soleLocation.name}
            </p>
            {departmentName ? (
              <p style={{ margin: 0, fontSize: '0.875rem', color: 'var(--cds-text-secondary, #525252)' }}>
                Stock location: {soleLocation.name}
              </p>
            ) : null}
          </div>
        ) : (
          <div style={{ flex: '1 1 18rem', minWidth: '16rem' }}>
            <Select
              id="stock-location"
              labelText="Location"
              value={locationId}
              onChange={(event) => {
                const selectedId = event.currentTarget.value;
                setLocationId(selectedId);
                if (selectedId) {
                  onSelectStock(itemId, selectedId);
                }
              }}
            >
              <SelectItem value="" text="Select a location" />
              {locations.map((location) => (
                <SelectItem key={location.id} value={location.id} text={`${location.code} — ${location.name}`} />
              ))}
            </Select>
          </div>
        )}

        <Button type="submit" disabled={!itemId || !locationId} size="md">
          View stock
        </Button>
        <Button
          type="button"
          kind="secondary"
          disabled={!locationId}
          onClick={() => locationId && onSelectStock('', locationId)}
          size="md"
        >
          View all items at location
        </Button>
      </form>

      {items.length === 0 || locations.length === 0 ? (
        <p style={{ color: 'var(--cds-text-secondary, #525252)' }}>
          {items.length === 0
            ? 'No active inventory items are configured for this tenant.'
            : 'No active stock locations are configured at this facility.'}{' '}
          Ask an administrator to configure the catalogue and locations before receiving stock.
        </p>
      ) : null}

      {selectedLocationId && !selectedItemId && currentLocation ? (
        <div style={{ display: 'flex', flexDirection: 'column', gap: '1.25rem' }}>
          <div
            style={{
              display: 'flex',
              alignItems: 'center',
              justifyContent: 'space-between',
              gap: '1rem',
              padding: '0.5rem 0.25rem',
            }}
          >
            <div>
              <h3 style={{ margin: 0, fontSize: '1.125rem', fontWeight: 600 }}>
                Stock at {currentLocation.name}
              </h3>
              <p style={{ margin: 0, fontSize: '0.875rem', color: 'var(--cds-text-secondary, #525252)' }}>
                Items recorded at this location and their aggregate quantities across batches.
              </p>
            </div>
          </div>

          <CarbonDataTable
            rows={locationRows}
            headers={locationHeaders}
            title={`Location stock (${pagination.total})`}
            ariaLabel="Stock by item at location"
            isSortable
            onSort={handleLocationStockSort}
            emptyState={<p>No stock is recorded at this location.</p>}
            toolbar={() => (
              <>
                <TableToolbarSearch
                  persistent
                  value={locationSearch}
                  onChange={(_event, value) => setLocationSearch(value ?? '')}
                  onClear={() => setLocationSearchAndQuery('')}
                  onKeyDown={(event) => {
                    if (event.key === 'Enter') {
                      event.preventDefault();
                      setLocationSearchAndQuery(locationSearch.trim());
                    }
                  }}
                  placeholder="Search items at this location"
                  aria-label="Search items at location"
                />
              </>
            )}
            getExpandRowLabel={(row) => {
              const item = locationItemsById.get(row.id);
              return item ? `${item.itemCode} — ${item.itemName}` : String(row.id);
            }}
            renderExpandedRow={(row) => {
              const item = locationItemsById.get(row.id);
              if (!item) return null;

              return (
                <div aria-label={`Batches for ${item.itemCode}`}>
                  <h4 style={{ margin: '0 0 0.75rem', fontSize: '1rem', fontWeight: 600 }}>
                    Batches for {item.itemName} ({item.itemCode})
                  </h4>
                  <Table aria-label={`Batches for ${item.itemCode}`} size="sm">
                    <TableHead>
                      <TableRow>
                        <TableHeader>Batch</TableHeader>
                        <TableHeader>Expiry</TableHeader>
                        <TableHeader>On hand</TableHeader>
                        <TableHeader>Reserved</TableHeader>
                        <TableHeader>Available</TableHeader>
                      </TableRow>
                    </TableHead>
                    <TableBody>
                      {item.batches.map((batch) => (
                        <TableRow key={batch.batchId ?? 'unbatched'}>
                          <TableCell>{batch.batchNumber ?? 'No batch recorded'}</TableCell>
                          <TableCell>{batch.expiryDate ?? '—'}</TableCell>
                          <TableCell>{batch.onHand} {batch.uom}</TableCell>
                          <TableCell>{batch.reserved} {batch.uom}</TableCell>
                          <TableCell>{batch.available} {batch.uom}</TableCell>
                        </TableRow>
                      ))}
                    </TableBody>
                  </Table>
                </div>
              );
            }}
            renderCell={(cell, row) => {
              if (cell.info.header === 'actions') {
                return (
                  <div style={{ display: 'flex', gap: '0.5rem', alignItems: 'center' }}>
                    <Button size="sm" kind="ghost" onClick={() => onSelectStock(row.id, selectedLocationId)}>
                      Inspect item
                    </Button>
                  </div>
                );
              }
              return cell.value;
            }}
          />
          {pagination.total > 0 ? (
            <Pagination
              id="location-stock-pagination"
              aria-label="Location stock pages"
              page={pagination.currentPage}
              pageSize={pagination.perPage}
              pageSizes={[15, 30, 50, 100]}
              totalItems={pagination.total}
              isLastPage={pagination.currentPage >= pagination.lastPage}
              size="lg"
              onChange={({ page, pageSize }) => updateLocationStockQuery({ page, perPage: pageSize })}
            />
          ) : null}
        </div>
      ) : null}

      {selectedItemId && selectedLocationId && currentItem && currentLocation ? (
        <div style={{ display: 'flex', flexDirection: 'column', gap: '1.25rem' }}>
          {/* Quick Context Card */}
          <div
            style={{
              display: 'flex',
              flexWrap: 'wrap',
              justifyContent: 'space-between',
              alignItems: 'center',
              gap: '1rem',
              padding: '1rem 1.25rem',
              border: '1px solid var(--cds-border-subtle, #e0e0e0)',
              borderRadius: '4px',
              background: 'var(--cds-layer, #ffffff)',
            }}
          >
            <div>
              <div style={{ display: 'flex', alignItems: 'center', gap: '0.5rem', flexWrap: 'wrap' }}>
                <span style={{ fontSize: '1.125rem', fontWeight: 600 }}>{currentItem.name}</span>
                <code style={{ fontSize: '0.875rem', color: 'var(--cds-text-secondary, #525252)' }}>({currentItem.code})</code>
                <Tag type="blue" size="sm">{currentItem.itemType}</Tag>
                <Tag type="cool-gray" size="sm">{currentItem.unitOfMeasure}</Tag>
              </div>
              <div style={{ fontSize: '0.875rem', color: 'var(--cds-text-secondary, #525252)', marginBlockStart: '0.25rem' }}>
                Location: <strong>{currentLocation.name}</strong> ({currentLocation.code})
              </div>
            </div>

            <div style={{ display: 'flex', gap: '1rem', alignItems: 'center', flexWrap: 'wrap' }}>
              <div style={{ textAlign: 'center', minWidth: '5rem' }}>
                <div style={{ fontSize: '0.75rem', textTransform: 'uppercase', color: 'var(--cds-text-secondary, #525252)' }}>On hand</div>
                <div style={{ fontSize: '1.25rem', fontWeight: 700, color: 'var(--cds-text-primary, #161616)' }}>
                  {totals.onHand} <span style={{ fontSize: '0.875rem', fontWeight: 400 }}>{currentItem.unitOfMeasure}</span>
                </div>
              </div>
              <div style={{ width: '1px', height: '2rem', background: 'var(--cds-border-subtle, #e0e0e0)' }} />
              <div style={{ textAlign: 'center', minWidth: '5rem' }}>
                <div style={{ fontSize: '0.75rem', textTransform: 'uppercase', color: 'var(--cds-text-secondary, #525252)' }}>Reserved</div>
                <div style={{ fontSize: '1.25rem', fontWeight: 700, color: 'var(--cds-support-warning, #f1c21b)' }}>
                  {totals.reserved} <span style={{ fontSize: '0.875rem', fontWeight: 400 }}>{currentItem.unitOfMeasure}</span>
                </div>
              </div>
              <div style={{ width: '1px', height: '2rem', background: 'var(--cds-border-subtle, #e0e0e0)' }} />
              <div style={{ textAlign: 'center', minWidth: '5rem' }}>
                <div style={{ fontSize: '0.75rem', textTransform: 'uppercase', color: 'var(--cds-text-secondary, #525252)' }}>Available</div>
                <div style={{ fontSize: '1.25rem', fontWeight: 700, color: totals.available > 0 ? 'var(--cds-support-success, #24a148)' : 'var(--cds-text-muted, #8d8d8d)' }}>
                  {totals.available} <span style={{ fontSize: '0.875rem', fontWeight: 400 }}>{currentItem.unitOfMeasure}</span>
                </div>
              </div>

              {onViewLedger ? (
                <div style={{ marginInlineStart: '0.5rem' }}>
                  <Button
                    size="sm"
                    kind="ghost"
                    onClick={() => onViewLedger(selectedItemId, selectedLocationId)}
                  >
                    View movement ledger
                  </Button>
                </div>
              ) : null}

            </div>
          </div>

          <CarbonDataTable
            rows={rows}
            headers={headers}
            title="Stock balance"
            ariaLabel="Stock balance by batch"
            emptyState={<p>No stock is recorded for this item at the selected location.</p>}
            toolbar={(onInputChange) => (
              <TableToolbarSearch
                persistent
                onChange={onInputChange}
                placeholder="Search batches"
                aria-label="Search stock batches"
              />
            )}
          />

          <p style={{ fontSize: '0.875rem', color: 'var(--cds-text-secondary, #525252)', marginBlockStart: '0.5rem' }}>
            Available quantity is on-hand less reserved stock. It may include expired batches and does not by itself
            confirm that stock is eligible for dispensing.
          </p>
        </div>
      ) : null}
    </section>
  );
}

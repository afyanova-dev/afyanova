import { Button, OverflowMenuItem, TableToolbarMenu, TableToolbarSearch, Tag } from '@carbon/react';
import { router } from '@inertiajs/react';
import { useMemo, useState, type ReactNode } from 'react';
import { Filter } from '@/icons';
import { routes } from '@/app/routes';
import { CarbonDataTable } from '@/components/ui/CarbonDataTable';
import type { StockLocation, StockLocationType } from '../types';

export interface InventoryLocationsTableProps {
  locations: StockLocation[];
  locationTypes: StockLocationType[];
  canManageLocations: boolean;
  canManageLocationTypes?: boolean;
  onAddLocation?: () => void;
  onAddLocationType?: () => void;
  onInspectLocation?: (locationId: string) => void;
}

export function InventoryLocationsTable({
  locations,
  locationTypes,
  canManageLocations,
  canManageLocationTypes,
  onAddLocation,
  onAddLocationType,
  onInspectLocation,
}: InventoryLocationsTableProps): ReactNode {
  const [locationType, setLocationType] = useState('');
  const [activeFilter, setActiveFilter] = useState<'ALL' | 'ACTIVE' | 'INACTIVE'>('ALL');

  const uniqueLocationTypes = useMemo(
    () => [...new Set(locations.map((loc) => loc.locationType))].sort(),
    [locations],
  );

  const filteredLocations = useMemo(() => {
    return locations.filter((loc) => {
      const matchesType = locationType === '' || loc.locationType === locationType;
      const matchesStatus = activeFilter === 'ALL'
        || (activeFilter === 'ACTIVE' ? loc.active : !loc.active);
      return matchesType && matchesStatus;
    });
  }, [locations, locationType, activeFilter]);

  const locationsById = useMemo(
    () => new Map(locations.map((loc) => [loc.id, loc])),
    [locations],
  );

  const rows = useMemo(() => {
    return filteredLocations.map((loc) => {
      const typeSummary = locationTypes.find((cand) => cand.code === loc.locationType);
      return {
        id: loc.id,
        code: loc.code,
        name: loc.name,
        type: typeSummary?.name ?? loc.locationType,
        status: loc.active ? 'Active' : 'Inactive',
      };
    });
  }, [filteredLocations, locationTypes]);


  const headers = [
    { key: 'code', header: 'Code' },
    { key: 'name', header: 'Name' },
    { key: 'type', header: 'Type' },
    { key: 'status', header: 'Status' },
    { key: 'actions', header: 'Actions' },
  ];

  return (
    <section aria-labelledby="inventory-locations-heading" style={{ marginBlockStart: '2rem' }}>
      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', flexWrap: 'wrap', gap: '1rem', marginBlockEnd: '1rem' }}>
        <div>
          <h3 id="inventory-locations-heading" style={{ fontSize: '1.125rem', fontWeight: 600, margin: 0 }}>
            Locations at this facility
          </h3>
          <p style={{ fontSize: '0.875rem', color: 'var(--cds-text-secondary, #525252)', margin: 0 }}>
            Facility-scoped stock locations such as central stores, wards, labs, and pharmacies.
          </p>
        </div>
      </div>

      <CarbonDataTable
        rows={rows}
        headers={headers}
        title={`Locations (${filteredLocations.length} of ${locations.length})`}
        ariaLabel="Facility stock locations"
        emptyState={<p>No active locations match the selected search and filters.</p>}
        toolbar={(onInputChange) => (
          <>
            <TableToolbarSearch
              persistent
              onChange={onInputChange}
              placeholder="Search facility locations"
              aria-label="Search facility locations"
            />
            <TableToolbarMenu
              renderIcon={Filter}
              iconDescription={`Filter by location type: ${locationTypes.find((type) => type.code === locationType)?.name ?? 'All types'}`}
            >
              <OverflowMenuItem
                itemText={`All types (${locations.length})`}
                isDelete={false}
                onClick={() => setLocationType('')}
              />
              {uniqueLocationTypes.map((type) => (
                <OverflowMenuItem
                  key={type}
                  itemText={`${locationTypes.find((candidate) => candidate.code === type)?.name ?? type} (${locations.filter((location) => location.locationType === type).length})`}
                  isDelete={false}
                  onClick={() => setLocationType(type)}
                />
              ))}
            </TableToolbarMenu>
            {canManageLocations ? (
              <TableToolbarMenu
                renderIcon={Filter}
                iconDescription={`Filter by status: ${activeFilter === 'ALL' ? 'All statuses' : activeFilter.toLowerCase()}`}
              >
                {(['ALL', 'ACTIVE', 'INACTIVE'] as const).map((status) => (
                  <OverflowMenuItem
                    key={status}
                    itemText={`${status === 'ALL' ? 'All statuses' : status === 'ACTIVE' ? 'Active' : 'Inactive'} (${locations.filter((location) => status === 'ALL' || location.active === (status === 'ACTIVE')).length})`}
                    isDelete={false}
                    onClick={() => setActiveFilter(status)}
                  />
                ))}
              </TableToolbarMenu>
            ) : null}
            {canManageLocationTypes && onAddLocationType ? (
              <Button kind="ghost" size="lg" onClick={onAddLocationType}>
                Add location type
              </Button>
            ) : null}
            {canManageLocations && onAddLocation ? (
              <Button size="lg" onClick={onAddLocation}>
                Add facility location
              </Button>
            ) : null}
          </>
        )}
        renderCell={(cell, row) => {
          if (cell.info.header === 'code') {
            return (
              <code style={{ fontSize: '0.875rem', fontWeight: 600, color: 'var(--cds-text-primary, #161616)' }}>
                {String(cell.value)}
              </code>
            );
          }
          if (cell.info.header === 'type') {
            const rawLocation = locationsById.get(row.id);
            const typeSummary = locationTypes.find((cand) => cand.code === rawLocation?.locationType);
            const rawScope = typeSummary?.scope ?? 'BUILT_IN';
            return (
              <div style={{ display: 'flex', alignItems: 'center', gap: '0.5rem' }}>
                <span>{String(cell.value)}</span>
                {rawScope !== 'BUILT_IN' ? (
                  <Tag type="cool-gray" size="sm">
                    {rawScope === 'TENANT' ? 'Tenant-wide' : 'Facility-local'}
                  </Tag>
                ) : null}
              </div>
            );
          }
          if (cell.info.header === 'status') {
            return (
              <Tag type={cell.value === 'Active' ? 'green' : 'gray'} size="sm">
                {String(cell.value)}
              </Tag>
            );
          }
          if (cell.info.header === 'actions') {
            const rawLocation = locationsById.get(row.id);
            if (!rawLocation) return null;
            return (
              <div style={{ display: 'flex', gap: '0.5rem', alignItems: 'center' }}>
                {onInspectLocation ? (
                  <Button
                    kind="ghost"
                    size="sm"
                    onClick={() => onInspectLocation(rawLocation.id)}
                  >
                    View stock
                  </Button>
                ) : null}
                {canManageLocations ? (
                  <Button
                    kind={rawLocation.active ? 'danger--tertiary' : 'tertiary'}
                    size="sm"
                    onClick={() => router.patch(routes.workspaces.inventoryLocationStatus(rawLocation.id), {
                      active: !rawLocation.active,
                    }, { preserveScroll: true })}
                  >
                    {rawLocation.active ? 'Deactivate' : 'Reactivate'}
                  </Button>
                ) : null}
              </div>
            );
          }
          return cell.value;
        }}
      />
    </section>
  );
}

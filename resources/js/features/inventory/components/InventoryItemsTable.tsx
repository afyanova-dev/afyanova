import { Button, OverflowMenuItem, TableToolbarMenu, TableToolbarSearch, Tag } from '@carbon/react';
import { router } from '@inertiajs/react';
import { useMemo, useState, type ReactNode } from 'react';
import { routes } from '@/app/routes';
import { CarbonDataTable, EmptyState, StatisticsCards } from '@/components/ui';
import { Box, Checkmark, Filter, List } from '@/icons';
import type { InventoryItem } from '../types';

export interface InventoryItemsTableProps {
  items: InventoryItem[];
  canManageItems: boolean;
  onAddItem?: () => void;
  onInspectStock?: (itemId: string) => void;
}

/** Trim the 4-decimal storage scale for display (`100.0000` → `100`). */
function formatQuantity(value: string): string {
  const parsed = Number.parseFloat(value);

  return Number.isFinite(parsed) ? String(parsed) : value;
}

function getItemTypeTagKind(type: string): 'blue' | 'teal' | 'cyan' | 'purple' | 'cool-gray' | 'gray' {
  switch (type.toUpperCase()) {
    case 'MEDICATION':
      return 'blue';
    case 'PPE':
      return 'teal';
    case 'SUPPLY':
      return 'cyan';
    case 'EQUIPMENT':
      return 'purple';
    default:
      return 'cool-gray';
  }
}

export function InventoryItemsTable({
  items,
  canManageItems,
  onAddItem,
  onInspectStock,
}: InventoryItemsTableProps): ReactNode {
  const [catalogType, setCatalogType] = useState('');
  const [activeFilter, setActiveFilter] = useState<'ALL' | 'ACTIVE' | 'INACTIVE'>('ALL');

  const itemTypes = useMemo(
    () => [...new Set(items.map((item) => item.itemType))].sort(),
    [items],
  );

  const filteredItems = useMemo(() => {
    return items.filter((item) => {
      const matchesType = catalogType === '' || item.itemType === catalogType;
      const matchesStatus = activeFilter === 'ALL'
        || (activeFilter === 'ACTIVE' ? item.active : !item.active);
      return matchesType && matchesStatus;
    });
  }, [items, catalogType, activeFilter]);

  const itemsById = useMemo(
    () => new Map(items.map((item) => [item.id, item])),
    [items],
  );

  const rows = useMemo(() => {
    return filteredItems.map((item) => ({
      id: item.id,
      code: item.code,
      name: item.name,
      type: item.itemType,
      category: item.category ?? '—',
      unit: item.unitOfMeasure,
      tracking: [
        item.tracksBatch ? 'Batch' : null,
        item.tracksExpiry ? 'Expiry' : null,
        item.tracksSerial ? 'Serial' : null,
      ].filter(Boolean).join(', ') || 'None',
      status: item.active ? 'Active' : 'Inactive',
    }));
  }, [filteredItems]);


  const headers = [
    { key: 'code', header: 'Code' },
    { key: 'name', header: 'Name' },
    { key: 'type', header: 'Type' },
    { key: 'category', header: 'Category' },
    { key: 'unit', header: 'Base unit' },
    { key: 'tracking', header: 'Tracking' },
    { key: 'status', header: 'Status' },
    { key: 'actions', header: 'Actions' },
  ];

  return (
    <section aria-labelledby="inventory-items-heading" className="afy-section">
      <div className="afy-section__heading">
        <div>
          <h2 id="inventory-items-heading" className="afy-section__title">Master item catalogue</h2>
          <p className="afy-section__description">
            Tenant-wide inventory items including medications, clinical supplies, and protective
            equipment.
          </p>
        </div>
      </div>

      <StatisticsCards
        label="Catalogue summary"
        cards={[
          {
            id: 'catalogue-items',
            label: 'Items in catalogue',
            value: items.length,
            detail: 'Shared vocabulary for every facility',
            borderTone: 'information',
            icon: <Box aria-hidden="true" />,
          },
          {
            id: 'catalogue-types',
            label: 'Item types',
            value: itemTypes.length,
            detail: 'Medication, PPE, equipment, and more',
            borderTone: 'information',
            icon: <List aria-hidden="true" />,
          },
          {
            id: 'catalogue-active',
            label: 'Active',
            value: items.filter((item) => item.active).length,
            detail: 'Available to order, receive, and request',
            borderTone: 'positive',
            icon: <Checkmark aria-hidden="true" />,
          },
        ]}
      />

      <CarbonDataTable
        rows={rows}
        headers={headers}
        title={`Items (${filteredItems.length} of ${items.length})`}
        ariaLabel="Inventory item catalogue"
        emptyState={<EmptyState title="No matches" description="No items match the selected search and filters." />}
        toolbar={(onInputChange) => (
          <>
            <TableToolbarSearch
              persistent
              onChange={onInputChange}
              placeholder="Search item catalogue"
              aria-label="Search item catalogue"
            />
            <TableToolbarMenu
              renderIcon={Filter}
              iconDescription={`Filter by item type: ${catalogType || 'All types'}`}
            >
              <OverflowMenuItem
                itemText={`All types (${items.length})`}
                isDelete={false}
                onClick={() => setCatalogType('')}
              />
              {itemTypes.map((type) => (
                <OverflowMenuItem
                  key={type}
                  itemText={`${type} (${items.filter((item) => item.itemType === type).length})`}
                  isDelete={false}
                  onClick={() => setCatalogType(type)}
                />
              ))}
            </TableToolbarMenu>
            {canManageItems ? (
              <TableToolbarMenu
                renderIcon={Filter}
                iconDescription={`Filter by status: ${activeFilter === 'ALL' ? 'All statuses' : activeFilter.toLowerCase()}`}
              >
                {(['ALL', 'ACTIVE', 'INACTIVE'] as const).map((status) => (
                  <OverflowMenuItem
                    key={status}
                    itemText={`${status === 'ALL' ? 'All statuses' : status === 'ACTIVE' ? 'Active' : 'Inactive'} (${items.filter((item) => status === 'ALL' || item.active === (status === 'ACTIVE')).length})`}
                    isDelete={false}
                    onClick={() => setActiveFilter(status)}
                  />
                ))}
              </TableToolbarMenu>
            ) : null}
            {canManageItems && onAddItem ? (
              <Button size="lg" onClick={onAddItem}>
                Add item to catalogue
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
            const typeStr = String(cell.value);
            const controlled = itemsById.get(row.id)?.isControlled ?? false;
            return (
              <div style={{ display: 'flex', gap: '0.25rem', flexWrap: 'wrap' }}>
                <Tag type={getItemTypeTagKind(typeStr)} size="sm">
                  {typeStr}
                </Tag>
                {controlled ? (
                  <Tag type="red" size="sm" title="Stock removal needs a named witness">
                    Controlled
                  </Tag>
                ) : null}
              </div>
            );
          }
          if (cell.info.header === 'unit') {
            const rawItem = itemsById.get(row.id);
            return (
              <div style={{ display: 'flex', flexDirection: 'column' }}>
                <span>{String(cell.value)}</span>
                {rawItem?.packUnit && rawItem.packSize ? (
                  <span style={{ fontSize: '0.75rem', color: 'var(--cds-text-secondary, #525252)' }}>
                    1 {rawItem.packUnit} = {formatQuantity(rawItem.packSize)} {rawItem.unitOfMeasure}
                  </span>
                ) : null}
              </div>
            );
          }
          if (cell.info.header === 'tracking') {
            const trackingStr = String(cell.value);
            if (trackingStr === 'None') return <span style={{ color: 'var(--cds-text-muted, #8d8d8d)' }}>None</span>;
            return (
              <div style={{ display: 'flex', gap: '0.25rem', flexWrap: 'wrap' }}>
                {trackingStr.split(', ').map((badge) => (
                  <Tag key={badge} type="outline" size="sm">
                    {badge}
                  </Tag>
                ))}
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
            const rawItem = itemsById.get(row.id);
            if (!rawItem) return null;
            return (
              <div style={{ display: 'flex', gap: '0.5rem', alignItems: 'center' }}>
                {onInspectStock ? (
                  <Button
                    kind="ghost"
                    size="sm"
                    onClick={() => onInspectStock(rawItem.id)}
                  >
                    View stock
                  </Button>
                ) : null}
                {canManageItems ? (
                  <Button
                    kind={rawItem.active ? 'danger--tertiary' : 'tertiary'}
                    size="sm"
                    onClick={() => router.patch(routes.workspaces.inventoryItemStatus(rawItem.id), {
                      active: !rawItem.active,
                    }, { preserveScroll: true })}
                  >
                    {rawItem.active ? 'Deactivate' : 'Reactivate'}
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

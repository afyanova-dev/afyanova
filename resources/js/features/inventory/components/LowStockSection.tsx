import {
  Button,
  InlineNotification,
  OverflowMenuItem,
  Stack,
  TableToolbarMenu,
  TableToolbarSearch,
  Tag,
  TextInput,
} from '@carbon/react';
import { router, useForm } from '@inertiajs/react';
import { useMemo, useState, type FormEvent, type ReactNode } from 'react';
import { routes } from '@/app/routes';
import { DockedPanel } from '@/components/panels';
import { CarbonDataTable, EmptyState, SearchableComboBox, StatisticsCards } from '@/components/ui';
import { Filter, List, Report, WarningAlt } from '@/icons';
import type { InventoryItem, ItemLevel, LowStockItem } from '../types';

export interface LowStockSectionProps {
  rows: LowStockItem[];
  /** Active items — the picker for tracking an item that is not low yet. */
  items: InventoryItem[];
  /** Every level already set at this facility, so the editor is never blind. */
  levels: ItemLevel[];
  canManageLevels: boolean;
}

type LevelForm = {
  item_id: string;
  min_level: string;
};

const HEADERS = [
  { key: 'item', header: 'Item' },
  { key: 'onHand', header: 'On hand' },
  { key: 'minimum', header: 'Minimum' },
  { key: 'order', header: 'To order' },
  { key: 'actions', header: 'Actions' },
];

function formatNumber(value: number): string {
  if (!Number.isFinite(value)) {
    return '—';
  }

  return Number.isInteger(value) ? String(value) : value.toFixed(2).replace(/0+$/, '').replace(/\.$/, '');
}

/**
 * "100.0000 CAPSULE ≈ 1 BOX" — a counting help, never a rule. Partial packs are
 * normal, so the level stays in base units and the pack is shown beside it.
 */
function packHint(item: InventoryItem | undefined, minLevel: string): string {
  if (item === undefined) {
    return '';
  }

  if (minLevel.trim() !== '' && Number(minLevel) === 0) {
    return '0 removes the level: this item stops being tracked at this facility.';
  }

  if (item.packUnit === null || item.packSize === null) {
    return `Stock is counted in ${item.unitOfMeasure}.`;
  }

  const base = `${item.packSize} ${item.unitOfMeasure}`;
  const packs = Number(minLevel) / Number(item.packSize);

  if (minLevel.trim() === '' || !Number.isFinite(packs)) {
    return `1 ${item.packUnit} = ${base}.`;
  }

  return `${minLevel} ${item.unitOfMeasure} ≈ ${formatNumber(packs)} ${item.packUnit} (1 ${item.packUnit} = ${base}).`;
}

/**
 * Running low (frontend-foundation-inventory-operations.md Phase 8).
 *
 * The minimum is a FACILITY setting even though the item master is shared:
 * the same item is reordered at different scales at a dispensary and a
 * hospital. Only items with a level are tracked, so an untouched catalogue
 * never produces false alarms. Quantities are base units; the pack size is a
 * counting help. Read-only with respect to stock — nothing here moves stock.
 */
export function LowStockSection({
  rows,
  items,
  levels,
  canManageLevels,
}: LowStockSectionProps): ReactNode {
  const [filter, setFilter] = useState<'all' | 'out'>('all');
  const [search, setSearch] = useState('');
  const [panelOpen, setPanelOpen] = useState(false);

  const levelForm = useForm<LevelForm>({ item_id: '', min_level: '' });

  const activeItems = useMemo(() => items.filter((item) => item.active), [items]);
  const levelsByItem = useMemo(
    () => new Map(levels.map((level) => [level.itemId, level.minLevel])),
    [levels],
  );

  const outCount = rows.filter((row) => row.outOfStock).length;

  const filtered = useMemo(() => {
    const term = search.trim().toLowerCase();

    return rows.filter((row) => {
      if (filter === 'out' && !row.outOfStock) {
        return false;
      }

      if (term === '') {
        return true;
      }

      return [row.itemCode, row.itemName]
        .some((value) => value.toLowerCase().includes(term));
    });
  }, [rows, filter, search]);

  const selectedItem = activeItems.find((item) => item.id === levelForm.data.item_id);
  const existingLevel = levelsByItem.get(levelForm.data.item_id) ?? null;

  function openEditor(itemId: string): void {
    levelForm.clearErrors();
    levelForm.setData({
      item_id: itemId,
      min_level: levelsByItem.get(itemId) ?? '',
    });
    setPanelOpen(true);
  }

  function closeEditor(): void {
    levelForm.reset();
    setPanelOpen(false);
  }

  function chooseItem(itemId: string): void {
    levelForm.setData({
      item_id: itemId,
      min_level: levelsByItem.get(itemId) ?? '',
    });
  }

  function submitLevel(event: FormEvent): void {
    event.preventDefault();
    levelForm.post(routes.workspaces.inventoryItemLevelsStore, {
      preserveScroll: true,
      onSuccess: () => {
        levelForm.reset();
        setPanelOpen(false);
      },
    });
  }

  /**
   * Clearing is an explicit action, not "a level of zero": the server deletes
   * the row, so the item simply stops being tracked. `router.post` is used
   * (rather than mutating the form and submitting) because the new value must
   * be the one sent.
   */
  function stopTracking(): void {
    router.post(
      routes.workspaces.inventoryItemLevelsStore,
      { item_id: levelForm.data.item_id, min_level: '0' },
      {
        preserveScroll: true,
        onSuccess: () => {
          levelForm.reset();
          setPanelOpen(false);
        },
      },
    );
  }

  const tableRows = filtered.map((row) => ({
    id: row.itemId,
    item: `${row.itemCode} — ${row.itemName}`,
    onHand: `${row.onHand} ${row.uom}`,
    minimum: `${row.minLevel} ${row.uom}`,
    order: `${row.shortfall} ${row.uom}`,
  }));

  const rowsById = new Map(filtered.map((row) => [row.itemId, row]));

  return (
    <div className="afy-section">
      <div className="afy-section__heading">
        <div>
          <h2 id="inventory-low-stock-heading" className="afy-section__title">Running low</h2>
          <p className="afy-section__description">
            Items at or below the minimum set for this facility. Only items with a minimum
            are checked; expired stock does not count as available. The minimum belongs to
            this facility, not the shared item master — it is kept in stock units
            (CAPSULE), so partial packs are fine.
          </p>
        </div>
        {canManageLevels ? (
          <Button kind="tertiary" size="md" onClick={() => openEditor('')}>
            Set a minimum level
          </Button>
        ) : null}
      </div>

      <StatisticsCards
        label="Low-stock summary"
        cards={[
          {
            id: 'low-stock-out',
            label: 'Out of stock',
            value: outCount,
            detail: 'Nothing on hand — everything must be reordered',
            borderTone: outCount > 0 ? 'negative' : 'information',
            icon: <WarningAlt aria-hidden="true" />,
          },
          {
            id: 'low-stock-below-minimum',
            label: 'At or below minimum',
            value: rows.length,
            detail: 'Levels with a shortfall to order',
            borderTone: rows.length > 0 ? 'caution' : 'information',
            icon: <Report aria-hidden="true" />,
          },
          {
            id: 'low-stock-tracked',
            label: 'Items tracked',
            value: levels.length,
            detail: 'Minimum levels set at this facility',
            borderTone: 'information',
            icon: <List aria-hidden="true" />,
          },
        ]}
      />

      <CarbonDataTable
        rows={tableRows}
        headers={HEADERS}
        title={`Running low (${filtered.length} of ${rows.length})`}
        ariaLabel="Running low"
        emptyState={
          rows.length > 0 ? (
            <EmptyState title="No matches" description="No item matches this filter." />
          ) : levels.length === 0 ? (
            <EmptyState
              title="No minimums set yet"
              description="No minimum levels have been set at this facility yet. Set one to start tracking what to reorder."
            />
          ) : (
            <EmptyState
              title="All clear"
              description="Everything tracked at this facility is above its minimum."
            />
          )
        }
        toolbar={(onInputChange) => (
          <>
            <TableToolbarSearch
              persistent
              onChange={(event) => {
                onInputChange(event);
                setSearch(typeof event === 'string' ? event : event.target.value);
              }}
              placeholder="Search item"
              aria-label="Search running low"
            />
            <TableToolbarMenu
              renderIcon={Filter}
              iconDescription={`Filter running low: ${filter === 'all' ? 'All' : 'Out of stock'}`}
            >
              <OverflowMenuItem
                itemText={`All (${rows.length})`}
                isDelete={false}
                onClick={() => setFilter('all')}
              />
              <OverflowMenuItem
                itemText={`Out of stock (${outCount})`}
                isDelete={false}
                onClick={() => setFilter('out')}
              />
            </TableToolbarMenu>
          </>
        )}
        renderCell={(cell, row) => {
          const lowRow = rowsById.get(String(row.id));

          if (lowRow === undefined) {
            return cell.value;
          }

          if (cell.info.header === 'item') {
            return (
              <>
                {cell.value}
                {lowRow.outOfStock ? (
                  <div>
                    <Tag type="red" size="sm">Out of stock</Tag>
                  </div>
                ) : null}
              </>
            );
          }

          if (cell.info.header === 'onHand') {
            return (
              <>
                {cell.value}
                {Number(lowRow.expired) > 0 ? (
                  <div className="procurement-hint">
                    + {lowRow.expired} {lowRow.uom} expired
                  </div>
                ) : null}
              </>
            );
          }

          if (cell.info.header === 'actions') {
            if (!canManageLevels) {
              return null;
            }

            return (
              <Button kind="ghost" size="sm" onClick={() => openEditor(lowRow.itemId)}>
                Change minimum
              </Button>
            );
          }

          return cell.value;
        }}
      />

      <DockedPanel
        heading="Set a minimum level"
        description="The item is tracked at this facility only, in its stock unit. Leave the level alone if the item should not be watched."
        hidden={!panelOpen}
        onClose={closeEditor}
        onSubmit={submitLevel}
        primaryLabel={levelForm.processing ? 'Saving…' : 'Save minimum'}
        primaryDisabled={
          levelForm.processing
          || levelForm.data.item_id === ''
          || levelForm.data.min_level.trim() === ''
        }
        width="min(34rem, 100%)"
      >
        <Stack gap={5}>
          <InlineNotification
            kind="info"
            lowContrast
            hideCloseButton
            title="A reorder reminder, not a stock rule"
            subtitle="Stock still leaves only through receipts, dispensing and approved adjustments. This screen never moves stock."
          />

          <SearchableComboBox
            id="low-stock-item"
            titleText="Item to track"
            helperText="Pick any active item to set, change, or stop tracking its minimum."
            items={activeItems.map((item) => ({ id: item.id, label: `${item.code} — ${item.name}` }))}
            selectedId={levelForm.data.item_id}
            onChange={chooseItem}
            invalid={Boolean(levelForm.errors.item_id)}
            invalidText={levelForm.errors.item_id}
          />

          <TextInput
            id="low-stock-minimum"
            type="number"
            min="0"
            step="any"
            labelText={selectedItem ? `Minimum level (${selectedItem.unitOfMeasure})` : 'Minimum level'}
            helperText={packHint(selectedItem, levelForm.data.min_level)}
            value={levelForm.data.min_level}
            onChange={(event) => levelForm.setData('min_level', event.currentTarget.value)}
            invalid={Boolean(levelForm.errors.min_level)}
            invalidText={levelForm.errors.min_level}
          />

          {existingLevel !== null ? (
            <div>
              <p className="procurement-hint">
                This facility currently tracks it at {existingLevel} {selectedItem?.unitOfMeasure ?? ''}.
              </p>
              <Button kind="ghost" size="sm" onClick={stopTracking}>
                Stop tracking this item
              </Button>
            </div>
          ) : null}

          <p className="procurement-hint">
            The order is placed in Procurement; this level only tells you when to look.
          </p>
        </Stack>
      </DockedPanel>
    </div>
  );
}

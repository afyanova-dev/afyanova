import { Button, InlineNotification, Tag, TextInput } from '@carbon/react';
import { router } from '@inertiajs/react';
import { useEffect, useMemo, useState, type ReactNode } from 'react';
import { routes } from '@/app/routes';
import { DockedPanel } from '@/components/panels';
import { SearchableComboBox } from '@/components/ui/SearchableComboBox';
import type { InventoryItem, StockCountLine, StockCountSheet } from '../../types';

const STATUS_LABELS: Record<string, string> = {
  IN_PROGRESS: 'In progress',
  COMPLETED: 'Completed',
  DISCARDED: 'Discarded',
};

const STATUS_TAG: Record<string, 'blue' | 'green' | 'cool-gray'> = {
  IN_PROGRESS: 'blue',
  COMPLETED: 'green',
  DISCARDED: 'cool-gray',
};

/** '100.0000' → '100'; '0.0000' → '0'; null → '—'. */
function displayQty(value: string | null): string {
  if (value === null) {
    return '—';
  }

  const number = Number(value);

  return Number.isFinite(number) ? String(number) : value;
}

/** counted − system for a line, or null while the line is blank. */
function varianceOf(line: StockCountLine, entered: string): number | null {
  const trimmed = entered.trim();

  if (trimmed === '' || !Number.isFinite(Number(trimmed))) {
    return null;
  }

  return Number(trimmed) - Number(line.systemQty);
}

function signed(value: number): string {
  if (value === 0) {
    return '0';
  }

  return `${value > 0 ? '+' : ''}${displayQty(String(value))}`;
}

export interface StockCountSheetPanelProps {
  open: boolean;
  onClose: () => void;
  sheet: StockCountSheet | null;
  /** Active items — the "add a find to the sheet" picker. */
  items: InventoryItem[];
  canManage: boolean;
}

/**
 * The count sheet (Phase 8): one row per (item, batch) the system believed was
 * at the location when the count started.
 *
 * A blank Counted box means "not counted yet" — it is NOT zero. Zero is a real
 * count (the shelf is empty). Nothing here edits stock; a difference is fixed
 * afterwards through a two-person stock adjustment.
 */
export function StockCountSheetPanel({
  open,
  onClose,
  sheet,
  items,
  canManage,
}: StockCountSheetPanelProps): ReactNode {
  const [draft, setDraft] = useState<Record<string, string>>({});
  const [busy, setBusy] = useState(false);
  const [confirmDiscard, setConfirmDiscard] = useState(false);
  const [addingItem, setAddingItem] = useState('');
  const [addingBatch, setAddingBatch] = useState('');
  const [witnessName, setWitnessName] = useState('');

  const countId = sheet?.count.countId ?? null;
  const status = sheet?.count.status ?? null;
  const readOnly = !canManage || status !== 'IN_PROGRESS';

  // A different count was opened: seed the boxes from what the server has.
  useEffect(() => {
    const next: Record<string, string> = {};
    for (const line of sheet?.lines ?? []) {
      next[line.lineId] = line.countedQty ?? '';
    }
    setDraft(next);
    setConfirmDiscard(false);
    setAddingItem('');
    setAddingBatch('');
    setWitnessName('');
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [countId]);

  const lines = useMemo(() => sheet?.lines ?? [], [sheet]);

  const counted = lines.filter((line) => (draft[line.lineId] ?? '').trim() !== '').length;
  const differences = lines.filter((line) => {
    const variance = varianceOf(line, draft[line.lineId] ?? '');

    return variance !== null && variance !== 0;
  }).length;
  const controlledShortfall = lines.some((line) => (
    line.isControlled && (varianceOf(line, draft[line.lineId] ?? '') ?? 0) < 0
  ));

  const lineItemIds = useMemo(() => new Set(lines.map((line) => line.itemId)), [lines]);
  const addableItems = useMemo(
    () => items.filter((item) => item.active && !lineItemIds.has(item.id)),
    [items, lineItemIds],
  );
  const addingCatalogItem = items.find((item) => item.id === addingItem) ?? null;

  function post(url: string, data: Parameters<typeof router.post>[1], onSuccess?: () => void): void {
    setBusy(true);
    router.post(url, data, {
      preserveScroll: true,
      only: ['stockCounts', 'stockCountSheet', 'status', 'errors'],
      onSuccess,
      onFinish: () => setBusy(false),
    });
  }

  function saveCounts(): void {
    if (countId === null) {
      return;
    }

    const counts: Record<string, string | null> = {};
    for (const line of lines) {
      const value = (draft[line.lineId] ?? '').trim();
      // Blank clears the line back to "not counted" — never silently zero.
      counts[line.lineId] = value === '' ? null : value;
    }

    post(routes.workspaces.inventoryStockCountCounts(countId), {
      idempotency_key: crypto.randomUUID(),
      counts,
    });
  }

  function addLine(): void {
    if (countId === null || addingItem === '') {
      return;
    }

    post(routes.workspaces.inventoryStockCountLines(countId), {
      idempotency_key: crypto.randomUUID(),
      item_id: addingItem,
      batch_number: addingBatch.trim() === '' ? null : addingBatch.trim(),
    }, () => {
      setAddingItem('');
      setAddingBatch('');
    });
  }

  function closeCount(decision: 'COMPLETE' | 'DISCARD'): void {
    if (countId === null) {
      return;
    }

    const counts = decision === 'COMPLETE'
      ? Object.fromEntries(lines.map((line) => {
        const value = (draft[line.lineId] ?? '').trim();

        return [line.lineId, value === '' ? null : value];
      }))
      : undefined;

    post(routes.workspaces.inventoryStockCountClose(countId), {
      idempotency_key: crypto.randomUUID(),
      decision,
      counts: counts ?? {},
      witness_name: decision === 'COMPLETE' ? witnessName.trim() || null : null,
    }, onClose);
  }

  return (
    <DockedPanel
      heading={sheet === null ? 'Count sheet' : `Count ${sheet.count.countNumber}`}
      description={sheet === null
        ? 'Choose a count to see its sheet.'
        : `${sheet.count.locationCode} — ${sheet.count.locationName}`}
      hidden={!open}
      onClose={onClose}
      width="min(52rem, 100%)"
      expandable
      actions={sheet === null ? undefined : (
        // No wrapper div — the buttons must be direct children of
        // .afy-dock__actions, which turns each into a full-width 4rem footer
        // slab (flex: 1 1 50%) that covers the panel edge to edge and
        // automatically re-balances across two rows on narrow panels.
        <>
          {readOnly ? null : (
            <>
              <Button onClick={saveCounts} disabled={busy}>
                Save counts
              </Button>
              <Button
                kind="tertiary"
                onClick={() => closeCount('COMPLETE')}
                disabled={busy || (controlledShortfall && witnessName.trim() === '')}
              >
                Complete count
              </Button>
              <Button kind="danger--tertiary" onClick={() => setConfirmDiscard(true)} disabled={busy}>
                Discard
              </Button>
            </>
          )}
          <Button kind="secondary" onClick={onClose}>
            Close
          </Button>
        </>
      )}
    >
      {sheet === null ? (
        <p className="procurement-hint">The count sheet is loading.</p>
      ) : (
        <div style={{ display: 'flex', flexDirection: 'column', gap: '1rem' }}>
          <div style={{ display: 'flex', gap: '0.75rem', alignItems: 'center', flexWrap: 'wrap' }}>
            <Tag type={STATUS_TAG[status ?? ''] ?? 'cool-gray'} size="sm">
              {STATUS_LABELS[status ?? ''] ?? status}
            </Tag>
            <span>{counted} of {lines.length} lines counted</span>
            <span>{differences} with a difference</span>
          </div>

          {sheet.count.notes ? (
            <p className="procurement-hint">Notes: {sheet.count.notes}</p>
          ) : null}

          {readOnly ? (
            <InlineNotification
              kind="info"
              lowContrast
              hideCloseButton
              title={canManage ? 'This count is closed' : 'Read only'}
              subtitle={canManage
                ? 'A completed or discarded count can no longer be changed.'
                : 'You can see the count, but starting or recording one needs the stock-count permission.'}
            />
          ) : null}

          {lines.length === 0 ? (
            <InlineNotification
              kind="info"
              title="Nothing on the sheet"
              subtitle="The system believes this location holds no stock. Add anything you find below."
              hideCloseButton
            />
          ) : (
            <div style={{ overflowX: 'auto' }}>
              <table style={{ width: '100%', borderCollapse: 'collapse' }} aria-label="Count sheet">
                <thead>
                  <tr style={{ textAlign: 'left' }}>
                    <th scope="col" style={{ padding: '0.5rem' }}>Item</th>
                    <th scope="col" style={{ padding: '0.5rem' }}>Batch</th>
                    <th scope="col" style={{ padding: '0.5rem' }}>System</th>
                    <th scope="col" style={{ padding: '0.5rem' }}>Counted</th>
                    <th scope="col" style={{ padding: '0.5rem' }}>Difference</th>
                  </tr>
                </thead>
                <tbody>
                  {lines.map((line) => {
                    const entered = draft[line.lineId] ?? '';
                    const variance = varianceOf(line, entered);

                    return (
                      <tr key={line.lineId} style={{ borderTop: '1px solid var(--cds-border-subtle, #e0e0e0)' }}>
                        <td style={{ padding: '0.5rem' }}>
                          {line.itemCode} — {line.itemName}
                        </td>
                        <td style={{ padding: '0.5rem' }}>{line.batchNumber ?? '—'}</td>
                        <td style={{ padding: '0.5rem', whiteSpace: 'nowrap' }}>
                          {displayQty(line.systemQty)} {line.uom}
                        </td>
                        <td style={{ padding: '0.5rem', minWidth: '9rem' }}>
                          {readOnly ? (
                            <span>{displayQty(line.countedQty)}</span>
                          ) : (
                            <TextInput
                              id={`count-line-${line.lineId}`}
                              labelText={`Counted quantity for ${line.itemCode}${line.batchNumber ? ` batch ${line.batchNumber}` : ''}`}
                              hideLabel
                              size="sm"
                              type="number"
                              min="0"
                              step="0.0001"
                              value={entered}
                              onChange={(event) => setDraft((current) => ({
                                ...current,
                                [line.lineId]: event.target.value,
                              }))}
                            />
                          )}
                        </td>
                        <td style={{ padding: '0.5rem', whiteSpace: 'nowrap' }}>
                          {variance === null ? '—' : signed(variance)}
                        </td>
                      </tr>
                    );
                  })}
                </tbody>
              </table>
            </div>
          )}

          {readOnly ? null : (
            <>
              {controlledShortfall ? (
                <TextInput
                  id="count-witness-name"
                  labelText="Witness name"
                  helperText="A controlled medicine shortfall requires the name of the person who witnessed the count."
                  value={witnessName}
                  onChange={(event) => setWitnessName(event.currentTarget.value)}
                  required
                />
              ) : null}

              <InlineNotification
                kind="info"
                lowContrast
                hideCloseButton
                title="Differences are corrected afterwards"
                subtitle="Saving the count only records what you found. Raise a stock adjustment for each difference; a second person approves it."
              />

              <div style={{ display: 'flex', flexDirection: 'column', gap: '0.75rem' }}>
                <SearchableComboBox
                  id="count-add-item"
                  titleText="Found something not on the sheet?"
                  helperText="Add the item, then type what you found. A count never creates a batch — name one that already exists."
                  items={addableItems.map((item) => ({ id: item.id, label: `${item.code} — ${item.name}` }))}
                  selectedId={addingItem}
                  onChange={(itemId) => {
                    setAddingItem(itemId);
                    setAddingBatch('');
                  }}
                />

                {addingCatalogItem?.tracksBatch ? (
                  <TextInput
                    id="count-add-batch"
                    labelText="Batch number"
                    helperText="The batch must already exist at this facility."
                    value={addingBatch}
                    onChange={(event) => setAddingBatch(event.target.value)}
                  />
                ) : null}

                <div>
                  <Button
                    kind="ghost"
                    size="md"
                    onClick={addLine}
                    disabled={busy || addingItem === ''}
                  >
                    Add to sheet
                  </Button>
                </div>
              </div>

              {confirmDiscard ? (
                <>
                  <InlineNotification
                    kind="warning"
                    title="Discard this count?"
                    subtitle="The sheet and its counted quantities will be kept as discarded and cannot be used. Nothing on the shelf changes."
                    hideCloseButton
                  />
                  <div style={{ display: 'flex', gap: '0.5rem' }}>
                    <Button size="sm" kind="danger" onClick={() => closeCount('DISCARD')} disabled={busy}>
                      Yes, discard
                    </Button>
                    <Button size="sm" kind="secondary" onClick={() => setConfirmDiscard(false)}>
                      Keep counting
                    </Button>
                  </div>
                </>
              ) : null}
            </>
          )}
        </div>
      )}
    </DockedPanel>
  );
}

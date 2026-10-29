import {
  Button,
  ComboBox,
  IconButton,
  InlineNotification,
  Modal,
  Select,
  SelectItem,
  SelectSkeleton,
  SkeletonPlaceholder,
  SkeletonText,
  TextArea,
  TextInput,
} from '@carbon/react';
import { Add, TrashCan } from '@carbon/icons-react';
import { useForm } from '@inertiajs/react';
import {
  useEffect,
  useMemo,
  useRef,
  useState,
  type FormEvent,
  type ReactNode,
} from 'react';
import { routes } from '@/app/routes';
import { DockedPanel } from '@/components/panels';
import { SearchableComboBox } from '@/components/ui/SearchableComboBox';
import { getJson, JsonRequestError } from '@/lib/http';
import type {
  InventoryItem,
  OpenPurchaseOrder,
  OpenPurchaseOrderLine,
  OpenPurchaseOrderLinePage,
  OpenPurchaseOrderSearchPage,
  StockLocation,
} from '../../types';

const MAX_LINES = 500;

interface DraftReceiptLine {
  key: string;
  receipt_line_id: string;
  item_id: string;
  po_line_id: string;
  entered_unit: string;
  entered_quantity: string;
  pack_size: string;
  quantity_delivered: string;
  quantity_rejected: string;
  condition: string;
  batch_number: string;
  expiry_date: string;
  manufacture_date: string;
  condition_notes: string;
  rejection_reason: string;
  substituted_for_item_id: string;
}

interface GoodsReceiptFormData {
  receipt_id: string;
  idempotency_key: string;
  location_id: string;
  source_type: string;
  po_ref: string;
  supplier_ref: string;
  delivery_note_number: string;
  delivery_note_missing_reason: string;
  invoice_number: string;
  invoice_date: string;
  invoice_status: string;
  invoice_currency: string;
  invoice_total: string;
  received_at: string;
  remarks: string;
  reason: string;
  lines: DraftReceiptLine[];
}

interface PurchaseOrderOption {
  id: string;
  label: string;
  purchaseOrder: OpenPurchaseOrder | null;
}

export interface GoodsReceiptWorkbenchPanelProps {
  open: boolean;
  onClose: () => void;
  onSuccess?: () => void;
  items: InventoryItem[];
  locations: StockLocation[];
  canSearchPurchaseOrders?: boolean;
  initialLocationId?: string | null;
}

function trimQuantity(value: string): string {
  const parsed = Number.parseFloat(value);

  if (! Number.isFinite(parsed)) {
    return value;
  }

  return String(Number(parsed.toFixed(4)));
}

function multiply(base: string, pack: string): string {
  const entered = Number.parseFloat(base);
  const size = Number.parseFloat(pack);

  if (! Number.isFinite(entered) || entered <= 0 || ! Number.isFinite(size) || size <= 0) {
    return '';
  }

  return (entered * size).toFixed(4);
}

function acceptedQuantity(line: DraftReceiptLine): string {
  const delivered = Number.parseFloat(line.quantity_delivered);
  const rejected = Number.parseFloat(line.quantity_rejected || '0');

  if (! Number.isFinite(delivered)) {
    return '0';
  }

  const accepted = delivered - (Number.isFinite(rejected) ? rejected : 0);

  return accepted <= 0 ? '0' : accepted.toFixed(4);
}

function errorFor(errors: Record<string, string>, key: string): string | undefined {
  return errors[key];
}

function isBackdated(value: string): boolean {
  if (value === '') {
    return false;
  }

  const receivedAt = new Date(value);

  return Number.isFinite(receivedAt.getTime())
    && Date.now() - receivedAt.getTime() > 24 * 60 * 60 * 1000;
}

/**
 * The Phase 1 receiving workbench: one delivery, one GRN, many lines.
 *
 * This panel is the sole receiving path. It keeps the panel open after posting
 * and refreshes only the affected Inventory props (P0-04).
 */
export function GoodsReceiptWorkbenchPanel({
  open,
  onClose,
  onSuccess,
  items,
  locations,
  canSearchPurchaseOrders = true,
  initialLocationId,
}: GoodsReceiptWorkbenchPanelProps): ReactNode {
  const receivableItems = useMemo(() => items.filter((item) => item.active && !item.tracksSerial), [items]);
  const defaultLocationId = initialLocationId && locations.some((location) => location.id === initialLocationId)
    ? initialLocationId
    : locations.find((location) => location.active)?.id ?? locations[0]?.id ?? '';

  const form = useForm<GoodsReceiptFormData>({
    receipt_id: crypto.randomUUID(),
    idempotency_key: crypto.randomUUID(),
    location_id: defaultLocationId,
    source_type: 'OPENING_BALANCE',
    po_ref: '',
    supplier_ref: '',
    delivery_note_number: '',
    delivery_note_missing_reason: '',
    invoice_number: '',
    invoice_date: '',
    invoice_status: 'PENDING',
    invoice_currency: 'TZS',
    invoice_total: '',
    received_at: '',
    remarks: '',
    reason: '',
    lines: [],
  });

  const [poSearch, setPoSearch] = useState('');
  const [poOptions, setPoOptions] = useState<OpenPurchaseOrder[]>([]);
  const [poLoading, setPoLoading] = useState(false);
  const [poFieldFocused, setPoFieldFocused] = useState(false);
  const [poError, setPoError] = useState<string | null>(null);
  const [selectedPurchaseOrder, setSelectedPurchaseOrder] = useState<OpenPurchaseOrder | null>(null);
  const [openLines, setOpenLines] = useState<OpenPurchaseOrderLine[]>([]);
  const [linesLoading, setLinesLoading] = useState(false);
  const [lineSearch, setLineSearch] = useState('');
  const [importOpen, setImportOpen] = useState(false);
  const [importText, setImportText] = useState('');
  const [importError, setImportError] = useState<string | null>(null);
  const [lineRefreshToken, setLineRefreshToken] = useState(0);
  const [manualItemId, setManualItemId] = useState('');
  const [successMessage, setSuccessMessage] = useState<string | null>(null);
  const [postedReceiptId, setPostedReceiptId] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const selectedPoRef = useRef(form.data.po_ref);
  selectedPoRef.current = form.data.po_ref;

  useEffect(() => {
    if (! open) {
      return;
    }

    if (initialLocationId && locations.some((location) => location.id === initialLocationId)) {
      form.setData('location_id', initialLocationId);
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open, initialLocationId, locations]);

  // Search open purchase orders on demand; never pre-load them into page props
  // (P0-03).
  useEffect(() => {
    if (! open || ! canSearchPurchaseOrders) {
      return;
    }

    let cancelled = false;
    const timer = window.setTimeout(() => {
      setPoLoading(true);
      setPoError(null);

      void (async () => {
        try {
          const url = `${routes.workspaces.inventoryReceiptOpenOrders}?q=${encodeURIComponent(poSearch.trim())}`;
          const page = await getJson<OpenPurchaseOrderSearchPage>(url);

          if (cancelled) {
            return;
          }

          setPoOptions(page.data);

          // A fully received PO drops out of the open list after the post-receipt
          // refresh. Do not leave it selected against an empty line set.
          if (poSearch.trim() === ''
            && selectedPurchaseOrder !== null
            && ! page.data.some((po) => po.purchaseOrderId === selectedPurchaseOrder.purchaseOrderId)) {
            setSelectedPurchaseOrder(null);
            setOpenLines([]);
            form.setData({
              ...form.data,
              po_ref: '',
              source_type: 'OPENING_BALANCE',
              lines: [],
            });
            form.clearErrors();
          }
        } catch (error) {
          if (! cancelled) {
            setPoError(error instanceof JsonRequestError && error.status === 403
              ? 'You are not authorised to receive against purchase orders.'
              : error instanceof Error
                ? error.message
                : 'Could not search open purchase orders.');
          }
        } finally {
          if (! cancelled) {
            setPoLoading(false);
          }
        }
      })();
    }, 250);

    return () => {
      cancelled = true;
      window.clearTimeout(timer);
    };
    // `form` is a stable Inertia form API; including it would restart the
    // debounce on every keystroke. The selected PO is read for the post-receipt
    // refresh only.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open, canSearchPurchaseOrders, poSearch, lineRefreshToken]);

  // A selected PO has at most 500 lines; load them in 100-line pages when the
  // selection (or the post-receipt refresh) changes.
  useEffect(() => {
    if (! open || selectedPurchaseOrder === null) {
      setOpenLines([]);
      return;
    }

    let cancelled = false;
    setLinesLoading(true);
    setPoError(null);

    void (async () => {
      try {
        const first = await getJson<OpenPurchaseOrderLinePage>(
          `${routes.workspaces.inventoryReceiptOpenOrderLines(selectedPurchaseOrder.purchaseOrderId)}?q=`,
        );

        let lines = first.data;
        for (let page = 2; page <= first.meta.lastPage; page++) {
          const next = await getJson<OpenPurchaseOrderLinePage>(
            `${routes.workspaces.inventoryReceiptOpenOrderLines(selectedPurchaseOrder.purchaseOrderId)}?q=&page=${page}`,
          );
          lines = [...lines, ...next.data];
        }

        if (! cancelled) {
          setOpenLines(lines);
        }
      } catch (error) {
        if (! cancelled) {
          setPoError(error instanceof JsonRequestError && (error.status === 401 || error.status === 403)
            ? 'You are not authorised to receive against this purchase order.'
            : error instanceof Error
              ? error.message
              : 'Could not load the purchase order lines.');
        }
      } finally {
        if (! cancelled) {
          setLinesLoading(false);
        }
      }
    })();

    return () => {
      cancelled = true;
    };
  }, [open, selectedPurchaseOrder, lineRefreshToken]);

  const itemsById = useMemo(() => new Map(items.map((item) => [item.id, item])), [items]);
  const filteredOpenLines = useMemo(() => {
    const query = lineSearch.trim().toLowerCase();

    return openLines.filter((line) => {
      if (query === '') {
        return true;
      }

      const item = itemsById.get(line.itemRef);

      return String(line.lineNo).includes(query)
        || (line.itemCode ?? item?.code ?? '').toLowerCase().includes(query)
        || (line.itemName ?? item?.name ?? '').toLowerCase().includes(query);
    });
  }, [lineSearch, openLines, itemsById]);

  const draftLineIds = useMemo(
    () => new Set(form.data.lines.map((line) => line.po_line_id).filter((id) => id !== '')),
    [form.data.lines],
  );
  const totals = useMemo(() => {
    let delivered = 0;
    let rejected = 0;
    let accepted = 0;
    let invalid = 0;

    for (const line of form.data.lines) {
      const deliveredValue = Number.parseFloat(line.quantity_delivered);
      const rejectedValue = Number.parseFloat(line.quantity_rejected || '0');

      if (! Number.isFinite(deliveredValue) || deliveredValue <= 0
        || ! Number.isFinite(rejectedValue) || rejectedValue < 0
        || rejectedValue > deliveredValue) {
        invalid++;
      }

      delivered += Number.isFinite(deliveredValue) ? deliveredValue : 0;
      rejected += Number.isFinite(rejectedValue) ? rejectedValue : 0;
      accepted += Number.parseFloat(acceptedQuantity(line));
    }

    return { delivered, rejected, accepted, invalid };
  }, [form.data.lines]);

  function setLines(lines: DraftReceiptLine[]): void {
    form.setData('lines', lines);
    form.clearErrors();
  }

  function updateLine(index: number, patch: Partial<DraftReceiptLine>): void {
    setLines(form.data.lines.map((line, lineIndex) => (
      lineIndex === index ? { ...line, ...patch } : line
    )));
  }

  function setDelivered(index: number, enteredQuantity: string): void {
    const line = form.data.lines[index];

    if (line === undefined) {
      return;
    }

    updateLine(index, {
      entered_quantity: enteredQuantity,
      quantity_delivered: multiply(enteredQuantity, line.pack_size),
    });
  }

  function draftFromPoLine(line: OpenPurchaseOrderLine, receiveRemaining: boolean): DraftReceiptLine | null {
    const item = itemsById.get(line.itemRef);

    if (item === undefined || !item.active || item.tracksSerial) {
      return null;
    }

    const packSize = line.enteredUnit !== null ? line.packSize ?? '1' : '1';
    const enteredUnit = line.enteredUnit ?? line.uom;
    const remaining = Number.parseFloat(line.remainingQty);
    const size = Number.parseFloat(packSize);
    const enteredQuantity = receiveRemaining && Number.isFinite(remaining) && Number.isFinite(size) && size > 0
      ? trimQuantity((remaining / size).toFixed(4))
      : '';

    return {
      key: crypto.randomUUID(),
      receipt_line_id: crypto.randomUUID(),
      item_id: item.id,
      po_line_id: line.lineId,
      entered_unit: enteredUnit,
      entered_quantity: enteredQuantity,
      pack_size: packSize,
      quantity_delivered: multiply(enteredQuantity, packSize),
      quantity_rejected: '0',
      condition: 'GOOD',
      batch_number: '',
      expiry_date: '',
      manufacture_date: '',
      condition_notes: '',
      rejection_reason: '',
      substituted_for_item_id: '',
    };
  }

  function addPoLine(line: OpenPurchaseOrderLine, receiveRemaining = true): void {
    if (form.data.lines.length >= MAX_LINES) {
      setNotice(`A goods receipt can contain at most ${MAX_LINES} lines.`);
      return;
    }

    if (draftLineIds.has(line.lineId)) {
      setNotice('That purchase-order line is already on this receipt. Edit its quantity above.');
      return;
    }

    const draft = draftFromPoLine(line, receiveRemaining);

    if (draft === null) {
      setNotice('That item is inactive, missing or serial-tracked and cannot be received here.');
      return;
    }

    setNotice(null);
    setSuccessMessage(null);
    setLines([...form.data.lines, draft]);
  }

  function addAllOpenLines(): void {
    const candidates = filteredOpenLines.filter((line) => (
      Number.parseFloat(line.remainingQty) > 0
      && ! draftLineIds.has(line.lineId)
      && itemsById.has(line.itemRef)
    ));
    const capacity = MAX_LINES - form.data.lines.length;
    const additions: DraftReceiptLine[] = [];

    for (const line of candidates.slice(0, capacity)) {
      const draft = draftFromPoLine(line, true);
      if (draft !== null) {
        additions.push(draft);
      }
    }

    if (additions.length === 0) {
      setNotice('There are no unadded open lines with a remaining quantity.');
      return;
    }

    setNotice(candidates.length > capacity
      ? `Added ${additions.length} lines; the ${MAX_LINES}-line limit was reached.`
      : null);
    setSuccessMessage(null);
    setLines([...form.data.lines, ...additions]);
  }

  function addManualItem(): void {
    const item = itemsById.get(manualItemId);

    if (item === undefined) {
      setNotice('Choose an item to add.');
      return;
    }

    if (form.data.lines.length >= MAX_LINES) {
      setNotice(`A goods receipt can contain at most ${MAX_LINES} lines.`);
      return;
    }

    const packSize = item.packSize ?? '1';

    setLines([...form.data.lines, {
      key: crypto.randomUUID(),
      receipt_line_id: crypto.randomUUID(),
      item_id: item.id,
      po_line_id: '',
      entered_unit: item.packUnit ?? item.unitOfMeasure,
      entered_quantity: '',
      pack_size: packSize,
      quantity_delivered: '',
      quantity_rejected: '0',
      condition: 'GOOD',
      batch_number: '',
      expiry_date: '',
      manufacture_date: '',
      condition_notes: '',
      rejection_reason: '',
      substituted_for_item_id: '',
    }]);
    setManualItemId('');
    setSuccessMessage(null);
    setNotice(null);
  }

  /**
   * P1-06 — paste a delivery note's quantities instead of typing 300 rows.
   *
   * One row per PO line: `line number, quantity` (or item code instead of the
   * line number). Quantities are BASE stock units, so `4 BOX of 100` is 400.
   * Rows with 0 remove the line; unmentioned lines stay as they are.
   */
  function applyImportedQuantities(): void {
    const rows = importText
      .split(/\r?\n/)
      .map((row) => row.trim())
      .filter((row) => row !== '');

    if (rows.length === 0) {
      setImportError('Paste at least one line number and quantity.');

      return;
    }

    const errors: string[] = [];
    const updates = new Map<string, string>();

    rows.forEach((row, rowIndex) => {
      const parts = row.split(/[,\t;]/).map((part) => part.trim());

      if (rowIndex === 0 && /^line(\s*no)?$/i.test(parts[0] ?? '')) {
        return;
      }

      if (parts.length < 2 || parts[0] === '' || parts[1] === '') {
        errors.push(`Row ${rowIndex + 1}: expected line number, quantity.`);

        return;
      }

      const openLine = openLines.find(
        (candidate) => String(candidate.lineNo) === parts[0] || candidate.itemCode === parts[0],
      );

      if (openLine === undefined) {
        errors.push(`Row ${rowIndex + 1}: “${parts[0]}” is not an open line on this order.`);

        return;
      }

      const quantity = Number(parts[1]);

      if (!Number.isFinite(quantity) || quantity < 0) {
        errors.push(`Row ${rowIndex + 1}: quantity must be zero or more.`);

        return;
      }

      if (quantity > Number(openLine.remainingQty)) {
        errors.push(`Row ${rowIndex + 1}: ${parts[1]} exceeds the ${openLine.remainingQty} remaining.`);

        return;
      }

      const item = itemsById.get(openLine.itemRef);
      const packSize = Number(openLine.packSize ?? item?.packSize ?? '1');
      const safePack = Number.isFinite(packSize) && packSize > 0 ? packSize : 1;

      updates.set(openLine.lineId, trimQuantity((quantity / safePack).toFixed(4)));
    });

    if (errors.length > 0) {
      setImportError(errors.slice(0, 3).join(' '));

      return;
    }

    let next = [...form.data.lines];

    for (const [poLineId, enteredQuantity] of updates) {
      const openLine = openLines.find((candidate) => candidate.lineId === poLineId);

      if (openLine === undefined) {
        continue;
      }

      if (Number(enteredQuantity) <= 0) {
        next = next.filter((line) => line.po_line_id !== poLineId);

        continue;
      }

      const existingIndex = next.findIndex((line) => line.po_line_id === poLineId);

      if (existingIndex >= 0) {
        const existing = next[existingIndex];

        if (existing !== undefined) {
          next[existingIndex] = {
            ...existing,
            entered_quantity: enteredQuantity,
            quantity_delivered: multiply(enteredQuantity, existing.pack_size),
          };
        }

        continue;
      }

      const draft = draftFromPoLine(openLine, false);

      if (draft !== null) {
        next.push({
          ...draft,
          entered_quantity: enteredQuantity,
          quantity_delivered: multiply(enteredQuantity, draft.pack_size),
        });
      }
    }

    if (next.length > MAX_LINES) {
      setImportError(`That would exceed the ${MAX_LINES}-line limit.`);

      return;
    }

    setLines(next);
    setImportOpen(false);
    setImportText('');
    setImportError(null);
    setSuccessMessage(null);
    setNotice(`Applied imported quantities to ${updates.size} line${updates.size === 1 ? '' : 's'}.`);
  }

  function removeLine(index: number): void {
    setLines(form.data.lines.filter((_, lineIndex) => lineIndex !== index));
  }

  function handlePurchaseOrderChange(next: OpenPurchaseOrder | null): void {
    setSuccessMessage(null);
    setNotice(null);
    setLineSearch('');
    setOpenLines([]);
    setSelectedPurchaseOrder(next);
    form.setData({
      ...form.data,
      po_ref: next?.poNumber ?? '',
      source_type: next === null ? 'OPENING_BALANCE' : 'PROCUREMENT',
      lines: [],
    });
    form.clearErrors();
  }

  function handleSubmit(event: FormEvent): void {
    event.preventDefault();
    setNotice(null);

    form.post(routes.workspaces.inventoryGoodsReceiptsStore, {
      preserveScroll: true,
      preserveState: true,
      // P0-04: keep the workbench mounted and refresh only the affected props.
      only: [
        'items', 'locations', 'balances', 'locationItems', 'locationItemPagination',
        'selectedItemId', 'selectedLocationId', 'ledger', 'facilityStock',
        'facilityStockPagination', 'goodsReceipts', 'goodsReceiptPagination', 'status',
      ],
      onSuccess: () => {
        setPostedReceiptId(form.data.receipt_id);
        setSuccessMessage('Goods receipt posted. The workbench is ready for the next delivery.');
        form.setData({
          ...form.data,
          receipt_id: crypto.randomUUID(),
          idempotency_key: crypto.randomUUID(),
          delivery_note_number: '',
          delivery_note_missing_reason: '',
          invoice_number: '',
          invoice_date: '',
          invoice_total: '',
          received_at: '',
          remarks: '',
          reason: '',
          lines: [],
        });
        form.clearErrors();
        setLineRefreshToken((token) => token + 1);
        onSuccess?.();
      },
    });
  }

  const errors = form.errors as Record<string, string>;
  const workbenchDisabled = form.processing
    || form.data.location_id === ''
    || form.data.lines.length === 0
    || totals.invalid > 0;
  const selectedPurchaseOrderOption: PurchaseOrderOption | null = selectedPurchaseOrder === null
    ? null
    : {
      id: selectedPurchaseOrder.purchaseOrderId,
      label: `${selectedPurchaseOrder.poNumber} — ${selectedPurchaseOrder.remainingLineCount ?? selectedPurchaseOrder.lines.length} line(s) awaiting delivery`,
      purchaseOrder: selectedPurchaseOrder,
    };
  const purchaseOrderOptions: PurchaseOrderOption[] = [
    { id: 'no-purchase-order', label: 'No purchase order — donation / opening balance', purchaseOrder: null },
    ...poOptions
      .filter((po) => po.purchaseOrderId !== selectedPurchaseOrder?.purchaseOrderId)
      .map((po) => ({
        id: po.purchaseOrderId,
        label: `${po.poNumber} — ${po.remainingLineCount ?? po.lines.length} line(s) awaiting delivery`,
        purchaseOrder: po,
      })),
    ...(selectedPurchaseOrderOption === null ? [] : [selectedPurchaseOrderOption]),
  ];
  // Keep the combobox mounted so typing never loses focus. Show the skeleton
  // in its place only while the field is idle and still has no selection.
  const showPoSkeleton = poLoading && !poFieldFocused && selectedPurchaseOrder === null;

  const backdated = isBackdated(form.data.received_at);

  return (
    <DockedPanel
      heading="Receive delivery"
      description="One delivery, one goods receipt — only accepted quantities reach stock."
      hidden={!open}
      onClose={onClose}
      onSubmit={handleSubmit}
      primaryLabel={form.processing ? 'Posting…' : 'Post goods receipt'}
      primaryDisabled={workbenchDisabled}
      secondaryLabel="Close"
      width="min(72rem, 100%)"
      expandedWidth="min(96rem, 100%)"
      contentClassName="afy-grn"
      expandable
    >
      {successMessage !== null ? (
        <InlineNotification
          kind="success"
          lowContrast
          title="Goods receipt posted"
          subtitle={successMessage}
          onCloseButtonClick={() => setSuccessMessage(null)}
        />
      ) : null}

      {postedReceiptId !== null && successMessage !== null ? (
        <p className="afy-grn__link">
          <a
            className="cds--link"
            href={routes.workspaces.inventoryGoodsReceiptPrint(postedReceiptId)}
            target="_blank"
            rel="noreferrer"
          >
            Print the GRN
          </a>
        </p>
      ) : null}

      {notice !== null ? (
        <InlineNotification
          kind="warning"
          lowContrast
          title="Receiving workbench"
          subtitle={notice}
          onCloseButtonClick={() => setNotice(null)}
        />
      ) : null}

      {errors.receipt !== undefined ? (
        <InlineNotification kind="error" title="Goods receipt" subtitle={errors.receipt} hideCloseButton />
      ) : null}

      {locations.length === 0 || receivableItems.length === 0 ? (
        <InlineNotification
          kind="warning"
          lowContrast
          title="Setup required"
          subtitle="Add an active stock location and a non-serial catalogue item before receiving a delivery."
          hideCloseButton
        />
      ) : null}

      {poError !== null ? (
        <InlineNotification kind="error" lowContrast title="Purchase order search" subtitle={poError} hideCloseButton />
      ) : null}

      <section aria-labelledby="goods-receipt-delivery" className="afy-grn__section">
        <h3 id="goods-receipt-delivery" className="afy-grn__section-title">Delivery details</h3>

        {canSearchPurchaseOrders ? (
          <div className={`afy-grn__po-field${showPoSkeleton ? ' afy-grn__po-field--loading' : ''}`}>
            <ComboBox<PurchaseOrderOption>
              id="goods-receipt-po"
              titleText="Purchase order"
              placeholder="Search by PO number, supplier or item"
              items={purchaseOrderOptions}
              selectedItem={selectedPurchaseOrderOption}
              itemToString={(item) => item?.label ?? ''}
              onInputChange={setPoSearch}
              onChange={({ selectedItem }) => {
                if (selectedItem !== null && selectedItem !== undefined) {
                  handlePurchaseOrderChange(selectedItem.purchaseOrder);
                }
              }}
              inputProps={{
                onFocus: () => setPoFieldFocused(true),
                onBlur: () => setPoFieldFocused(false),
              }}
              shouldFilterItem={() => true}
              invalid={Boolean(errors.po_ref)}
              invalidText={errors.po_ref}
            />
            {showPoSkeleton ? (
              <SelectSkeleton className="afy-grn__po-skeleton" aria-hidden="true" />
            ) : null}
          </div>
        ) : null}

        <div className="afy-grn__fields">
          <Select
            id="goods-receipt-location"
            labelText="Stock location"
            size="sm"
            value={form.data.location_id}
            onChange={(event) => form.setData('location_id', event.currentTarget.value)}
            invalid={Boolean(errors.location_id)}
            invalidText={errors.location_id}
          >
            {locations.map((location) => (
              <SelectItem key={location.id} value={location.id} text={`${location.code} — ${location.name}`} />
            ))}
          </Select>

          {form.data.po_ref === '' ? (
            <Select
              id="goods-receipt-source"
              labelText="Source"
              size="sm"
              value={form.data.source_type}
              onChange={(event) => form.setData('source_type', event.currentTarget.value)}
            >
              <SelectItem value="OPENING_BALANCE" text="Opening balance" />
              <SelectItem value="DONATION" text="Donation" />
              <SelectItem value="OTHER" text="Other" />
            </Select>
          ) : null}

          <TextInput
            id="goods-receipt-delivery-note"
            labelText="Delivery note"
            size="sm"
            value={form.data.delivery_note_number}
            onChange={(event) => form.setData('delivery_note_number', event.currentTarget.value)}
            invalid={Boolean(errors.delivery_note_number)}
            invalidText={errors.delivery_note_number}
          />

          <TextInput
            id="goods-receipt-received-at"
            labelText="Received at"
            size="sm"
            type="datetime-local"
            value={form.data.received_at}
            onChange={(event) => form.setData('received_at', event.currentTarget.value)}
            invalid={Boolean(errors.received_at)}
            invalidText={errors.received_at}
          />
        </div>

        {form.data.po_ref === '' ? (
          <div className="afy-grn__add-item">
            <div className="afy-grn__add-item-field">
              <SearchableComboBox
                id="goods-receipt-manual-item"
                titleText="Add an item"
                items={receivableItems.map((item) => ({
                  id: item.id,
                  label: `${item.code} — ${item.name}`,
                }))}
                selectedId={manualItemId}
                onChange={setManualItemId}
              />
            </div>
            <Button
              kind="tertiary"
              size="md"
              renderIcon={Add}
              onClick={addManualItem}
              disabled={manualItemId === ''}
            >
              Add line
            </Button>
          </div>
        ) : null}
      </section>

      {selectedPurchaseOrder !== null ? (
        <section aria-labelledby="goods-receipt-open-lines" className="afy-grn__section">
          <div className="afy-grn__section-head">
            <div>
              <h3 id="goods-receipt-open-lines" className="afy-grn__section-title">
                Open lines <span className="afy-grn__muted">· {selectedPurchaseOrder.poNumber}</span>
              </h3>
              {linesLoading ? (
                <p className="afy-visually-hidden" role="status">Loading open purchase-order lines…</p>
              ) : (
                <p className="afy-grn__section-meta">
                  {`${filteredOpenLines.length} of ${openLines.length} open lines`}
                </p>
              )}
            </div>
            <div className="afy-grn__toolbar">
              <TextInput
                id="goods-receipt-line-search"
                labelText="Filter open lines"
                hideLabel
                size="sm"
                className="afy-grn__filter"
                placeholder="Filter lines"
                value={lineSearch}
                onChange={(event) => setLineSearch(event.currentTarget.value)}
              />
              <Button
                kind="ghost"
                size="sm"
                onClick={() => {
                  setImportError(null);
                  setImportOpen(true);
                }}
                disabled={linesLoading || openLines.length === 0}
              >
                Paste quantities
              </Button>
              <Button kind="tertiary" size="sm" onClick={addAllOpenLines} disabled={linesLoading}>
                Add all remaining
              </Button>
            </div>
          </div>

          <ul
            className="afy-grn__open-lines"
            aria-label="Open purchase-order lines available to add"
            aria-busy={linesLoading}
          >
            {linesLoading ? (
              Array.from({ length: 4 }, (_, index) => (
                <li
                  key={`goods-receipt-open-line-skeleton-${index}`}
                  className="afy-grn__open-line afy-grn__open-line--skeleton"
                  aria-hidden="true"
                >
                  <div className="afy-grn__open-line-main">
                    <SkeletonText width="35%" />
                    <SkeletonText width="65%" />
                    <SkeletonText width="28%" />
                  </div>
                  <SkeletonPlaceholder className="afy-grn__skeleton-qty" />
                  <SkeletonPlaceholder className="afy-grn__skeleton-action" />
                </li>
              ))
            ) : (
              <>
                {filteredOpenLines.map((line) => {
                  const item = itemsById.get(line.itemRef);
                  const unavailable = item === undefined || !item.active || item.tracksSerial;
                  const added = draftLineIds.has(line.lineId);
                  const orderedAs = line.enteredUnit !== null && line.enteredQty !== null
                    ? `${line.enteredQty} ${line.enteredUnit}${line.packSize !== null ? ` of ${line.packSize}` : ''}`
                    : null;

                  return (
                    <li
                      key={line.lineId}
                      className={`afy-grn__open-line${added ? ' afy-grn__open-line--added' : ''}`}
                    >
                      <div className="afy-grn__open-line-main">
                        <span className="afy-grn__open-line-code">
                          {item?.code ?? line.itemCode ?? 'Unknown item'}
                          <span className="afy-grn__open-line-number">Line {line.lineNo}</span>
                        </span>
                        <span className="afy-grn__open-line-name">{item?.name ?? line.itemName ?? ''}</span>
                        <span className="afy-grn__open-line-meta">
                          {orderedAs !== null ? `Ordered ${orderedAs}` : 'Ordered in base units'}
                          {unavailable ? ' · Cannot be received here' : ''}
                        </span>
                      </div>
                      <div className="afy-grn__open-line-qty">
                        <strong>{trimQuantity(line.remainingQty)}</strong>
                        <span>{line.uom} left</span>
                      </div>
                      <Button
                        kind={added ? 'ghost' : 'tertiary'}
                        size="sm"
                        onClick={() => addPoLine(line, true)}
                        disabled={unavailable || added || Number.parseFloat(line.remainingQty) <= 0}
                      >
                        {added ? 'Added' : 'Add'}
                      </Button>
                    </li>
                  );
                })}
                {filteredOpenLines.length === 0 ? (
                  <li className="afy-grn__empty">No open lines match this filter.</li>
                ) : null}
              </>
            )}
          </ul>
        </section>
      ) : null}

      <section aria-labelledby="goods-receipt-lines" className="afy-grn__section">
        <div className="afy-grn__section-head">
          <h3 id="goods-receipt-lines" className="afy-grn__section-title">Receipt lines</h3>
          <p className="afy-grn__summary">
            <span>{form.data.lines.length} of {MAX_LINES} lines</span>
            <span className="afy-grn__stat afy-grn__stat--accepted">
              {trimQuantity(totals.accepted.toFixed(4))} accepted
            </span>
            <span className="afy-grn__stat afy-grn__stat--rejected">
              {trimQuantity(totals.rejected.toFixed(4))} rejected
            </span>
          </p>
        </div>

        {form.data.lines.length === 0 ? (
          <p className="afy-grn__empty afy-grn__empty--boxed">
            {selectedPurchaseOrder !== null
              ? 'Add lines from the open purchase-order list above.'
              : 'Add an item above to start this receipt.'}
          </p>
        ) : (
          <div className="afy-grn__table-wrap">
            <table className="afy-table afy-grn__table">
              <caption className="afy-visually-hidden">Goods receipt lines</caption>
              <thead>
                <tr>
                  <th scope="col">Item</th>
                  <th scope="col">Delivered</th>
                  <th scope="col">Rejected</th>
                  <th scope="col">Accepted</th>
                  <th scope="col">Batch</th>
                  <th scope="col">Expiry</th>
                  <th scope="col">Condition</th>
                  <th scope="col"><span className="afy-visually-hidden">Actions</span></th>
                </tr>
              </thead>
              <tbody>
                {form.data.lines.map((line, index) => {
                  const item = itemsById.get(line.item_id);
                  const deliveredError = errorFor(errors, `lines.${index}.quantity_delivered`)
                    ?? errorFor(errors, `lines.${index}.entered_quantity`);
                  const rejectedError = errorFor(errors, `lines.${index}.quantity_rejected`);
                  const batchError = errorFor(errors, `lines.${index}.batch_number`);
                  const expiryError = errorFor(errors, `lines.${index}.expiry_date`);
                  const conditionError = errorFor(errors, `lines.${index}.condition`);
                  const rejectionReasonError = errorFor(errors, `lines.${index}.rejection_reason`);
                  const itemError = errorFor(errors, `lines.${index}.item_id`);
                  const packError = errorFor(errors, `lines.${index}.pack_size`);
                  const substituteError = errorFor(errors, `lines.${index}.substituted_for_item_id`);
                  const conditionNotesError = errorFor(errors, `lines.${index}.condition_notes`);
                  const poLine = openLines.find((candidate) => candidate.lineId === line.po_line_id);
                  const isSubstitution = line.substituted_for_item_id !== '';

                  return (
                    <tr key={line.key}>
                      <td className="afy-grn__item-cell">
                        <div className="afy-grn__line-item">
                          <strong>{item?.code ?? 'Unknown item'}</strong>
                          <span>{item?.name ?? ''}</span>
                          {line.po_line_id !== '' ? (
                            <span className="afy-grn__line-po">PO line {poLine?.lineNo ?? '—'}</span>
                          ) : null}
                        </div>

                        {poLine !== undefined && line.condition === 'WRONG_ITEM' ? (
                          <div className="afy-grn__substitute">
                            <Select
                              id={`goods-receipt-substitute-${index}`}
                              labelText={`Substitute for ${poLine.itemCode}`}
                              hideLabel
                              size="sm"
                              value={isSubstitution ? line.item_id : ''}
                              onChange={(event) => {
                                const substituteId = event.currentTarget.value;

                                if (substituteId === '') {
                                  const original = itemsById.get(poLine.itemRef);
                                  const packSize = poLine.packSize ?? original?.packSize ?? '1';

                                  updateLine(index, {
                                    item_id: poLine.itemRef,
                                    substituted_for_item_id: '',
                                    condition_notes: '',
                                    entered_unit: poLine.enteredUnit
                                      ?? original?.packUnit
                                      ?? original?.unitOfMeasure
                                      ?? line.entered_unit,
                                    pack_size: packSize,
                                    quantity_delivered: multiply(line.entered_quantity, packSize),
                                  });

                                  return;
                                }

                                const substitute = itemsById.get(substituteId);

                                updateLine(index, {
                                  item_id: substituteId,
                                  substituted_for_item_id: poLine.itemRef,
                                  condition: 'WRONG_ITEM',
                                  entered_unit: substitute?.unitOfMeasure ?? line.entered_unit,
                                  pack_size: '1',
                                  quantity_delivered: line.entered_quantity,
                                  batch_number: '',
                                  expiry_date: '',
                                  manufacture_date: '',
                                });
                              }}
                              invalid={Boolean(substituteError)}
                              invalidText={substituteError}
                            >
                              <SelectItem value="" text="No substitute — reject the line" />
                              {items
                                .filter((option) => option.id !== poLine.itemRef)
                                .map((option) => (
                                  <SelectItem
                                    key={option.id}
                                    value={option.id}
                                    text={`${option.code} — ${option.name}`}
                                  />
                                ))}
                            </Select>
                            {isSubstitution ? (
                              <TextInput
                                id={`goods-receipt-substitution-reason-${index}`}
                                labelText={`Reason for substituting ${poLine.itemCode}`}
                                hideLabel
                                size="sm"
                                value={line.condition_notes}
                                onChange={(event) => updateLine(index, {
                                  condition_notes: event.currentTarget.value,
                                })}
                                invalid={Boolean(conditionNotesError)}
                                invalidText={conditionNotesError}
                                placeholder="Reason for substitution"
                              />
                            ) : null}
                          </div>
                        ) : null}

                        {itemError !== undefined ? <div className="afy-grn__field-error">{itemError}</div> : null}
                      </td>

                      <td className="afy-grn__quantity-cell">
                        <TextInput
                          id={`goods-receipt-delivered-${index}`}
                          labelText={`${item?.code ?? 'Item'} delivered in ${line.entered_unit}`}
                          hideLabel
                          size="sm"
                          type="number"
                          min="0.0001"
                          step="0.0001"
                          value={line.entered_quantity}
                          onChange={(event) => setDelivered(index, event.currentTarget.value)}
                          invalid={Boolean(deliveredError)}
                          invalidText={deliveredError}
                        />
                        <div className="afy-grn__hint">
                          {line.entered_unit} × {line.pack_size} = {trimQuantity(line.quantity_delivered || '0')} {item?.unitOfMeasure}
                        </div>
                        {packError !== undefined ? <div className="afy-grn__field-error">{packError}</div> : null}
                      </td>

                      <td className="afy-grn__rejected-cell">
                        <TextInput
                          id={`goods-receipt-rejected-${index}`}
                          labelText={`${item?.code ?? 'Item'} rejected base quantity`}
                          hideLabel
                          size="sm"
                          type="number"
                          min="0"
                          step="0.0001"
                          value={line.quantity_rejected}
                          onChange={(event) => updateLine(index, { quantity_rejected: event.currentTarget.value })}
                          invalid={Boolean(rejectedError)}
                          invalidText={rejectedError}
                        />
                        {Number.parseFloat(line.quantity_rejected || '0') > 0 ? (
                          <TextInput
                            id={`goods-receipt-rejection-reason-${index}`}
                            labelText={`Reason for rejecting ${item?.code ?? 'item'}`}
                            hideLabel
                            size="sm"
                            className="afy-grn__rejection-reason"
                            value={line.rejection_reason}
                            onChange={(event) => updateLine(index, { rejection_reason: event.currentTarget.value })}
                            invalid={Boolean(rejectionReasonError)}
                            invalidText={rejectionReasonError}
                            placeholder="Reason"
                          />
                        ) : null}
                      </td>

                      <td className="afy-grn__accepted-cell">
                        <strong>{trimQuantity(acceptedQuantity(line))} {item?.unitOfMeasure}</strong>
                      </td>

                      <td className="afy-grn__meta-cell">
                        {item?.tracksBatch ? (
                          <TextInput
                            id={`goods-receipt-batch-${index}`}
                            labelText={`${item.code} batch number`}
                            hideLabel
                            size="sm"
                            value={line.batch_number}
                            onChange={(event) => updateLine(index, { batch_number: event.currentTarget.value })}
                            invalid={Boolean(batchError)}
                            invalidText={batchError}
                            placeholder="Batch"
                          />
                        ) : <span className="afy-grn__muted">—</span>}
                      </td>

                      <td className="afy-grn__meta-cell">
                        {item?.tracksExpiry ? (
                          <TextInput
                            id={`goods-receipt-expiry-${index}`}
                            labelText={`${item.code} expiry date`}
                            hideLabel
                            size="sm"
                            type="date"
                            value={line.expiry_date}
                            onChange={(event) => updateLine(index, { expiry_date: event.currentTarget.value })}
                            invalid={Boolean(expiryError)}
                            invalidText={expiryError}
                          />
                        ) : <span className="afy-grn__muted">—</span>}
                      </td>

                      <td className="afy-grn__condition-cell">
                        <Select
                          id={`goods-receipt-condition-${index}`}
                          labelText={`${item?.code ?? 'Item'} condition`}
                          hideLabel
                          size="sm"
                          value={line.condition}
                          onChange={(event) => {
                            const condition = event.currentTarget.value;

                            if (condition !== 'WRONG_ITEM' && line.substituted_for_item_id !== '') {
                              updateLine(index, {
                                condition,
                                item_id: poLine?.itemRef ?? line.item_id,
                                substituted_for_item_id: '',
                                condition_notes: '',
                              });

                              return;
                            }

                            updateLine(index, { condition });
                          }}
                          invalid={Boolean(conditionError)}
                          invalidText={conditionError}
                        >
                          <SelectItem value="GOOD" text="Good" />
                          <SelectItem value="DAMAGED" text="Damaged" />
                          <SelectItem value="SHORT" text="Short" />
                          <SelectItem value="NEAR_EXPIRY" text="Near expiry" />
                          <SelectItem value="WRONG_ITEM" text="Wrong item" />
                        </Select>
                      </td>

                      <td className="afy-grn__remove-cell">
                        <IconButton
                          kind="ghost"
                          size="sm"
                          label={`Remove ${item?.code ?? 'item'} line`}
                          onClick={() => removeLine(index)}
                        >
                          <TrashCan />
                        </IconButton>
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
        )}
      </section>

      <details className="afy-grn__details">
        <summary>Invoice details (optional)</summary>
        <div className="afy-grn__fields afy-grn__details-fields">
          <TextInput
            id="goods-receipt-invoice-number"
            labelText="Supplier invoice number"
            size="sm"
            value={form.data.invoice_number}
            onChange={(event) => form.setData('invoice_number', event.currentTarget.value)}
            invalid={Boolean(errors.invoice_number)}
            invalidText={errors.invoice_number}
          />
          <TextInput
            id="goods-receipt-invoice-date"
            labelText="Invoice date"
            size="sm"
            type="date"
            value={form.data.invoice_date}
            onChange={(event) => form.setData('invoice_date', event.currentTarget.value)}
            invalid={Boolean(errors.invoice_date)}
            invalidText={errors.invoice_date}
          />
          <Select
            id="goods-receipt-invoice-status"
            labelText="Invoice status"
            size="sm"
            value={form.data.invoice_status}
            onChange={(event) => form.setData('invoice_status', event.currentTarget.value)}
          >
            <SelectItem value="PENDING" text="Pending" />
            <SelectItem value="RECEIVED" text="Received" />
            <SelectItem value="NOT_REQUIRED" text="Not required" />
          </Select>
          <TextInput
            id="goods-receipt-invoice-currency"
            labelText="Currency"
            size="sm"
            value={form.data.invoice_currency}
            onChange={(event) => form.setData('invoice_currency', event.currentTarget.value.toUpperCase())}
            invalid={Boolean(errors.invoice_currency)}
            invalidText={errors.invoice_currency}
          />
          <TextInput
            id="goods-receipt-invoice-total"
            labelText="Invoice total"
            helperText="VAT-inclusive if that is what the supplier billed."
            size="sm"
            type="number"
            min="0"
            step="0.01"
            value={form.data.invoice_total}
            onChange={(event) => form.setData('invoice_total', event.currentTarget.value)}
            invalid={Boolean(errors.invoice_total)}
            invalidText={errors.invoice_total}
          />
        </div>
      </details>

      <div className="afy-grn__fields afy-grn__fields--notes">
        <TextArea
          id="goods-receipt-remarks"
          labelText="Remarks (optional)"
          value={form.data.remarks}
          onChange={(event) => form.setData('remarks', event.currentTarget.value)}
          invalid={Boolean(errors.remarks)}
          invalidText={errors.remarks}
          rows={2}
        />
        {backdated || errors.reason !== undefined ? (
          <TextInput
            id="goods-receipt-backdate-reason"
            labelText="Backdating reason"
            helperText="Required when the delivery arrived more than 24 hours ago."
            size="sm"
            value={form.data.reason}
            onChange={(event) => form.setData('reason', event.currentTarget.value)}
            invalid={Boolean(errors.reason)}
            invalidText={errors.reason}
          />
        ) : null}
      </div>

      <Modal
        open={importOpen}
        modalHeading="Paste received quantities"
        primaryButtonText="Apply quantities"
        secondaryButtonText="Cancel"
        onRequestClose={() => {
          setImportOpen(false);
          setImportError(null);
        }}
        onRequestSubmit={applyImportedQuantities}
        size="md"
      >
        <p className="afy-grn__modal-hint">
          Line number (or item code) and quantity in base units. A zero removes the line.
        </p>
        <TextArea
          id="goods-receipt-import-quantities"
          labelText="Received quantities"
          hideLabel
          rows={9}
          value={importText}
          onChange={(event) => setImportText(event.currentTarget.value)}
          placeholder={'1, 400\n2, 150\nGLOVE-M, 20'}
        />
        {importError !== null ? (
          <InlineNotification
            kind="error"
            lowContrast
            hideCloseButton
            title="Some rows could not be imported"
            subtitle={importError}
          />
        ) : null}
      </Modal>
    </DockedPanel>
  );
}

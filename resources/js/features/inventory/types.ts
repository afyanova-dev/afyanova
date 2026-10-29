export interface InventoryItem {
  id: string;
  code: string;
  name: string;
  category: string | null;
  description: string | null;
  itemType: string;
  unitOfMeasure: string;
  /** Default buying unit (e.g. BOX) — null when the item has no pack. */
  packUnit: string | null;
  /** How many base units are in one `packUnit` (e.g. '100.0000'). */
  packSize: string | null;
  tracksBatch: boolean;
  tracksExpiry: boolean;
  tracksSerial: boolean;
  /** Controlled medicine: a stock DECREASE needs a named witness (Phase 0 policy). */
  isControlled: boolean;
  active: boolean;
}

export interface StockLocation {
  id: string;
  code: string;
  name: string;
  locationType: string;
  active: boolean;
}

export interface StockLocationType {
  code: string;
  name: string;
  scope: 'BUILT_IN' | 'TENANT' | 'FACILITY';
}

export interface StockBalance {
  onHand: string;
  reserved: string;
  available: string;
  batchId: string | null;
  batchNumber: string | null;
  expiryDate: string | null;
  unitOfMeasure: string;
}

export interface OpenPurchaseOrderLine {
  lineId: string;
  lineNo: number;
  itemRef: string;
  itemCode: string | null;
  itemName: string | null;
  /** Base-unit quantities — receiving always posts base units. */
  orderedQty: string;
  receivedQty: string;
  remainingQty: string;
  uom: string;
  /** How the line was ordered ("3 BOX of 100"), for display only. */
  enteredUnit: string | null;
  enteredQty: string | null;
  packSize: string | null;
}

/**
 * A validated purchase-order selection for the receiving surface (Phase 7) —
 * only the OPEN STOCK lines still awaiting delivery. Loaded server-side through
 * the Procurement contract; empty when the user cannot read procurement.
 */
export interface OpenPurchaseOrder {
  purchaseOrderId: string;
  poNumber: string;
  status: string;
  /** Full lines are only sent for a selected order; search results omit them. */
  lines: OpenPurchaseOrderLine[];
  /** Open STOCK lines, including lines with no remaining quantity. */
  openLineCount?: number;
  /** Open STOCK lines that still have quantity to deliver. */
  remainingLineCount?: number;
}

export interface ReceiptSearchMeta {
  currentPage: number;
  lastPage: number;
  perPage: number;
  total: number;
}

export interface OpenPurchaseOrderSearchPage {
  data: OpenPurchaseOrder[];
  meta: ReceiptSearchMeta;
}

export interface OpenPurchaseOrderLinePage {
  data: OpenPurchaseOrderLine[];
  meta: ReceiptSearchMeta;
}

export interface LocationItemSummary {
  itemId: string;
  itemCode: string;
  itemName: string;
  onHand: string;
  reserved: string;
  available: string;
  uom: string;
  batches: LocationBatchSummary[];
}

export interface LocationItemPagination {
  currentPage: number;
  lastPage: number;
  perPage: number;
  total: number;
  sortBy: 'code' | 'name' | 'on_hand' | 'reserved' | 'available';
  sortDirection: 'asc' | 'desc';
  search: string;
}

export interface LocationItemListQuery {
  page: number;
  perPage: number;
  sortBy: LocationItemPagination['sortBy'];
  sortDirection: LocationItemPagination['sortDirection'];
  search: string;
}

export interface LocationBatchSummary {
  batchId: string | null;
  batchNumber: string | null;
  expiryDate: string | null;
  onHand: string;
  reserved: string;
  available: string;
  uom: string;
}

export interface StockLedgerEntry {
  movementId: string;
  movementNumber: string;
  movementType: string;
  direction: string;
  quantity: string;
  uom: string;
  /** How the receipt was typed in, e.g. '3 BOX of 100' — null for plain base-unit entries. */
  enteredUnit: string | null;
  enteredQuantity: string | null;
  packSize: string | null;
  runningBalance: string;
  sourceType: string;
  sourceRef: string | null;
  poRef: string | null;
  batchId: string | null;
  reasonText: string | null;
  actorUserId: string;
  occurredAt: string;
}

export interface StockLedger {
  itemId: string;
  locationId: string;
  currentOnHandQty: string;
  ledgerSum: string;
  drift: string;
  entries: StockLedgerEntry[];
}

/**
 * One item on the Running low worklist. Every quantity is in the item's BASE
 * unit. `onHand` counts usable stock only; `expired` is on hand but no longer
 * usable and is shown separately.
 */
export interface LowStockItem {
  itemId: string;
  itemCode: string;
  itemName: string;
  uom: string;
  packUnit: string | null;
  packSize: string | null;
  minLevel: string;
  onHand: string;
  expired: string;
  /** How much to buy to get back to the minimum. */
  shortfall: string;
  outOfStock: boolean;
}

/** A tracked minimum at this facility (the level editor pre-fills from it). */
export interface ItemLevel {
  itemId: string;
  minLevel: string;
}

/**
 * One completed stock transfer between two locations of this facility (Phase 8).
 * `quantity` is in the item's base unit; the two movement rows are the ledger,
 * this is the document.
 */
/** A goods-receipt register row (Phase 1). */
export interface GoodsReceiptSummary {
  goodsReceiptId: string;
  receiptNumber: string;
  receivedAt: string;
  sourceType: string;
  poRef: string | null;
  supplierRef: string | null;
  locationId: string;
  locationCode: string;
  locationName: string;
  deliveryNoteNumber: string | null;
  invoiceNumber: string | null;
  invoiceStatus: string;
  invoiceTotal: string | null;
  lineCount: number;
  rejectedLineCount: number;
}

export interface GoodsReceiptPagination {
  currentPage: number;
  lastPage: number;
  perPage: number;
  total: number;
  search: string;
}

/** One line on a goods-receipt detail / printed GRN. */
export interface GoodsReceiptLineDetail {
  receiptLineId: string;
  itemId: string;
  itemCode: string;
  itemName: string;
  poLineRef: string | null;
  enteredUnit: string;
  enteredQuantity: string;
  packSize: string;
  quantityDelivered: string;
  quantityRejected: string;
  quantityAccepted: string;
  poUnit: string | null;
  poUnitPrice: string | null;
  invoiceQuantity: string | null;
  invoiceUnitPrice: string | null;
  uom: string;
  batchNumber: string | null;
  expiryDate: string | null;
  manufactureDate: string | null;
  condition: string;
  substitutedForItemId: string | null;
  conditionNotes: string | null;
  rejectionReason: string | null;
  movementNumber: string | null;
}

/** The opened goods receipt, loaded on demand. */
export interface GoodsReceiptDetail {
  goodsReceiptId: string;
  receiptNumber: string;
  receivedAt: string;
  recordedAt: string | null;
  sourceType: string;
  poRef: string | null;
  supplierRef: string | null;
  locationId: string;
  locationCode: string;
  locationName: string;
  deliveryNoteNumber: string | null;
  deliveryNoteMissingReason: string | null;
  invoiceNumber: string | null;
  invoiceDate: string | null;
  invoiceStatus: string;
  invoiceCurrency: string;
  invoiceTotal: string | null;
  status: string;
  matchStatus: string;
  remarks: string | null;
  receivedByUserId: string;
  lines: GoodsReceiptLineDetail[];
}

export interface StockTransfer {
  transferId: string;
  transferNumber: string;
  itemId: string;
  itemCode: string;
  itemName: string;
  fromLocationId: string;
  fromLocationCode: string;
  toLocationId: string;
  toLocationCode: string;
  batchId: string | null;
  batchNumber: string | null;
  quantity: string;
  uom: string;
  reasonText: string | null;
  movedByUserId: string;
  movedAt: string;
}

/** One batch at the chosen source location, for the move-stock picker. */
export interface TransferSourceBatch {
  batchId: string | null;
  batchNumber: string | null;
  expiryDate: string | null;
  onHand: string;
  reserved: string;
  available: string;
  uom: string;
  /** False when the batch has expired; the server refuses to move it. */
  eligible: boolean;
}

/** An item currently held at the chosen source location. */
export interface TransferSourceItem {
  itemId: string;
  itemCode: string;
  itemName: string;
  onHand: string;
  reserved: string;
  available: string;
  uom: string;
  batches: TransferSourceBatch[];
}

/**
 * One physical stock count at one location (Phase 8). A count is a record, not
 * a movement: it never edits a balance. `lineCount` is how many (item, batch)
 * rows the sheet holds; `countedCount` how many have been written down;
 * `varianceCount` how many disagree with the system snapshot.
 */
export interface StockCount {
  countId: string;
  countNumber: string;
  facilityId: string;
  locationId: string;
  locationCode: string;
  locationName: string;
  status: 'IN_PROGRESS' | 'COMPLETED' | 'DISCARDED' | string;
  notes: string | null;
  startedByUserId: string;
  startedAt: string;
  closedAt: string | null;
  lineCount: number;
  countedCount: number;
  varianceCount: number;
}

/**
 * One line of a count sheet. `systemQty` is the snapshot taken when the count
 * started; `countedQty` is what was found, or null when the line has not been
 * counted yet (null is NOT zero). `variance` is counted − system.
 *
 * `isControlled` asks the sheet for a witness when a controlled medicine has a
 * shortfall. `adjustmentId` / `adjustmentStatus` link the difference to the
 * correction raised at completion; `varianceSkippedReason` explains why a
 * difference was deliberately not raised automatically.
 */
export interface StockCountLine {
  lineId: string;
  itemId: string;
  itemCode: string;
  itemName: string;
  uom: string;
  packUnit: string | null;
  packSize: string | null;
  batchId: string | null;
  batchNumber: string | null;
  systemQty: string;
  countedQty: string | null;
  variance: string | null;
  isControlled: boolean;
  adjustmentId: string | null;
  adjustmentStatus: string | null;
  varianceSkippedReason: string | null;
}

/** One count with its sheet — loaded only when a count is opened. */
export interface StockCountSheet {
  count: StockCount;
  lines: StockCountLine[];
}

/**
 * One batch on the expiry worklist. `daysRemaining` is 0 when the batch expires
 * today (still usable until the end of the day); negative means already
 * expired. `expired` comes from the server's single expiry rule.
 */
export interface ExpiringStock {
  balanceId: string;
  itemId: string;
  itemCode: string;
  itemName: string;
  locationId: string;
  locationCode: string;
  batchId: string;
  batchNumber: string;
  expiryDate: string;
  daysRemaining: number;
  onHand: string;
  uom: string;
  expired: boolean;
  /** Mirror of the item master flag: the removal request must name a witness. */
  controlled: boolean;
}

export interface StockAdjustment {
  adjustmentId: string;
  itemId: string;
  locationId: string;
  batchId: string | null;
  direction: string;
  quantity: string;
  reasonCode: string;
  reasonText: string | null;
  witnessName: string | null;
  status: string;
  requestedByUserId: string;
  requestedAt: string;
  approvedByUserId: string | null;
  approvedAt: string | null;
  rejectionReason: string | null;
  resultingMovementId: string | null;
}

export interface StockReservation {
  reservationId: string;
  itemId: string;
  locationId: string;
  batchId: string | null;
  reservedQty: string;
  consumerType: string;
  consumerRef: string | null;
  reason: string | null;
  holdUntil: string | null;
  status: string;
  releasedReason: string | null;
  fulfilledByMovementId: string | null;
  requestedByUserId: string;
  createdAt: string | null;
}

export interface FacilityStockRow {
  balanceId: string;
  itemId: string;
  itemCode: string;
  itemName: string;
  locationId: string;
  locationCode: string;
  batchId: string | null;
  batchNumber: string | null;
  expiryDate: string | null;
  onHand: string;
  reserved: string;
  available: string;
  uom: string;
}

export interface FacilityStockPagination {
  currentPage: number;
  lastPage: number;
  perPage: number;
  total: number;
  sortBy: 'code' | 'location' | 'on_hand' | 'reserved' | 'available';
  sortDirection: 'asc' | 'desc';
  search: string;
  locationId: string | null;
}

export interface FacilityStockQuery {
  page: number;
  perPage: number;
  sortBy: FacilityStockPagination['sortBy'];
  sortDirection: FacilityStockPagination['sortDirection'];
  search: string;
  locationId: string | null;
}

export type InventoryRightPanelType =
  | 'goods-receipt'
  | 'receipt-detail'
  | 'request-adjustment'
  | 'move-stock'
  | 'start-stock-count'
  | 'stock-count-sheet'
  | 'add-item'
  | 'add-location'
  | 'add-location-type'
  | null;

export interface InventoryWorkspaceProps {
  items: InventoryItem[];
  locations: StockLocation[];
  locationTypes: StockLocationType[];
  selectedItemId: string | null;
  selectedLocationId: string | null;
  departmentScoped?: boolean;
  departmentName?: string | null;
  balances: StockBalance[];
  locationItems?: LocationItemSummary[];
  locationItemPagination?: LocationItemPagination;
  ledger?: StockLedger | null;
  adjustments?: StockAdjustment[];
  adjustmentStatusFilter?: string | null;
  reservations?: StockReservation[];
  reservationStatusFilter?: string | null;
  facilityStock?: FacilityStockRow[];
  facilityStockPagination?: FacilityStockPagination;
  expiringStock?: ExpiringStock[];
  expiryWindowDays?: number;
  lowStock?: LowStockItem[];
  itemLevels?: ItemLevel[];
  canManageLevels: boolean;
  transfers?: StockTransfer[];
  /** Stock at the chosen source location, loaded on demand. */
  transferSource?: TransferSourceItem[];
  transferFromLocationId?: string | null;
  canTransfer?: boolean;
  /** Recent physical stock counts at this facility. */
  stockCounts?: StockCount[];
  canManageCounts?: boolean;
  /** The opened count sheet, loaded on demand (`?section=counts&count_id=…`). */
  stockCountSheet?: StockCountSheet | null;
  /** Goods-receipt register (Phase 1). */
  goodsReceipts?: GoodsReceiptSummary[];
  goodsReceiptPagination?: GoodsReceiptPagination;
  /** The opened goods receipt (`?section=receipts&receipt_id=…`). */
  goodsReceiptDetail?: GoodsReceiptDetail | null;
  canReceive: boolean;
  canMatchInvoices: boolean;
  /** True when the user can also search/receive against open purchase orders. */
  canReceiveAgainstPurchaseOrders?: boolean;
  /** Test/legacy fallback; production searches through the JSON endpoints. */
  canRequestAdjustment: boolean;
  canApproveAdjustments: boolean;
  currentUserId: string;
  canManageItems: boolean;
  canManageLocations: boolean;
  canManageLocationTypes: boolean;
  contextMode: 'general' | 'facility';
  itemTypes: string[];
  itemCategories: string[];
  unitsOfMeasure: string[];
  errors: Record<string, string[]>;
  status: string | null;
  currentSection?: string;
  onSectionChange?: (
    section: string,
    href?: string,
    params?: Record<string, string | undefined>,
  ) => void;
}

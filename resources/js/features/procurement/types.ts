export interface Supplier {
  id: string;
  code: string;
  name: string;
  registrationNumber: string | null;
  licenceNumber: string | null;
  licenceAuthority: string | null;
  licenceExpiryDate: string | null;
  licenceExpiryStatus: 'MISSING' | 'EXPIRED' | 'EXPIRING_SOON' | 'CURRENT';
  contactName: string | null;
  contactEmail: string | null;
  contactPhone: string | null;
  address: string | null;
  taxIdentifier: string | null;
  paymentTerms: string | null;
  categories: string[];
  status: string;
  statusReason: string | null;
  /** The lifecycle moves this supplier may take next (server-computed). */
  allowedStatuses: string[];
}

/** One order line, already snapshot by the server (never re-resolved here). */
export interface PurchaseOrderLine {
  id: string;
  lineNo: number;
  itemCode: string | null;
  itemName: string | null;
  description: string | null;
  /** What the buyer typed — in `unit`, not necessarily the base unit. */
  orderedQty: string;
  /** The unit entered (e.g. BOX); equal to `uom` when ordered in the base unit. */
  unit: string;
  /** Base units in one `unit`; null when the line is in the base unit. */
  packSize: string | null;
  /** `orderedQty` in the base unit — what stock is received against. */
  orderedBaseQty: string;
  receivedQty: string;
  uom: string;
  unitPrice: string;
  lineTotal: string;
  status: string;
}

export interface PurchaseOrder {
  id: string;
  poNumber: string;
  supplierId: string;
  requisitionId: string | null;
  sourcingMethod: string | null;
  status: string;
  currency: string;
  priority: string;
  emergency: boolean;
  orderTotal: string;
  notes: string | null;
  costCentreRef: string | null;
  budgetRef: string | null;
  createdByUserId: string;
  approvedByUserId: string | null;
  issuedByUserId: string | null;
  cancellationReason: string | null;
  /** P1-10 — why the outstanding balance was abandoned. */
  closedShortReason: string | null;
  closedShortAt: string | null;
  createdAt: string | null;
  amendmentCount: number;
  /** May one person approve/issue this order (the money limit covers it)? */
  selfApprovalPermitted: boolean;
  /** What the current user may do now — computed by the server. */
  allowedActions: string[];
  lines: PurchaseOrderLine[];
}

/** An active catalogue item offered as an order line. */
export interface ProcurementItem {
  id: string;
  code: string;
  name: string;
  uom: string;
  /** The item's optional default buying unit, e.g. BOX with 100 base units in it. */
  packUnit: string | null;
  packSize: string | null;
  category: string | null;
  itemType: string;
}

/** One request line, already snapshot by the server. */
export interface PurchaseRequisitionLine {
  id: string;
  lineNo: number;
  itemRef: string | null;
  itemCode: string | null;
  itemName: string | null;
  description: string | null;
  quantity: string;
  uom: string;
  unit: string;
  packSize: string | null;
  requiredDate: string | null;
  status: string;
}

export interface PurchaseRequisition {
  id: string;
  requisitionNumber: string;
  departmentRef: string | null;
  departmentName: string | null;
  status: string;
  priority: string;
  reason: string | null;
  costCentreRef: string | null;
  budgetRef: string | null;
  estimatedValue: string | null;
  emergency: boolean;
  emergencyReason: string | null;
  reviewedByUserId: string | null;
  reviewedAt: string | null;
  rejectionReason: string | null;
  createdByUserId: string;
  createdAt: string | null;
  hasActivePurchaseOrder: boolean;
  /** What the current user may do now — computed by the server. */
  allowedActions: string[];
  lines: PurchaseRequisitionLine[];
}

export interface Department {
  id: string;
  name: string;
  code: string;
}

/** An APPROVED request the order panel opens prefilled from. */
export interface InitialRequest {
  id: string;
  requisitionNumber: string;
  departmentRef: string | null;
  departmentName: string | null;
  priority: string;
  reason: string | null;
  costCentreRef: string | null;
  budgetRef: string | null;
  lines: Array<{
    itemId: string;
    itemCode: string | null;
    itemName: string | null;
    quantity: string;
    uom: string;
    unit: string;
    packSize: string | null;
  }>;
  unorderableLineCount: number;
}

export interface ProcurementWorkspaceProps {
  section: string;
  facilityContextRequired: boolean;
  suppliers: Supplier[];
  supplierStatuses: string[];
  canManageSuppliers: boolean;
  canCreateOrders: boolean;
  canCreateRequests: boolean;
  canCreateForAnyDepartment: boolean;
  currentUserId: string | null;
  purchaseOrders: PurchaseOrder[];
  purchaseRequisitions: PurchaseRequisition[];
  departments: Department[];
  items: ProcurementItem[];
  effectiveApprovalLimit: string | null;
  initialRequest: InitialRequest | null;
  orderableRequests: InitialRequest[];
}

export function formatStatus(status: string): string {
  return status
    .toLowerCase()
    .split('_')
    .map((part) => part.charAt(0).toUpperCase() + part.slice(1))
    .join(' ');
}

/** `1000000.00` → `1,000,000.00` without assuming an Intl locale is present. */
export function formatAmount(amount: string): string {
  const [rawWhole = '0', fraction] = amount.split('.');
  const sign = rawWhole.startsWith('-') ? '-' : '';
  const whole = sign === '' ? rawWhole : rawWhole.slice(1);
  const grouped = whole.replace(/\B(?=(\d{3})+(?!\d))/g, ',');
  const decimals = fraction === undefined ? '00' : fraction.padEnd(2, '0').slice(0, 2);

  return `${sign}${grouped}.${decimals}`;
}

export function formatMoney(amount: string, currency: string): string {
  return `${currency} ${formatAmount(amount)}`;
}

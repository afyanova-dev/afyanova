/**
 * Billing price-list admin view models (frontend-foundation-inventory-operations.md
 * Phase 5). Hand-written mirrors of the backend's `CatalogDashboardProps` DTOs
 * plus the local tab/panel state the workspace owns.
 */

export interface CatalogServiceItem {
  billingServiceId: string;
  code: string;
  name: string;
  category: string;
  status: string;
  amount: string | null;
  resolvedScope: string;
  currency: string;
  effectiveFrom: string | null;
}

export interface CatalogTariffHistoryItem {
  tariffId: string;
  resolvedScope: string;
  amount: string;
  currency: string;
  effectiveFrom: string;
  effectiveTo: string | null;
  status: string;
  current: boolean;
}

export interface CatalogPendingEventItem {
  billableEventId: string;
  patientDisplayName: string;
  patientNumber: string;
  quantity: string;
  pendingReason: string;
  occurredAt: string;
}

export interface CatalogItemMapItem {
  invItemId: string;
  code: string;
  name: string;
  itemType: string;
  itemActive: boolean;
  mapped: boolean;
  billingServiceId: string | null;
  serviceCode: string | null;
  serviceName: string | null;
  serviceStatus: string | null;
  amount: string | null;
  resolvedScope: string | null;
  currency: string | null;
  mappedAt: string | null;
}

export interface CatalogItemMapHistoryItem {
  mapId: string;
  invItemId: string;
  billingServiceId: string;
  serviceName: string | null;
  active: boolean;
  occurredAt: string;
}

export interface CatalogWorkspaceProps {
  facilityId: string;
  canManageCatalog: boolean;
  canRetryCharges: boolean;
  services: CatalogServiceItem[];
  category: string | null;
  status: string | null;
  selectedServiceId: string | null;
  selectedCode: string | null;
  selectedName: string | null;
  selectedCategory: string | null;
  selectedStatus: string | null;
  facilityAmount: string | null;
  facilityEffectiveFrom: string | null;
  defaultAmount: string | null;
  defaultEffectiveFrom: string | null;
  currency: string;
  pendingEvents: CatalogPendingEventItem[];
  tariffHistory: CatalogTariffHistoryItem[];
  medicationMaps: CatalogItemMapItem[];
  itemMapHistory: CatalogItemMapHistoryItem[];
  searchError: string | null;
  errors: Record<string, string[]>;
  /** Inertia flash message (distinct from the `status` filter). */
  flashStatus: string | null;
}

export type CatalogTab = 'services' | 'mapping' | 'pending';

export type CatalogPanelType =
  | 'add-service'
  | 'set-tariff'
  | 'end-tariff'
  | 'map-medication'
  | null;

export interface CatalogFilterChange {
  category?: string | null;
  status?: string | null;
  serviceId?: string | null;
}

/** The coded category set (mirrors `BillingServiceCategory::ALL`). */
export const CATALOG_CATEGORIES = [
  'CONSULTATION',
  'PROCEDURE',
  'LABORATORY',
  'RADIOLOGY',
  'MEDICATION',
  'BED_DAY',
  'OTHER',
] as const;

/** Human copy for the four worklist reason states (mirrors `BillablePendingReason`). */
export const PENDING_REASON_LABELS: Record<string, string> = {
  ITEM_UNMAPPED: 'No mapped service',
  SERVICE_UNPRICED: 'Mapped service has no tariff',
  SERVICE_INACTIVE: 'Mapped service is inactive',
};

/** The three tariff scopes the rail can distinguish (`TariffResolution`). */
export const SCOPE_LABELS: Record<string, string> = {
  FACILITY: 'Facility override',
  TENANT_DEFAULT: 'Tenant default',
  NONE: 'Unpriced',
};

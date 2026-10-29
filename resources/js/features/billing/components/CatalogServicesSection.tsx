import { Button, Select, SelectItem } from '@carbon/react';
import { router } from '@inertiajs/react';
import { useMemo, type ReactNode } from 'react';
import { routes } from '@/app/routes';
import { CarbonDataTable } from '@/components/ui/CarbonDataTable';
import { EmptyState, Status } from '@/components/ui';
import {
  CATALOG_CATEGORIES,
  SCOPE_LABELS,
  type CatalogFilterChange,
  type CatalogServiceItem,
  type CatalogTariffHistoryItem,
} from '../types';

export interface CatalogServicesSectionProps {
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
  tariffHistory: CatalogTariffHistoryItem[];
  canManageCatalog: boolean;
  onFilterChange: (change: CatalogFilterChange) => void;
  onSelectService: (serviceId: string | null) => void;
  onAddService: () => void;
  onOpenTariff: (mode: 'set' | 'end') => void;
}

function formatMoney(amount: string | null, currency: string | null): string {
  return amount === null ? 'Unpriced' : `${amount} ${currency ?? ''}`.trim();
}

export function CatalogServicesSection({
  services,
  category,
  status,
  selectedServiceId,
  selectedCode,
  selectedName,
  selectedCategory,
  selectedStatus,
  facilityAmount,
  facilityEffectiveFrom,
  defaultAmount,
  defaultEffectiveFrom,
  currency,
  tariffHistory,
  canManageCatalog,
  onFilterChange,
  onSelectService,
  onAddService,
  onOpenTariff,
}: CatalogServicesSectionProps): ReactNode {
  const rows = useMemo(
    () => services.map((service) => ({
      id: service.billingServiceId,
      code: service.code,
      name: service.name,
      category: service.category,
      status: service.status,
      tariff: formatMoney(service.amount, service.currency),
      scope: SCOPE_LABELS[service.resolvedScope] ?? service.resolvedScope,
      effectiveFrom: service.effectiveFrom ?? '—',
    })),
    [services],
  );

  const historyRows = useMemo(
    () => tariffHistory.map((entry) => ({
      id: entry.tariffId,
      scope: SCOPE_LABELS[entry.resolvedScope] ?? entry.resolvedScope,
      amount: `${entry.amount} ${entry.currency}`.trim(),
      from: entry.effectiveFrom,
      to: entry.effectiveTo ?? 'open',
      state: entry.current ? 'In force today' : entry.status === 'CURRENT' ? 'Scheduled' : 'Superseded',
    })),
    [tariffHistory],
  );

  const hasOpenTariff = facilityAmount !== null || defaultAmount !== null;

  function toggleStatus(): void {
    if (selectedServiceId === null) return;
    router.post(
      routes.workspaces.billingCatalogServiceStatus(selectedServiceId),
      { status: selectedStatus === 'ACTIVE' ? 'INACTIVE' : 'ACTIVE' },
      { preserveScroll: true },
    );
  }

  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: '1.5rem' }}>
      <CarbonDataTable
        rows={rows}
        headers={[
          { key: 'code', header: 'Code' },
          { key: 'name', header: 'Name' },
          { key: 'category', header: 'Category' },
          { key: 'status', header: 'Status' },
          { key: 'tariff', header: 'Effective tariff' },
          { key: 'scope', header: 'Scope' },
          { key: 'effectiveFrom', header: 'From' },
          { key: 'actions', header: 'Actions' },
        ]}
        title={`Price list (${services.length})`}
        ariaLabel="Billing service price list"
        emptyState={(
          <EmptyState
            title="No services yet"
            description="Add a billing service and set its tariff — dispensing and other billable acts then capture charges automatically."
            action={canManageCatalog ? <Button onClick={onAddService}>Add billing service</Button> : undefined}
          />
        )}
        toolbar={() => (
          <>
            <Select
              id="billing-catalog-category-filter"
              labelText="Category filter"
              hideLabel
              size="sm"
              value={category ?? ''}
              onChange={(event) => onFilterChange({ category: event.currentTarget.value || null })}
            >
              <SelectItem value="" text="All categories" />
              {CATALOG_CATEGORIES.map((value) => (
                <SelectItem key={value} value={value} text={value} />
              ))}
            </Select>
            <Select
              id="billing-catalog-status-filter"
              labelText="Status filter"
              hideLabel
              size="sm"
              value={status ?? ''}
              onChange={(event) => onFilterChange({ status: event.currentTarget.value || null })}
            >
              <SelectItem value="" text="All statuses" />
              <SelectItem value="ACTIVE" text="Active" />
              <SelectItem value="INACTIVE" text="Inactive" />
            </Select>
            {canManageCatalog ? <Button onClick={onAddService}>Add billing service</Button> : null}
          </>
        )}
        renderCell={(cell, row) => {
          if (cell.info.header === 'status') {
            return (
              <Status kind={cell.value === 'ACTIVE' ? 'active' : 'inactive'}>
                {String(cell.value)}
              </Status>
            );
          }
          if (cell.info.header === 'tariff') {
            return (
              <span style={{ fontVariantNumeric: 'tabular-nums', fontWeight: 600 }}>{String(cell.value)}</span>
            );
          }
          if (cell.info.header === 'actions') {
            return (
              <Button
                kind="ghost"
                size="sm"
                onClick={() => onSelectService(row.id === selectedServiceId ? null : String(row.id))}
              >
                {row.id === selectedServiceId ? 'Close' : 'Inspect'}
              </Button>
            );
          }
          if (cell.info.header === 'code') {
            return <code style={{ fontWeight: 600 }}>{String(cell.value)}</code>;
          }
          return cell.value;
        }}
      />

      {selectedServiceId !== null ? (
        <section
          aria-labelledby="billing-service-detail-heading"
          className="afy-card"
          style={{ padding: '1.5rem', display: 'flex', flexDirection: 'column', gap: '1.25rem' }}
        >
          <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', gap: '1rem', flexWrap: 'wrap' }}>
            <div>
              <h3 id="billing-service-detail-heading" style={{ fontSize: '1.125rem', fontWeight: 600, margin: 0 }}>
                {selectedName} <code style={{ fontSize: '0.875rem' }}>({selectedCode})</code>
              </h3>
              <div style={{ margin: 0, color: 'var(--cds-text-secondary, #525252)', display: 'flex', alignItems: 'center', gap: '0.5rem' }}>
                <span>{selectedCategory}</span>
                <Status kind={selectedStatus === 'ACTIVE' ? 'active' : 'inactive'}>{selectedStatus}</Status>
              </div>
            </div>
            {canManageCatalog ? (
              <div style={{ display: 'flex', gap: '0.5rem', flexWrap: 'wrap' }}>
                <Button kind="primary" size="sm" onClick={() => onOpenTariff('set')}>Set new price</Button>
                <Button kind="tertiary" size="sm" disabled={!hasOpenTariff} onClick={() => onOpenTariff('end')}>
                  End open price
                </Button>
                <Button
                  kind={selectedStatus === 'ACTIVE' ? 'danger--tertiary' : 'tertiary'}
                  size="sm"
                  onClick={toggleStatus}
                >
                  {selectedStatus === 'ACTIVE' ? 'Deactivate' : 'Reactivate'}
                </Button>
              </div>
            ) : null}
          </div>

          <dl style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(12rem, 1fr))', gap: '1rem', margin: 0 }}>
            <div>
              <dt style={{ fontSize: '0.75rem', color: 'var(--cds-text-secondary, #525252)' }}>Facility tariff</dt>
              <dd style={{ margin: 0, fontWeight: 600 }}>
                {facilityAmount !== null ? `${facilityAmount} ${currency}` : 'None — falls back to tenant default'}
                {facilityEffectiveFrom ? <div style={{ fontWeight: 400, fontSize: '0.75rem' }}>from {facilityEffectiveFrom}</div> : null}
              </dd>
            </div>
            <div>
              <dt style={{ fontSize: '0.75rem', color: 'var(--cds-text-secondary, #525252)' }}>Tenant default tariff</dt>
              <dd style={{ margin: 0, fontWeight: 600 }}>
                {defaultAmount !== null ? `${defaultAmount} ${currency}` : 'None'}
                {defaultEffectiveFrom ? <div style={{ fontWeight: 400, fontSize: '0.75rem' }}>from {defaultEffectiveFrom}</div> : null}
              </dd>
            </div>
          </dl>

          <div>
            <h4 style={{ fontSize: '1rem', fontWeight: 600, marginBlockEnd: '0.75rem' }}>Tariff history (immutable)</h4>
            <CarbonDataTable
              rows={historyRows}
              headers={[
                { key: 'scope', header: 'Scope' },
                { key: 'amount', header: 'Amount' },
                { key: 'from', header: 'From' },
                { key: 'to', header: 'To' },
                { key: 'state', header: 'State' },
              ]}
              title="Every price ever set"
              ariaLabel={`Tariff history for ${selectedCode}`}
              size="sm"
              emptyState={<p>No tariff rows yet. Set the first price to make this service billable.</p>}
            />
          </div>
        </section>
      ) : null}
    </div>
  );
}

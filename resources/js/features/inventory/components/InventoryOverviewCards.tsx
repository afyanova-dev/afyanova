import { Button, InlineNotification } from '@carbon/react';
import type { ReactNode } from 'react';
import { StatisticsCards } from '@/components/ui/StatisticsCards';
import type { InventoryItem, StockLocation } from '../types';

export interface InventoryOverviewCardsProps {
  items: InventoryItem[];
  locations: StockLocation[];
  canReceive: boolean;
  canManageItems: boolean;
  canManageLocations: boolean;
  isGeneral?: boolean;
  onNavigateSection: (sectionId: string) => void;
  onOpenPanel: (panel: 'goods-receipt' | 'add-item' | 'add-location') => void;
}

export function InventoryOverviewCards({
  items,
  locations,
  canReceive,
  canManageItems,
  canManageLocations,
  isGeneral = false,
  onNavigateSection,
  onOpenPanel,
}: InventoryOverviewCardsProps): ReactNode {
  const activeItems = items.filter((item) => item.active);
  const activeLocations = locations.filter((location) => location.active);
  const itemTypes = [...new Set(activeItems.map((item) => item.itemType))].sort();
  const medicationsCount = activeItems.filter((item) => item.itemType === 'MEDICATION').length;
  const batchTrackedCount = activeItems.filter((item) => item.tracksBatch).length;

  return (
    <section aria-labelledby="inventory-overview-heading" style={{ marginBlockEnd: '2rem' }}>
      <div style={{ marginBlockEnd: '1.25rem' }}>
        <h2 id="inventory-overview-heading" style={{ fontSize: '1.25rem', fontWeight: 600 }}>
          Inventory overview
        </h2>
        <p style={{ color: 'var(--cds-text-secondary, #525252)' }}>
          {isGeneral
            ? 'Tenant-wide inventory catalogue and shared setup.'
            : 'Operational stock setup for this tenant and active clinical facility.'}
        </p>
      </div>

      <StatisticsCards
        label="Inventory setup summary"
        cards={[
          {
            id: 'inventory-items',
            label: 'Catalogue items',
            value: activeItems.length,
            detail: `${medicationsCount} medication${medicationsCount === 1 ? '' : 's'} · ${activeItems.length - medicationsCount} other`,
          },
          ...(!isGeneral ? [{
            id: 'inventory-locations',
            label: 'Stock locations',
            value: activeLocations.length,
            detail: 'Active locations at this facility',
          }] : []),
          {
            id: 'inventory-types',
            label: 'Item classifications',
            value: itemTypes.length,
            detail: `${batchTrackedCount} batch-tracked item${batchTrackedCount === 1 ? '' : 's'}`,
          },
        ]}
      />

      <section aria-labelledby="inventory-operations-heading" style={{ marginBlock: '1.5rem' }}>
        <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', flexWrap: 'wrap', gap: '0.75rem' }}>
          <div>
            <h3 id="inventory-operations-heading" style={{ fontSize: '1rem', fontWeight: 600, margin: 0 }}>
              Quick operations & workflows
            </h3>
            <p style={{ fontSize: '0.875rem', color: 'var(--cds-text-secondary, #525252)', margin: 0 }}>
              Review balances, receive deliveries, or update the catalogue and facility locations.
            </p>
          </div>
          <div style={{ display: 'flex', flexWrap: 'wrap', gap: '0.5rem' }}>
            <Button kind="secondary" size="md" onClick={() => onNavigateSection('stock')}>
              Check stock by location
            </Button>
            {canReceive ? (
              <Button size="md" onClick={() => onOpenPanel('goods-receipt')}>
                + Receive delivery
              </Button>
            ) : null}
            {canManageItems ? (
              <Button kind="tertiary" size="md" onClick={() => onOpenPanel('add-item')}>
                + Add item
              </Button>
            ) : null}
            {canManageLocations ? (
              <Button kind="ghost" size="md" onClick={() => onOpenPanel('add-location')}>
                + Add location
              </Button>
            ) : null}
          </div>
        </div>
      </section>

      {activeItems.length === 0 || (!isGeneral && activeLocations.length === 0) ? (
        <InlineNotification
          kind="warning"
          title="Inventory setup required"
          subtitle={activeItems.length === 0
            ? 'No active catalogue items are configured for this tenant. Add items before recording stock.'
            : 'No active stock locations are configured at this facility. Add locations to store stock.'}
          hideCloseButton
          style={{ marginBlockEnd: '1rem' }}
        />
      ) : null}
      {isGeneral ? (
        <InlineNotification
          kind="info"
          title="General context"
          subtitle="Catalogue changes apply across the tenant. Select a facility to inspect or manage its stock and storage locations."
          hideCloseButton
        />
      ) : null}

      <InlineNotification
        kind="info"
        title="Clinical Dispensing Safety"
        subtitle="Stock availability reflects on-hand balances. Availability does not yet confirm clinical dispensing eligibility or exclude expired batches. Always verify Pharmacy prescription safety before dispensing."
        hideCloseButton
      />
    </section>
  );
}

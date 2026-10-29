import { InlineNotification, TabPanel, TabPanels, Tabs } from '@carbon/react';
import { router } from '@inertiajs/react';
import { useEffect, useState, type ReactNode } from 'react';
import { routes } from '@/app/routes';
import { ContainedTabList, FormErrors, IconTab } from '@/components/ui';
import { Medication, Money, Pending } from '@/icons';
import { AddServicePanel } from './panels/AddServicePanel';
import { TariffPanel } from './panels/TariffPanel';
import { MapMedicationPanel } from './panels/MapMedicationPanel';
import { CatalogServicesSection } from './CatalogServicesSection';
import { CatalogMappingSection } from './CatalogMappingSection';
import { CatalogPendingChargesSection } from './CatalogPendingChargesSection';
import type { CatalogFilterChange, CatalogPanelType, CatalogTab, CatalogWorkspaceProps } from '../types';

const TABS: CatalogTab[] = ['services', 'mapping', 'pending'];

/**
 * The Billing price-list admin workspace: services & tariffs, the medication →
 * service mapping worklist, and pending charges. Filters and the selected
 * service ride the URL (`category`, `status`, `service_id`) so partial reloads
 * keep the rest of the page still.
 */
export function CatalogWorkspace(props: CatalogWorkspaceProps): ReactNode {
  const [tab, setTab] = useState<CatalogTab>('services');
  const [activePanel, setActivePanel] = useState<CatalogPanelType>(null);
  const [panelItemId, setPanelItemId] = useState<string | null>(null);

  const errorKey = Object.keys(props.errors).sort().join('|');

  useEffect(() => {
    // A failed write (e.g. the `catalog` pseudo-field) leaves any open panel so
    // the workspace-level error is visible rather than hidden behind it.
    if (errorKey !== '') {
      setActivePanel(null);
    }
  }, [errorKey]);

  function reload(change: CatalogFilterChange): void {
    router.get(
      routes.workspaces.billingCatalog,
      {
        category: (change.category === undefined ? props.category : change.category) ?? undefined,
        status: (change.status === undefined ? props.status : change.status) ?? undefined,
        service_id: (change.serviceId === undefined ? props.selectedServiceId : change.serviceId) ?? undefined,
      },
      {
        preserveScroll: true,
        preserveState: true,
        only: [
          'services',
          'category',
          'status',
          'selectedServiceId',
          'selectedCode',
          'selectedName',
          'selectedCategory',
          'selectedStatus',
          'facilityAmount',
          'facilityEffectiveFrom',
          'defaultAmount',
          'defaultEffectiveFrom',
          'currency',
          'tariffHistory',
        ],
      },
    );
  }

  function openMapPanel(itemId: string): void {
    setPanelItemId(itemId);
    setActivePanel('map-medication');
  }

  return (
    <div className="afyanova-billing-catalog" style={{ display: 'flex', flexDirection: 'column', gap: '1rem' }}>
      {props.flashStatus ? (
        <InlineNotification kind="success" title="Success" subtitle={props.flashStatus} hideCloseButton />
      ) : null}
      <FormErrors errors={props.errors} fields={[]} />

      {props.searchError ? (
        <InlineNotification kind="error" title="Unable to load the catalogue" subtitle={props.searchError} hideCloseButton />
      ) : null}

      <Tabs
        selectedIndex={TABS.indexOf(tab)}
        onChange={({ selectedIndex }) => setTab(TABS[selectedIndex] ?? 'services')}
      >
        <ContainedTabList aria-label="Billing catalogue views">
          <IconTab icon={Money}>Services &amp; tariffs</IconTab>
          <IconTab icon={Medication}>Medication mapping</IconTab>
          <IconTab icon={Pending}>Pending charges{props.pendingEvents.length > 0 ? ` (${props.pendingEvents.length})` : ''}</IconTab>
        </ContainedTabList>
        <TabPanels>
          <TabPanel>
            <CatalogServicesSection
              services={props.services}
              category={props.category}
              status={props.status}
              selectedServiceId={props.selectedServiceId}
              selectedCode={props.selectedCode}
              selectedName={props.selectedName}
              selectedCategory={props.selectedCategory}
              selectedStatus={props.selectedStatus}
              facilityAmount={props.facilityAmount}
              facilityEffectiveFrom={props.facilityEffectiveFrom}
              defaultAmount={props.defaultAmount}
              defaultEffectiveFrom={props.defaultEffectiveFrom}
              currency={props.currency}
              tariffHistory={props.tariffHistory}
              canManageCatalog={props.canManageCatalog}
              onFilterChange={reload}
              onSelectService={(serviceId) => reload({ serviceId })}
              onAddService={() => setActivePanel('add-service')}
              onOpenTariff={(mode) => setActivePanel(mode === 'set' ? 'set-tariff' : 'end-tariff')}
            />
          </TabPanel>
          <TabPanel>
            <CatalogMappingSection
              medications={props.medicationMaps}
              history={props.itemMapHistory}
              canManageCatalog={props.canManageCatalog}
              onMap={openMapPanel}
            />
          </TabPanel>
          <TabPanel>
            <CatalogPendingChargesSection events={props.pendingEvents} canRetryCharges={props.canRetryCharges} />
          </TabPanel>
        </TabPanels>
      </Tabs>

      <AddServicePanel open={activePanel === 'add-service'} onClose={() => setActivePanel(null)} />
      <TariffPanel
        open={activePanel === 'set-tariff' || activePanel === 'end-tariff'}
        mode={activePanel === 'end-tariff' ? 'end' : 'set'}
        serviceId={props.selectedServiceId}
        serviceName={props.selectedName}
        currency={props.currency}
        onClose={() => setActivePanel(null)}
      />
      <MapMedicationPanel
        open={activePanel === 'map-medication'}
        medications={props.medicationMaps}
        services={props.services}
        initialItemId={panelItemId}
        onClose={() => setActivePanel(null)}
      />
    </div>
  );
}

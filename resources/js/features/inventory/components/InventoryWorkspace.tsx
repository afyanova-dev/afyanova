import { InlineNotification, TabPanel, TabPanels, Tabs } from '@carbon/react';
import { router } from '@inertiajs/react';
import { useEffect, useMemo, useState, type ReactNode } from 'react';
import { routes } from '@/app/routes';
import type { InventoryRightPanelType, InventoryWorkspaceProps, LocationItemListQuery, FacilityStockQuery } from '../types';
import { InventoryOverviewCards } from './InventoryOverviewCards';
import { InventoryItemsTable } from './InventoryItemsTable';
import { InventoryLocationsTable } from './InventoryLocationsTable';
import { InventoryLocationTypesTable } from './InventoryLocationTypesTable';
import { StockLedgerPanel } from './StockLedgerPanel';
import { StockLookupSection } from './StockLookupSection';
import { ReceiptsSection } from './ReceiptsSection';
import { ReceiptDetailPanel } from './ReceiptDetailPanel';
import { FacilityStockSection } from './FacilityStockSection';
import { ExpirySection } from './ExpirySection';
import { LowStockSection } from './LowStockSection';
import { TransfersSection } from './TransfersSection';
import { StockCountsSection } from './StockCountsSection';
import { AdjustmentsSection } from './AdjustmentsSection';
import { ReservationsSection } from './ReservationsSection';
import { AddItemPanel } from './panels/AddItemPanel';
import { AddLocationPanel } from './panels/AddLocationPanel';
import { AddLocationTypePanel } from './panels/AddLocationTypePanel';
import { GoodsReceiptWorkbenchPanel } from './panels/GoodsReceiptWorkbenchPanel';
import { RequestAdjustmentPanel } from './panels/RequestAdjustmentPanel';
import { MoveStockPanel } from './panels/MoveStockPanel';
import { StartStockCountPanel } from './panels/StartStockCountPanel';
import { StockCountSheetPanel } from './panels/StockCountSheetPanel';
import { Building, Location } from '@/icons';
import { ContainedTabList, IconTab } from '@/components/ui';

export function InventoryWorkspace(props: InventoryWorkspaceProps): ReactNode {
  const [activeSection, setActiveSection] = useState<string>(props.currentSection || 'overview');
  const [activePanel, setActivePanel] = useState<InventoryRightPanelType>(null);
  const [panelContext, setPanelContext] = useState<{
    itemId?: string | null;
    locationId?: string | null;
  }>({
    itemId: props.selectedItemId,
    locationId: props.selectedLocationId,
  });

  useEffect(() => {
    if (props.currentSection && props.currentSection !== activeSection) {
      setActiveSection(props.currentSection);
    }
  }, [props.currentSection, activeSection]);

  // `?section=counts&count_id=…` (a reload, or a link someone shared) fetches
  // the sheet on the server; open the panel so the loaded sheet is visible
  // instead of silently sitting in the page props.
  const openSheetId = props.stockCountSheet?.count.countId ?? null;
  useEffect(() => {
    if (openSheetId !== null && props.currentSection === 'counts') {
      setActivePanel('stock-count-sheet');
    }
  }, [openSheetId, props.currentSection]);

  // `?section=receipts&receipt_id=…` loads one GRN on the server; open its
  // read-only panel so a shared/reloaded link shows the document.
  const openReceiptId = props.goodsReceiptDetail?.goodsReceiptId ?? null;
  useEffect(() => {
    if (openReceiptId !== null && props.currentSection === 'receipts') {
      setActivePanel('receipt-detail');
    }
  }, [openReceiptId, props.currentSection]);

  function navigateToSection(
    section: string,
    params?: Record<string, string | undefined>,
    only?: string[],
  ): void {
    if (props.onSectionChange) {
      props.onSectionChange(section, undefined, params);

      return;
    }
    setActiveSection(section);
    const hasParams = params && Object.values(params).some((val) => Boolean(val));
    if (hasParams && typeof router?.get === 'function') {
      router.get(
        routes.workspaces.inventory,
        {
          section: section === 'overview' ? undefined : section,
          ...params,
        },
        {
          preserveScroll: true,
          preserveState: true,
          only: only ?? [
            'balances',
            'locationItems',
            'locationItemPagination',
            'selectedItemId',
            'selectedLocationId',
          ],
        },
      );
    }
  }

  function handleOpenPanel(panel: InventoryRightPanelType, context?: { itemId?: string; locationId?: string }): void {
    if (context) {
      setPanelContext({
        itemId: context.itemId ?? panelContext.itemId,
        locationId: context.locationId ?? panelContext.locationId,
      });
    }
    setActivePanel(panel);
  }

  function handleClosePanel(): void {
    setActivePanel(null);
  }

  function handleSelectStock(itemId: string, locationId: string): void {
    navigateToSection('stock', {
      item_id: itemId || undefined,
      location_id: locationId || undefined,
    });
  }

  function handleLocationStockQueryChange(query: LocationItemListQuery): void {
    router.get(
      routes.workspaces.inventory,
      {
        section: 'stock',
        item_id: undefined,
        location_id: props.selectedLocationId ?? undefined,
        page: query.page > 1 ? query.page : undefined,
        per_page: query.perPage,
        sort_by: query.sortBy,
        sort_direction: query.sortDirection,
        q: query.search || undefined,
      },
      {
        preserveScroll: true,
        preserveState: true,
        only: [
          'locationItems',
          'locationItemPagination',
          'selectedItemId',
          'selectedLocationId',
          'balances',
        ],
      },
    );
  }

  function handleFacilityStockQueryChange(query: FacilityStockQuery): void {
    router.get(
      routes.workspaces.inventory,
      {
        section: 'stock-on-hand',
        page: query.page > 1 ? query.page : undefined,
        per_page: query.perPage,
        sort_by: query.sortBy,
        sort_direction: query.sortDirection,
        q: query.search || undefined,
        location_id: query.locationId ?? undefined,
      },
      {
        preserveScroll: true,
        preserveState: true,
        only: ['facilityStock', 'facilityStockPagination'],
      },
    );
  }

  function handleInspectItem(itemId: string): void {
    navigateToSection('stock', {
      item_id: itemId,
      location_id: props.selectedLocationId || props.locations.find((location) => location.active)?.id || props.locations[0]?.id || undefined,
    });
  }

  function handleInspectLocation(locationId: string): void {
    navigateToSection('stock', {
      item_id: props.selectedItemId || props.items.find((item) => item.active)?.id || props.items[0]?.id || undefined,
      location_id: locationId,
    });
  }

  const batchNumbers = useMemo(() => {
    const map = new Map<string, string>();
    for (const balance of props.balances) {
      if (balance.batchId && balance.batchNumber) {
        map.set(balance.batchId, balance.batchNumber);
      }
    }
    return map;
  }, [props.balances]);

  function handleViewLedger(itemId: string, locationId: string): void {
    router.get(
      routes.workspaces.inventory,
      {
        section: 'stock',
        item_id: itemId || undefined,
        location_id: locationId || undefined,
        view: 'ledger',
      },
      {
        preserveScroll: true,
        preserveState: true,
        only: ['ledger', 'selectedItemId', 'selectedLocationId', 'balances'],
      },
    );
  }

  function handleCloseLedger(): void {
    router.get(
      routes.workspaces.inventory,
      {
        section: 'stock',
        item_id: props.selectedItemId ?? undefined,
        location_id: props.selectedLocationId ?? undefined,
      },
      {
        preserveScroll: true,
        preserveState: true,
        only: ['ledger'],
      },
    );
  }

  function handleAdjustmentStatusChange(status: string | null): void {
    router.get(
      routes.workspaces.inventory,
      {
        section: 'adjustments',
        adjustment_status: status ?? undefined,
      },
      {
        preserveScroll: true,
        preserveState: true,
        only: ['adjustments', 'adjustmentStatusFilter'],
      },
    );
  }

  function handleApproveAdjustment(adjustmentId: string): void {
    router.post(
      routes.workspaces.inventoryAdjustmentApprove(adjustmentId),
      { idempotency_key: crypto.randomUUID() },
      { preserveScroll: true },
    );
  }

  function handleRejectAdjustment(adjustmentId: string, reason: string): void {
    router.post(
      routes.workspaces.inventoryAdjustmentReject(adjustmentId),
      { reason, idempotency_key: crypto.randomUUID() },
      { preserveScroll: true },
    );
  }

  function handleReservationStatusChange(status: string | null): void {
    router.get(
      routes.workspaces.inventory,
      {
        section: 'reservations',
        reservation_status: status ?? undefined,
      },
      {
        preserveScroll: true,
        preserveState: true,
        only: ['reservations', 'reservationStatusFilter'],
      },
    );
  }

  /**
   * The move-stock panel needs the stock at the chosen source location. That is
   * a partial reload of this same page — the rest of the screen (and the
   * panel's own form state) stays as it is.
   */
  function handleTransferSourceChange(locationId: string): void {
    router.get(
      routes.workspaces.inventory,
      {
        section: 'transfers',
        transfer_from: locationId || undefined,
      },
      {
        preserveScroll: true,
        preserveState: true,
        only: ['transferSource', 'transferFromLocationId'],
      },
    );
  }

  /**
   * The count sheet is loaded only for the count that was chosen, as a partial
   * reload of this same page. The panel keeps its own form state.
   */
  function handleOpenStockCount(countId: string): void {
    setActivePanel('stock-count-sheet');

    if (typeof router?.get === 'function') {
      router.get(
        routes.workspaces.inventory,
        { section: 'counts', count_id: countId },
        {
          preserveScroll: true,
          preserveState: true,
          only: ['stockCountSheet'],
        },
      );
    }
  }

  function handleOpenReceipt(goodsReceiptId: string): void {
    setActivePanel('receipt-detail');
    navigateToSection('receipts', { receipt_id: goodsReceiptId }, ['goodsReceiptDetail']);
  }

  function handleCloseReceipt(): void {
    setActivePanel(null);
    navigateToSection('receipts', undefined, ['goodsReceiptDetail']);
  }

  const ledgerItem = props.items.find((item) => item.id === props.selectedItemId) ?? null;
  const ledgerLocation = props.locations.find((location) => location.id === props.selectedLocationId) ?? null;

  return (
    <div className="afyanova-inventory-workspace" style={{ width: '100%' }}>
      {props.status ? (
        <InlineNotification kind="success" title="Success" subtitle={props.status} hideCloseButton />
      ) : null}
      {Object.values(props.errors).flat().map((error) => (
        <InlineNotification key={error} kind="error" title="Unable to complete request" subtitle={error} hideCloseButton />
      ))}

      <div className="afyanova-inventory-canvas" style={{ width: '100%', minWidth: 0, marginBlockStart: '1rem' }}>

        {/* ── Section: Overview ───────────────────────────────────────────── */}
        <div
          id="inventory-section-overview"
          role="region"
          aria-label="Overview"
          style={{ display: activeSection === 'overview' ? 'block' : 'none' }}
        >
          {activeSection === 'overview' ? (
            <InventoryOverviewCards
              items={props.items.filter((item) => item.active)}
              locations={props.locations.filter((location) => location.active)}
              canReceive={props.canReceive}
              canManageItems={props.canManageItems}
              canManageLocations={props.canManageLocations}
              isGeneral={props.contextMode === 'general'}
              onNavigateSection={navigateToSection}
              onOpenPanel={(panel) => handleOpenPanel(panel)}
            />
          ) : null}
        </div>

        {/* ── Section: Stock lookup ───────────────────────────────────────── */}
        <div
          id="inventory-section-stock"
          role="region"
          aria-label="Stock lookup"
          style={{ display: activeSection === 'stock' ? 'block' : 'none' }}
        >
          {activeSection !== 'stock' ? null : props.contextMode === 'general' ? (
            <InlineNotification kind="info" title="Select a facility" subtitle="Stock lookup is facility-specific. Choose a facility in the header to inspect its stock." hideCloseButton />
          ) : (
            <StockLookupSection
              items={props.items}
              locations={props.locations}
              selectedItemId={props.selectedItemId}
              selectedLocationId={props.selectedLocationId}
              departmentScoped={props.departmentScoped}
              departmentName={props.departmentName}
              balances={props.balances}
              locationItems={props.locationItems}
              locationItemPagination={props.locationItemPagination}
              onSelectStock={handleSelectStock}
              onLocationStockQueryChange={handleLocationStockQueryChange}
              onViewLedger={handleViewLedger}
            />
          )}
        </div>

        {/* ── Section: Facility stock on hand ─────────────────────────────── */}
        <div
          id="inventory-section-stock-on-hand"
          role="region"
          aria-label="Stock on hand"
          style={{ display: activeSection === 'stock-on-hand' ? 'block' : 'none' }}
        >
          {activeSection !== 'stock-on-hand' ? null : props.contextMode === 'general' ? (
            <InlineNotification kind="info" title="Select a facility" subtitle="This stock-on-hand view is facility-specific. Choose a facility in the header." hideCloseButton />
          ) : (
            <FacilityStockSection
              rows={props.facilityStock}
              pagination={props.facilityStockPagination}
              locations={props.locations}
              onQueryChange={handleFacilityStockQueryChange}
            />
          )}
        </div>

        {/* ── Section: Goods receipts ─────────────────────────────────────── */}
        <div
          id="inventory-section-receipts"
          role="region"
          aria-label="Goods receipts"
          style={{ display: activeSection === 'receipts' ? 'block' : 'none' }}
        >
          {activeSection === 'receipts' ? (
            <ReceiptsSection
              receipts={props.goodsReceipts ?? []}
              pagination={props.goodsReceiptPagination ?? {
                currentPage: 1, lastPage: 1, perPage: 25, total: 0, search: '',
              }}
              canReceive={props.canReceive}
              onReceiveDelivery={() => handleOpenPanel('goods-receipt')}
              onInspectReceipt={handleOpenReceipt}
            />
          ) : null}
        </div>

        {/* ── Section: Expiry ─────────────────────────────────────────────── */}
        <div
          id="inventory-section-expiry"
          role="region"
          aria-label="Expiry"
          style={{ display: activeSection === 'expiry' ? 'block' : 'none' }}
        >
          {activeSection !== 'expiry' ? null : props.contextMode === 'general' ? (
            <InlineNotification kind="info" title="Select a facility" subtitle="Expiry is facility-specific. Choose a facility in the header to see what is expiring there." hideCloseButton />
          ) : (
            <ExpirySection
              rows={props.expiringStock ?? []}
              windowDays={props.expiryWindowDays ?? 90}
              locations={props.locations}
              adjustments={props.adjustments ?? []}
              canRequestAdjustment={props.canRequestAdjustment}
            />
          )}
        </div>

        {/* ── Section: Running low ────────────────────────────────────────── */}
        <div
          id="inventory-section-low-stock"
          role="region"
          aria-label="Running low"
          style={{ display: activeSection === 'low-stock' ? 'block' : 'none' }}
        >
          {activeSection !== 'low-stock' ? null : props.contextMode === 'general' ? (
            <InlineNotification kind="info" title="Select a facility" subtitle="Minimum levels are set per facility. Choose a facility in the header to see what is running low there." hideCloseButton />
          ) : (
            <LowStockSection
              rows={props.lowStock ?? []}
              items={props.items}
              levels={props.itemLevels ?? []}
              canManageLevels={props.canManageLevels}
            />
          )}
        </div>

        {/* ── Section: Transfers ──────────────────────────────────────────── */}
        <div
          id="inventory-section-transfers"
          role="region"
          aria-label="Transfers"
          style={{ display: activeSection === 'transfers' ? 'block' : 'none' }}
        >
          {activeSection !== 'transfers' ? null : props.contextMode === 'general' ? (
            <InlineNotification kind="info" title="Select a facility" subtitle="A transfer moves stock inside one facility. Choose a facility in the header to move its stock." hideCloseButton />
          ) : (
            <TransfersSection
              transfers={props.transfers ?? []}
              canTransfer={Boolean(props.canTransfer)}
              onMoveStock={() => handleOpenPanel('move-stock')}
            />
          )}
        </div>

        {/* ── Section: Stock counts ───────────────────────────────────────── */}
        <div
          id="inventory-section-counts"
          role="region"
          aria-label="Stock counts"
          style={{ display: activeSection === 'counts' ? 'block' : 'none' }}
        >
          {activeSection !== 'counts' ? null : props.contextMode === 'general' ? (
            <InlineNotification kind="info" title="Select a facility" subtitle="A stock count belongs to one facility location. Choose a facility in the header to count its stock." hideCloseButton />
          ) : (
            <StockCountsSection
              counts={props.stockCounts ?? []}
              canManageCounts={Boolean(props.canManageCounts)}
              onStartCount={() => handleOpenPanel('start-stock-count')}
              onOpenSheet={handleOpenStockCount}
            />
          )}
        </div>

        {/* ── Section: Stock adjustments ──────────────────────────────────── */}
        <div
          id="inventory-section-adjustments"
          role="region"
          aria-label="Adjustments"
          style={{ display: activeSection === 'adjustments' ? 'block' : 'none' }}
        >
          {activeSection !== 'adjustments' ? null : props.contextMode === 'general' ? (
            <InlineNotification kind="info" title="Select a facility" subtitle="Stock adjustments belong to a facility. Choose a facility in the header." hideCloseButton />
          ) : (
            <AdjustmentsSection
              adjustments={props.adjustments ?? []}
              statusFilter={props.adjustmentStatusFilter ?? null}
              items={props.items}
              locations={props.locations}
              canRequestAdjustment={props.canRequestAdjustment}
              canApproveAdjustments={props.canApproveAdjustments}
              currentUserId={props.currentUserId}
              onStatusFilterChange={handleAdjustmentStatusChange}
              onRequestAdjustment={() => handleOpenPanel('request-adjustment')}
              onApproveAdjustment={handleApproveAdjustment}
              onRejectAdjustment={handleRejectAdjustment}
            />
          )}
        </div>

        {/* ── Section: Reservations ───────────────────────────────────────── */}
        <div
          id="inventory-section-reservations"
          role="region"
          aria-label="Reservations"
          style={{ display: activeSection === 'reservations' ? 'block' : 'none' }}
        >
          {activeSection !== 'reservations' ? null : props.contextMode === 'general' ? (
            <InlineNotification kind="info" title="Select a facility" subtitle="Reservations are facility-specific. Choose a facility in the header." hideCloseButton />
          ) : (
            <ReservationsSection
              reservations={props.reservations ?? []}
              statusFilter={props.reservationStatusFilter ?? null}
              items={props.items}
              locations={props.locations}
              onStatusFilterChange={handleReservationStatusChange}
            />
          )}
        </div>

        {/* ── Section: Item catalogue ─────────────────────────────────────── */}
        <div
          id="inventory-section-catalogue"
          role="region"
          aria-label="Item catalogue"
          style={{ display: activeSection === 'catalogue' ? 'block' : 'none' }}
        >
          {activeSection === 'catalogue' ? (
            <InventoryItemsTable
              items={props.items}
              canManageItems={props.canManageItems}
              onAddItem={() => handleOpenPanel('add-item')}
              onInspectStock={handleInspectItem}
            />
          ) : null}
        </div>

        {/* ── Section: Storage Locations ──────────────────────────────────── */}
        <div
          id="inventory-section-locations"
          role="region"
          aria-label="Locations & types"
          style={{ display: activeSection === 'locations' ? 'block' : 'none' }}
        >
          {activeSection === 'locations' ? (
            <Tabs>
              <ContainedTabList aria-label="Location management views">
                <IconTab icon={Location}>Locations</IconTab>
                <IconTab icon={Building}>Location Types</IconTab>
              </ContainedTabList>
              <TabPanels>
                <TabPanel>
                  <>
                    {props.contextMode === 'general' ? (
                      <InlineNotification kind="info" title="General context" subtitle="Custom types created here are shared across the tenant. Select a facility to inspect or create its locations." hideCloseButton />
                    ) : null}
                    <InventoryLocationsTable
                      locations={props.locations}
                      locationTypes={props.locationTypes}
                      canManageLocations={props.canManageLocations}
                      canManageLocationTypes={props.canManageLocationTypes}
                      onAddLocation={() => handleOpenPanel('add-location')}
                      onAddLocationType={() => handleOpenPanel('add-location-type')}
                      onInspectLocation={handleInspectLocation}
                    />
                  </>
                </TabPanel>
                <TabPanel>
                  <InventoryLocationTypesTable
                    locationTypes={props.locationTypes}
                    canManageLocationTypes={props.canManageLocationTypes}
                    onAddLocationType={() => handleOpenPanel('add-location-type')}
                  />
                </TabPanel>
              </TabPanels>
            </Tabs>
          ) : null}
        </div>

      </div>

      {/* Right Workspace Panels */}
      {props.canManageItems ? (
        activePanel === 'add-item' ? (
          <AddItemPanel
            open
            onClose={handleClosePanel}
            itemTypes={props.itemTypes}
            itemCategories={props.itemCategories}
            unitsOfMeasure={props.unitsOfMeasure}
            existingItemNames={props.items.filter((item) => item.active).map((item) => item.name)}
          />
        ) : null
      ) : null}

      {props.canManageLocations ? (
        activePanel === 'add-location' ? (
          <AddLocationPanel
            open
            onClose={handleClosePanel}
            locationTypes={props.locationTypes}
            canManageLocationTypes={props.canManageLocationTypes}
            onOpenAddType={() => handleOpenPanel('add-location-type')}
          />
        ) : null
      ) : null}

      {props.canManageLocationTypes ? (
        activePanel === 'add-location-type' ? (
          <AddLocationTypePanel
            open
            onClose={handleClosePanel}
            isGeneral={props.contextMode === 'general'}
          />
        ) : null
      ) : null}

      {props.canReceive ? (
        activePanel === 'goods-receipt' ? (
          <GoodsReceiptWorkbenchPanel
            open
            onClose={handleClosePanel}
            items={props.items.filter((item) => item.active)}
            locations={props.locations.filter((location) => location.active)}
            canSearchPurchaseOrders={props.canReceiveAgainstPurchaseOrders ?? false}
            initialLocationId={panelContext.locationId}
          />
        ) : null
      ) : null}

      {activePanel === 'receipt-detail' && props.goodsReceiptDetail ? (
        <ReceiptDetailPanel
          receipt={props.goodsReceiptDetail}
          locations={props.locations}
          canManageInvoice={props.canReceive}
          canMatchInvoice={props.canMatchInvoices}
          canManageReturns={props.canReceive}
          onClose={handleCloseReceipt}
        />
      ) : null}

      {props.canRequestAdjustment ? (
        activePanel === 'request-adjustment' ? (
          <RequestAdjustmentPanel
            open
            onClose={handleClosePanel}
            items={props.items.filter((item) => item.active)}
            locations={props.locations.filter((location) => location.active)}
            initialItemId={panelContext.itemId}
            initialLocationId={panelContext.locationId}
          />
        ) : null
      ) : null}

      {props.canTransfer ? (
        activePanel === 'move-stock' ? (
          <MoveStockPanel
            open
            onClose={handleClosePanel}
            locations={props.locations.filter((location) => location.active)}
            items={props.items.filter((item) => item.active)}
            source={props.transferSource ?? []}
            fromLocationId={props.transferFromLocationId ?? null}
            onSourceChange={handleTransferSourceChange}
          />
        ) : null
      ) : null}

      {props.canManageCounts ? (
        activePanel === 'start-stock-count' ? (
          <StartStockCountPanel
            open
            onClose={handleClosePanel}
            locations={props.locations.filter((location) => location.active)}
          />
        ) : null
      ) : null}

      {activePanel === 'stock-count-sheet' ? (
        <StockCountSheetPanel
          open
          onClose={handleClosePanel}
          sheet={props.stockCountSheet ?? null}
          items={props.items.filter((item) => item.active)}
          canManage={Boolean(props.canManageCounts)}
        />
      ) : null}

      {props.ledger ? (
        <StockLedgerPanel
          ledger={props.ledger}
          item={ledgerItem}
          location={ledgerLocation}
          batchNumbers={batchNumbers}
          onClose={handleCloseLedger}
        />
      ) : null}
    </div>
  );
}

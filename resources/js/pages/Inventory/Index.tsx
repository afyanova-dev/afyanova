import { router, usePage } from '@inertiajs/react';
import { useCallback, useEffect, useState, type ReactNode } from 'react';
import { routes } from '@/app/routes';
import { InventoryWorkspace, type InventoryWorkspaceProps } from '@/features/inventory';
import { AppLayout } from '@/layouts/AppLayout';
import { inventorySectionFromUrl } from '@/layouts/AppLayout/navigation';

export default function InventoryIndex(props: InventoryWorkspaceProps): ReactNode {
  const { url } = usePage();
  const [activeSection, setActiveSection] = useState<string>(() => inventorySectionFromUrl(url));

  useEffect(() => {
    setActiveSection(inventorySectionFromUrl(url));
  }, [url]);

  useEffect(() => {
    function handlePopState(): void {
      setActiveSection(inventorySectionFromUrl(window.location.href));
    }
    window.addEventListener('popstate', handlePopState);
    return () => window.removeEventListener('popstate', handlePopState);
  }, []);

  const handleSectionChange = useCallback((
    section: string,
    href?: string,
    params: Record<string, string | undefined> = {},
  ): void => {
    const targetUrl = href
      ?? (section === 'overview'
        ? routes.workspaces.inventory
        : `${routes.workspaces.inventory}?section=${section}`);

    router.get(targetUrl, params, {
      preserveScroll: true,
      preserveState: true,
      only: sectionProps[section] ?? [],
      onSuccess: () => setActiveSection(section),
    });
  }, []);

  return (
    <AppLayout
      activeSidebarItemId={`inventory-${activeSection}`}
      onSidebarItemClick={(item) => {
        const section = item.id.replace('inventory-', '');
        handleSectionChange(section, item.href);
      }}
    >
      <InventoryWorkspace
        {...props}
        currentSection={activeSection}
        onSectionChange={handleSectionChange}
      />
    </AppLayout>
  );
}

const sectionProps: Record<string, string[]> = {
  overview: ['items', 'locations'],
  stock: [
    'items', 'locations', 'balances', 'locationItems', 'locationItemPagination',
    'selectedItemId', 'selectedLocationId', 'ledger',
  ],
  'stock-on-hand': ['locations', 'facilityStock', 'facilityStockPagination'],
  receipts: ['goodsReceipts', 'goodsReceiptPagination', 'goodsReceiptDetail'],
  expiry: ['items', 'locations', 'expiringStock', 'adjustments', 'adjustmentStatusFilter'],
  'low-stock': ['items', 'lowStock', 'itemLevels'],
  transfers: ['items', 'locations', 'transfers', 'transferSource', 'transferFromLocationId'],
  counts: ['items', 'locations', 'stockCounts', 'stockCountSheet'],
  adjustments: ['items', 'locations', 'adjustments', 'adjustmentStatusFilter'],
  reservations: ['items', 'locations', 'reservations', 'reservationStatusFilter'],
  catalogue: ['items'],
  locations: ['locations', 'locationTypes'],
};

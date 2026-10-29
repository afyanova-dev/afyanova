import { Button, TableToolbarSearch } from '@carbon/react';
import { router } from '@inertiajs/react';
import { useEffect, useMemo, useState, type ReactNode } from 'react';
import { routes } from '@/app/routes';
import { CarbonDataTable, EmptyState, StatisticsCards } from '@/components/ui';
import { Document, List, Report } from '@/icons';
import type { GoodsReceiptPagination, GoodsReceiptSummary } from '../types';

export interface ReceiptsSectionProps {
  receipts: GoodsReceiptSummary[];
  pagination: GoodsReceiptPagination;
  canReceive: boolean;
  onReceiveDelivery: () => void;
  onInspectReceipt: (goodsReceiptId: string) => void;
}

const HEADERS = [
  { key: 'receipt', header: 'GRN' },
  { key: 'received', header: 'Received' },
  { key: 'source', header: 'Source' },
  { key: 'po', header: 'Purchase order' },
  { key: 'location', header: 'Destination' },
  { key: 'lines', header: 'Lines' },
  { key: 'invoice', header: 'Invoice' },
  { key: 'actions', header: 'Actions' },
];

function formatWhen(iso: string): string {
  const date = new Date(iso);

  return Number.isNaN(date.getTime()) ? iso : date.toLocaleString();
}

function sourceLabel(source: string): string {
  return source.replaceAll('_', ' ').toLowerCase().replace(/^./, (character) => character.toUpperCase());
}

function formatInvoice(receipt: GoodsReceiptSummary): string {
  if (receipt.invoiceNumber !== null) {
    return receipt.invoiceNumber;
  }

  return receipt.invoiceStatus === 'NOT_REQUIRED' ? 'Not required' : 'Pending';
}

/**
 * The goods-receipt register (Phase 1 / P1-08).
 *
 * One row per GRN. Search is server-side so a long receipt history stays fast;
 * the detail panel and the PDF are the same stored document.
 */
export function ReceiptsSection({
  receipts,
  pagination,
  canReceive,
  onReceiveDelivery,
  onInspectReceipt,
}: ReceiptsSectionProps): ReactNode {
  const [search, setSearch] = useState(pagination.search);

  useEffect(() => {
    setSearch(pagination.search);
  }, [pagination.search]);

  useEffect(() => {
    if (search === pagination.search) {
      return;
    }

    const timer = window.setTimeout(() => {
      router.get(
        routes.workspaces.inventory,
        { section: 'receipts', q: search, page: 1 },
        {
          preserveScroll: true,
          preserveState: true,
          only: ['goodsReceipts', 'goodsReceiptPagination'],
        },
      );
    }, 300);

    return () => window.clearTimeout(timer);
  }, [search, pagination.search]);

  const rows = useMemo(() => receipts.map((receipt) => ({
    id: receipt.goodsReceiptId,
    receipt: receipt.receiptNumber,
    received: formatWhen(receipt.receivedAt),
    source: sourceLabel(receipt.sourceType),
    po: receipt.poRef ?? '—',
    location: receipt.locationCode !== '' ? `${receipt.locationCode} — ${receipt.locationName}` : '—',
    lines: `${receipt.lineCount} line${receipt.lineCount === 1 ? '' : 's'}${receipt.rejectedLineCount > 0 ? ` · ${receipt.rejectedLineCount} with rejects` : ''}`,
    invoice: formatInvoice(receipt),
    actions: '',
  })), [receipts]);

  const rejectedLines = receipts.reduce((total, receipt) => total + receipt.rejectedLineCount, 0);

  function goToPage(page: number): void {
    router.get(
      routes.workspaces.inventory,
      { section: 'receipts', q: pagination.search, page },
      {
        preserveScroll: true,
        preserveState: true,
        only: ['goodsReceipts', 'goodsReceiptPagination'],
      },
    );
  }

  return (
    <div className="afy-section">
      <div className="afy-section__heading">
        <div>
          <h2 id="inventory-receipts-heading" className="afy-section__title">Goods receipts</h2>
          <p className="afy-section__description">
            Every delivery posted into this facility. A GRN is the stored operational document:
            accepted quantity moved stock; rejected quantity is recorded here and never posted.
          </p>
        </div>
        {canReceive ? (
          <Button kind="tertiary" size="md" onClick={onReceiveDelivery}>
            Receive delivery
          </Button>
        ) : null}
      </div>

      <StatisticsCards
        label="Goods-receipt summary"
        cards={[
          {
            id: 'receipt-count',
            label: 'GRNs on record',
            value: pagination.total,
            detail: 'One delivery, one GRN',
            borderTone: 'information',
            icon: <Document aria-hidden="true" />,
          },
          {
            id: 'receipt-page',
            label: 'On this page',
            value: receipts.length,
            detail: pagination.search === '' ? 'Most recently received first' : `Matching “${pagination.search}”`,
            borderTone: 'information',
            icon: <List aria-hidden="true" />,
          },
          {
            id: 'receipt-rejects',
            label: 'Lines with rejects',
            value: rejectedLines,
            detail: 'Rejected quantity did not post to stock',
            borderTone: rejectedLines > 0 ? 'caution' : 'information',
            icon: <Report aria-hidden="true" />,
          },
        ]}
      />

      <CarbonDataTable
        rows={rows}
        headers={HEADERS}
        title={`Goods receipts (${pagination.total})`}
        ariaLabel="Goods receipts"
        emptyState={
          pagination.search !== '' ? (
            <EmptyState title="No matching GRNs" description="No goods receipt matches this search." />
          ) : (
            <EmptyState
              title="No goods receipts yet"
              description="Once a delivery is posted, its GRN appears here with a printable document."
            />
          )
        }
        toolbar={(onInputChange) => (
          <TableToolbarSearch
            persistent
            value={search}
            onChange={(event) => {
              onInputChange(event);
              setSearch(typeof event === 'string' ? event : event.target.value);
            }}
            placeholder="Search GRN, PO, delivery note or invoice"
            aria-label="Search goods receipts"
          />
        )}
        renderCell={(cell, row) => {
          if (cell.info.header === 'actions') {
            return (
              <div style={{ display: 'flex', gap: '0.25rem', flexWrap: 'wrap' }}>
                <Button kind="ghost" size="sm" onClick={() => onInspectReceipt(row.id)}>
                  Open
                </Button>
                <a
                  className="cds--btn cds--btn--ghost cds--btn--sm"
                  href={routes.workspaces.inventoryGoodsReceiptPrint(row.id)}
                  target="_blank"
                  rel="noreferrer"
                >
                  Print
                </a>
              </div>
            );
          }

          return cell.value;
        }}
      />

      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: '0.75rem', flexWrap: 'wrap' }}>
        <span style={{ fontSize: '0.875rem' }}>
          Page {pagination.currentPage} of {pagination.lastPage} · {pagination.total} receipt{pagination.total === 1 ? '' : 's'}
        </span>
        <div style={{ display: 'flex', gap: '0.5rem' }}>
          <Button
            kind="tertiary"
            size="sm"
            disabled={pagination.currentPage <= 1}
            onClick={() => goToPage(pagination.currentPage - 1)}
          >
            Previous
          </Button>
          <Button
            kind="tertiary"
            size="sm"
            disabled={pagination.currentPage >= pagination.lastPage}
            onClick={() => goToPage(pagination.currentPage + 1)}
          >
            Next
          </Button>
        </div>
      </div>
    </div>
  );
}

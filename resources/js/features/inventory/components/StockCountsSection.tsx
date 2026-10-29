import { Button, TableToolbarSearch, Tag } from '@carbon/react';
import { useMemo, useState, type ReactNode } from 'react';
import { CarbonDataTable, EmptyState, StatisticsCards } from '@/components/ui';
import { List, Report, WarningAlt } from '@/icons';
import type { StockCount } from '../types';

export interface StockCountsSectionProps {
  counts: StockCount[];
  canManageCounts: boolean;
  onStartCount: () => void;
  onOpenSheet: (countId: string) => void;
}

const STATUS_LABELS: Record<string, string> = {
  IN_PROGRESS: 'In progress',
  COMPLETED: 'Completed',
  DISCARDED: 'Discarded',
};

const STATUS_TAG: Record<string, 'blue' | 'green' | 'red' | 'cool-gray'> = {
  IN_PROGRESS: 'blue',
  COMPLETED: 'green',
  DISCARDED: 'cool-gray',
};

function formatWhen(iso: string): string {
  const date = new Date(iso);

  if (Number.isNaN(date.getTime())) {
    return iso;
  }

  return date.toLocaleString();
}

/**
 * Stock counts (frontend-foundation-inventory-operations.md Phase 8).
 *
 * A count records what is physically on the shelf at ONE location and compares
 * it with what the system believes. It never edits a balance: the differences
 * it shows are put right afterwards through the two-person stock adjustment, so
 * the person who counts is never the person who silently corrects the stock.
 */
export function StockCountsSection({
  counts,
  canManageCounts,
  onStartCount,
  onOpenSheet,
}: StockCountsSectionProps): ReactNode {
  const [search, setSearch] = useState('');

  const filtered = useMemo(() => {
    const term = search.trim().toLowerCase();

    if (term === '') {
      return counts;
    }

    return counts.filter((count) => [
      count.countNumber,
      count.locationCode,
      count.locationName,
      STATUS_LABELS[count.status] ?? count.status,
      count.notes ?? '',
    ].some((value) => value.toLowerCase().includes(term)));
  }, [counts, search]);

  const rows = filtered.map((count) => ({
    id: count.countId,
    count: `${count.countNumber} — ${formatWhen(count.startedAt)}`,
    location: `${count.locationCode} — ${count.locationName}`,
    status: count.status,
    progress: `${count.countedCount} of ${count.lineCount} lines counted`,
    differences: String(count.varianceCount),
    actions: count.countId,
  }));

  const open = counts.filter((count) => count.status === 'IN_PROGRESS').length;
  const differing = counts.reduce((total, count) => total + count.varianceCount, 0);

  return (
    <div className="afy-section">
      <div className="afy-section__heading">
        <div>
          <h2 id="inventory-counts-heading" className="afy-section__title">Stock counts</h2>
          <p className="afy-section__description">
            Count what is physically on the shelf at one location and record the differences against
            the system. A count never changes stock: a difference is corrected afterwards through a
            stock adjustment, which a second person approves.
          </p>
        </div>
        {canManageCounts ? (
          <Button kind="tertiary" size="md" onClick={onStartCount}>
            Start a count
          </Button>
        ) : null}
      </div>

      <StatisticsCards
        label="Stock count summary"
        cards={[
          {
            id: 'counts-recorded',
            label: 'Counts recorded',
            value: counts.length,
            detail: 'Physical counts at this facility',
            borderTone: 'information',
            icon: <List aria-hidden="true" />,
          },
          {
            id: 'counts-open',
            label: 'Open now',
            value: open,
            detail: 'Counts still being walked',
            borderTone: 'information',
            icon: <Report aria-hidden="true" />,
          },
          {
            id: 'counts-differences',
            label: 'Lines with differences',
            value: differing,
            detail: 'Counted lines that disagree with the system',
            borderTone: 'caution',
            icon: <WarningAlt aria-hidden="true" />,
          },
        ]}
      />

      <CarbonDataTable
        rows={rows}
        headers={[
          { key: 'count', header: 'Count' },
          { key: 'location', header: 'Location' },
          { key: 'status', header: 'Status' },
          { key: 'progress', header: 'Progress' },
          { key: 'differences', header: 'Differences' },
          { key: 'actions', header: 'Actions' },
        ]}
        title={`Stock counts (${filtered.length} of ${counts.length})`}
        ariaLabel="Stock counts"
        emptyState={
          counts.length > 0 ? (
            <EmptyState title="No matches" description="No stock count matches this search." />
          ) : (
            <EmptyState
              title="No stock counts yet"
              description="No physical count has been taken at this facility. Use Start a count to walk a location's shelf."
            />
          )
        }
        toolbar={(onInputChange) => (
          <TableToolbarSearch
            persistent
            onChange={(event) => {
              onInputChange(event);
              setSearch(typeof event === 'string' ? event : event.target.value);
            }}
            placeholder="Search counts"
            aria-label="Search stock counts"
          />
        )}
        renderCell={(cell) => {
          if (cell.info.header === 'status') {
            const status = String(cell.value);
            return (
              <Tag type={STATUS_TAG[status] ?? 'cool-gray'} size="sm">
                {STATUS_LABELS[status] ?? status}
              </Tag>
            );
          }

          if (cell.info.header === 'actions') {
            const count = counts.find((candidate) => candidate.countId === String(cell.value));
            return (
              <Button
                kind="ghost"
                size="sm"
                onClick={() => onOpenSheet(String(cell.value))}
              >
                {count?.status === 'IN_PROGRESS' ? 'Continue count' : 'View sheet'}
              </Button>
            );
          }

          return cell.value;
        }}
      />
    </div>
  );
}

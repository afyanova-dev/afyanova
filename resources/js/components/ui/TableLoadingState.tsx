import { DataTableSkeleton } from '@carbon/react';
import type { ReactNode } from 'react';

/**
 * Table-region loading state (Rule 07). Row-shaped skeleton for a table
 * that has no data yet — never used to replace rows that already exist
 * (refresh keeps stale rows visible with an inline pending indicator).
 */
export function TableLoadingState({
  label = 'Loading table rows',
  columnCount = 4,
  rowCount = 5,
}: {
  label?: string;
  columnCount?: number;
  rowCount?: number;
}): ReactNode {
  return (
    <div role="status" aria-label={label} aria-busy="true">
      <DataTableSkeleton columnCount={columnCount} rowCount={rowCount} showToolbar={false} />
    </div>
  );
}

import { TableToolbarSearch, Tag, type DataTableCell, type DataTableHeader } from '@carbon/react';
import type { ReactNode } from 'react';
import { CarbonDataTable, EmptyState } from '@/components/ui';
import { clinicalTagType, humanizeClinicalValue } from './patientChartData';

export type PatientSectionAccessState = 'restricted' | 'unavailable' | null;

export function PatientSectionState({ state }: { state: Exclude<PatientSectionAccessState, null> }): ReactNode {
  return (
    <div className="patient-chart__section-state" role={state === 'unavailable' ? 'alert' : 'status'}>
      <Tag type={state === 'restricted' ? 'red' : 'cool-gray'}>
        {state === 'restricted' ? 'Restricted for your role' : 'Section temporarily unavailable'}
      </Tag>
      <p>
        {state === 'restricted'
          ? 'Your role does not have permission to read this part of the chart.'
          : 'The source system did not return this section. Try again later or contact support.'}
      </p>
    </div>
  );
}

export interface PatientRecordRow {
  id: string;
  cells: string[];
}

interface PatientRecordTableProps {
  title: string;
  ariaLabel: string;
  headers: DataTableHeader[];
  rows: PatientRecordRow[];
  searchLabel: string;
  emptyTitle: string;
  emptyDescription: string;
  statusHeaders?: string[];
  headerActions?: ReactNode;
  renderCell?: (cell: DataTableCell<ReactNode>, rowId: string) => ReactNode;
}

/**
 * Patient-domain table shell. Rows stay string-based so Carbon's table search
 * works; status-like columns are rendered through the shared tag mapping.
 * This is not a global table abstraction — it exists only for chart sections.
 */
export function PatientRecordTable({
  title,
  ariaLabel,
  headers,
  rows,
  searchLabel,
  emptyTitle,
  emptyDescription,
  statusHeaders = [],
  headerActions,
  renderCell,
}: PatientRecordTableProps): ReactNode {
  const statusHeaderSet = new Set(statusHeaders);
  const tableRows = rows.map((row) => {
    const record: { id: string } & Record<string, string> = { id: row.id };

    headers.forEach((header, index) => {
      record[header.key] = row.cells[index] ?? '';
    });

    return record;
  });

  return (
    <CarbonDataTable
      rows={tableRows}
      headers={headers}
      title={`${title} (${rows.length})`}
      ariaLabel={ariaLabel}
      headerActions={headerActions}
      size="sm"
      className="patient-chart__record-table"
      emptyState={(
        <EmptyState
          title={emptyTitle}
          description={emptyDescription}
        />
      )}
      toolbar={(onInputChange) => (
        <TableToolbarSearch
          persistent
          onChange={onInputChange}
          placeholder={searchLabel}
          aria-label={searchLabel}
        />
      )}
      renderCell={(cell, row) => {
        if (statusHeaderSet.has(cell.info.header)) {
          return (
            <Tag type={clinicalTagType(String(cell.value))}>
              {humanizeClinicalValue(String(cell.value))}
            </Tag>
          );
        }

        return renderCell ? renderCell(cell, row.id) : cell.value;
      }}
    />
  );
}

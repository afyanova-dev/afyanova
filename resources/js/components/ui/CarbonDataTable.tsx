import {
  DataTable,
  Table,
  TableBody,
  TableCell,
  TableContainer,
  TableExpandHeader,
  TableExpandRow,
  TableExpandedRow,
  TableHead,
  TableHeader,
  TableRow,
  TableToolbar,
  TableToolbarContent,
  type DataTableCell as CarbonDataTableCell,
  type DataTableHeader,
  type DataTableProps,
  type DataTableRenderProps,
  type DataTableRow,
  type DataTableSortState,
  type DataTableSize,
} from '@carbon/react';
import { Fragment, useEffect, useState, type ReactNode } from 'react';

export type CarbonDataTableRow = DataTableProps<unknown, ReactNode[]>['rows'][number];

interface CarbonDataTableProps<Row extends CarbonDataTableRow> {
  rows: Row[];
  headers: DataTableHeader[];
  title: ReactNode;
  ariaLabel: string;
  emptyState: ReactNode;
  toolbar?: ReactNode | ((
    onInputChange: DataTableRenderProps<CarbonDataTableRow, ReactNode[]>['onInputChange'],
  ) => ReactNode);
  toolbarLabel?: string;
  className?: string;
  size?: DataTableSize;
  ariaBusy?: boolean;
  isSortable?: boolean;
  onSort?: (sortHeaderKey: string, sortDirection: DataTableSortState) => void;
  expandedRowId?: string | null;
  renderCell?: (
    cell: CarbonDataTableCell<ReactNode>,
    row: DataTableRow<ReactNode[]> & CarbonDataTableRow,
  ) => ReactNode;
  getExpandRowLabel?: (row: DataTableRow<ReactNode[]> & CarbonDataTableRow) => string;
  renderExpandedRow?: (
    row: DataTableRow<ReactNode[]> & CarbonDataTableRow,
  ) => ReactNode;
  headerClassName?: (header: DataTableHeader) => string | undefined;
  cellClassName?: (
    cell: CarbonDataTableCell<ReactNode>,
    row: DataTableRow<ReactNode[]> & CarbonDataTableRow,
  ) => string | undefined;
}

export function CarbonDataTable<Row extends CarbonDataTableRow>({
  rows,
  headers,
  title,
  ariaLabel,
  emptyState,
  toolbar,
  toolbarLabel,
  className,
  size = 'lg',
  ariaBusy,
  isSortable = false,
  onSort,
  expandedRowId,
  renderCell,
  getExpandRowLabel,
  renderExpandedRow,
  headerClassName,
  cellClassName,
}: CarbonDataTableProps<Row>): React.JSX.Element {
  const [expandedOverride, setExpandedOverride] = useState<{ rowId: string; expanded: boolean } | null>(null);

  useEffect(() => {
    setExpandedOverride(null);
  }, [expandedRowId]);

  return (
    <DataTable<CarbonDataTableRow, ReactNode[]>
      rows={rows}
      headers={headers}
      size={size}
      locale="en"
      overflowMenuOnHover
      isSortable={isSortable}
      sortRow={() => 0}
    >
      {({
        rows: tableRows,
        headers: tableHeaders,
        getHeaderProps,
        getRowProps,
        getTableProps,
        getExpandedRowProps,
        onInputChange,
      }) => (
        <TableContainer title={title} className={['afyanova-data-table', className].filter(Boolean).join(' ')}>
          {toolbar ? (
            <TableToolbar aria-label={toolbarLabel ?? `${ariaLabel} toolbar`}>
              <TableToolbarContent>
                {typeof toolbar === 'function' ? toolbar(onInputChange) : toolbar}
              </TableToolbarContent>
            </TableToolbar>
          ) : null}
          {tableRows.length === 0 ? emptyState : (
            <Table {...getTableProps()} aria-label={ariaLabel} aria-busy={ariaBusy || undefined} size={size}>
              <TableHead>
                <TableRow>
                  {renderExpandedRow ? <TableExpandHeader aria-label={`Expand ${ariaLabel}`} /> : null}
                  {tableHeaders.map((header) => {
                    const { key, ...props } = getHeaderProps({
                      header,
                      onClick: onSort
                        ? (_event, sortState) => onSort(sortState.sortHeaderKey, sortState.sortDirection)
                        : undefined,
                    });
                    const customClassName = headerClassName?.(header);
                    return (
                      <TableHeader
                        key={key}
                        {...props}
                        className={[props.className, customClassName].filter(Boolean).join(' ') || undefined}
                      >
                        {header.header}
                      </TableHeader>
                    );
                  })}
                </TableRow>
              </TableHead>
              <TableBody>
                {tableRows.map((row) => {
                  const { key, ...props } = getRowProps({ row });
                  if (renderExpandedRow) {
                    const { ...expandedProps } = getExpandedRowProps({ row });
                    const hasExpandedOverride = expandedOverride?.rowId === row.id;
                    const isExpanded = hasExpandedOverride
                      ? expandedOverride.expanded
                      : row.isExpanded || expandedRowId === row.id;
                    return (
                      <Fragment key={key}>
                        <TableExpandRow
                          {...props}
                          isExpanded={isExpanded}
                          onExpand={(event) => {
                            if (expandedRowId === row.id || hasExpandedOverride) {
                              setExpandedOverride({ rowId: row.id, expanded: !isExpanded });
                            } else {
                              props.onExpand(event);
                            }
                          }}
                          aria-label={`${isExpanded ? 'Collapse' : 'Expand'} ${getExpandRowLabel?.(row) ?? String(row.cells[0]?.value ?? ariaLabel)}`}
                        >
                          {row.cells.map((cell) => {
                            const customClassName = cellClassName?.(cell, row);
                            return (
                              <TableCell key={cell.id} className={customClassName}>
                                {renderCell ? renderCell(cell, row) : cell.value}
                              </TableCell>
                            );
                          })}
                        </TableExpandRow>
                        {isExpanded ? (
                          <TableExpandedRow
                            {...expandedProps}
                            key={row.id}
                            colSpan={tableHeaders.length + 1}
                          >
                            {renderExpandedRow(row)}
                          </TableExpandedRow>
                        ) : null}
                      </Fragment>
                    );
                  }
                  return (
                    <TableRow key={key} {...props}>
                      {row.cells.map((cell) => {
                        const customClassName = cellClassName?.(cell, row);
                        return (
                          <TableCell key={cell.id} className={customClassName}>
                            {renderCell ? renderCell(cell, row) : cell.value}
                          </TableCell>
                        );
                      })}
                    </TableRow>
                  );
                })}
              </TableBody>
            </Table>
          )}
        </TableContainer>
      )}
    </DataTable>
  );
}

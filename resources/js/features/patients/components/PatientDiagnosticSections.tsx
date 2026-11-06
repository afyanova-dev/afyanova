import type { ReactNode } from 'react';
import { formatClinicalDateTime } from '@/lib/formatting';
import type { PatientChartData, PatientChartImagingOrder } from '@/features/patients/types/chart';
import { isAbnormalFlag, referenceRange } from './patientChartData';
import { PatientRecordTable, PatientSectionState, type PatientSectionAccessState } from './PatientRecordTable';

function date(value: string | null | undefined): string {
  return formatClinicalDateTime(value) ?? '—';
}

const AWAITING_RESULT_STATUSES = new Set(['ORDERED', 'PENDING', 'IN_PROGRESS', 'COLLECTED']);

function displayResult(value: number | string | null, unit: string | null): string {
  if (value === null || value === '') return '—';

  return [value, unit].filter((part) => part !== null && part !== '').join(' ');
}

export function PatientLabResultsSection({
  chart,
  state,
}: {
  chart: PatientChartData;
  state: PatientSectionAccessState;
}): ReactNode {
  if (state) return <PatientSectionState state={state} />;

  const rows = chart.labOrders.flatMap((order) => {
    const results = chart.labResults[order.orderId] ?? [];
    const resultsLoaded = Object.prototype.hasOwnProperty.call(chart.labResults, order.orderId);

    if (results.length === 0) {
      return order.items.map((item, index) => {
        const resultCell = !resultsLoaded
          ? 'Results not loaded'
          : AWAITING_RESULT_STATUSES.has(item.status.toUpperCase())
            ? 'Pending'
            : 'No result reported';

        return {
          id: `${order.orderId}-pending-${index}`,
          cells: [
            date(order.createdAt),
            order.orderNumber,
            item.testName,
            resultCell,
            '—',
            '—',
            '—',
            item.status,
            order.priority,
          ],
        };
      });
    }

    return results.map((result, index) => ({
      id: result.id ?? `${order.orderId}-${index}`,
      cells: [
        date(result.releasedAt ?? order.createdAt),
        order.orderNumber,
        result.parameter,
        displayResult(result.value, null),
        result.unit ?? '—',
        referenceRange(result) ?? '—',
        result.flag ?? '—',
        result.status ?? order.status,
        order.priority,
      ],
    }));
  });
  const abnormalResultIds = new Set(
    chart.labOrders.flatMap((order) => (
      (chart.labResults[order.orderId] ?? [])
        .filter((result) => isAbnormalFlag(result.flag))
        .map((result, index) => result.id ?? `${order.orderId}-${index}`)
    )),
  );

  return (
    <PatientRecordTable
      title="Laboratory results"
      ariaLabel="Patient laboratory results"
      searchLabel="Search results"
      headers={[
        { key: 'date', header: 'Date' },
        { key: 'order', header: 'Order' },
        { key: 'test', header: 'Test' },
        { key: 'result', header: 'Result' },
        { key: 'unit', header: 'Unit' },
        { key: 'reference', header: 'Reference' },
        { key: 'flag', header: 'Flag' },
        { key: 'status', header: 'Status' },
        { key: 'priority', header: 'Priority' },
      ]}
      rows={rows}
      statusHeaders={['flag', 'status', 'priority']}
      emptyTitle="No laboratory orders"
      emptyDescription="No laboratory orders or released results are recorded for this patient."
      renderCell={(cell, rowId) => {
        if (cell.info.header === 'result' && abnormalResultIds.has(rowId)) {
          return <span className="patient-chart__result-abnormal">{cell.value}</span>;
        }

        return cell.value;
      }}
    />
  );
}

export function PatientImagingSection({
  orders,
  state,
}: {
  orders: PatientChartImagingOrder[];
  state: PatientSectionAccessState;
}): ReactNode {
  if (state) return <PatientSectionState state={state} />;

  const rows = orders.flatMap((order) => (
    order.items.length === 0
      ? [{
        id: order.orderId,
        cells: [date(order.createdAt), order.orderNumber, '—', order.priority, order.status],
      }]
      : order.items.map((item, index) => ({
        id: `${order.orderId}-${index}`,
        cells: [
          date(order.createdAt),
          order.orderNumber,
          item.serviceName ?? item.serviceCode ?? 'Imaging',
          order.priority,
          item.status,
        ],
      }))
  ));

  return (
    <PatientRecordTable
      title="Imaging"
      ariaLabel="Patient imaging orders"
      searchLabel="Search imaging"
      headers={[
        { key: 'date', header: 'Date' },
        { key: 'order', header: 'Order' },
        { key: 'service', header: 'Service' },
        { key: 'priority', header: 'Priority' },
        { key: 'status', header: 'Status' },
      ]}
      rows={rows}
      statusHeaders={['priority', 'status']}
      emptyTitle="No imaging orders"
      emptyDescription="No imaging orders or reports are recorded for this patient."
    />
  );
}

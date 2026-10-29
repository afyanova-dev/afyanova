import {
  Button,
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
  Tag,
} from '@carbon/react';
import { router } from '@inertiajs/react';
import { useMemo, type ReactNode } from 'react';
import { routes } from '@/app/routes';
import { CarbonDataTable } from '@/components/ui/CarbonDataTable';
import { EmptyState, Status } from '@/components/ui';
import type { CatalogItemMapHistoryItem, CatalogItemMapItem } from '../types';

export interface CatalogMappingSectionProps {
  medications: CatalogItemMapItem[];
  history: CatalogItemMapHistoryItem[];
  canManageCatalog: boolean;
  onMap: (itemId: string) => void;
}

/**
 * The medication → billing-service mapping worklist. Item identity is Inventory's;
 * the mapped service's identity/status/tariff is Billing's. An unmapped, inactive,
 * or unpriced medication is the revenue leak the admin resolves here.
 */
export function CatalogMappingSection({
  medications,
  history,
  canManageCatalog,
  onMap,
}: CatalogMappingSectionProps): ReactNode {
  const historyByItem = useMemo(() => {
    const grouped = new Map<string, CatalogItemMapHistoryItem[]>();
    for (const entry of history) {
      const bucket = grouped.get(entry.invItemId);
      if (bucket) {
        bucket.push(entry);
      } else {
        grouped.set(entry.invItemId, [entry]);
      }
    }
    return grouped;
  }, [history]);

  const medicationsById = useMemo(
    () => new Map(medications.map((medication) => [medication.invItemId, medication])),
    [medications],
  );

  const rows = useMemo(
    () => medications.map((medication) => ({
      id: medication.invItemId,
      code: medication.code,
      name: medication.name,
      type: medication.itemType,
      itemStatus: medication.itemActive ? 'Active' : 'Inactive',
      service: medication.mapped
        ? `${medication.serviceCode ?? '—'} — ${medication.serviceName ?? 'Unknown service'}`
        : 'Not mapped',
      serviceStatus: medication.mapped ? medication.serviceStatus ?? 'UNKNOWN' : null,
      tariff: medication.mapped
        ? medication.amount !== null
          ? `${medication.amount} ${medication.currency ?? ''}`.trim()
          : 'Unpriced'
        : '—',
      actions: '',
    })),
    [medications],
  );

  function deactivate(itemId: string): void {
    router.post(
      routes.workspaces.billingCatalogItemMapsDeactivate,
      { item_id: itemId },
      { preserveScroll: true },
    );
  }

  return (
    <CarbonDataTable
      rows={rows}
      headers={[
        { key: 'code', header: 'Code' },
        { key: 'name', header: 'Medication' },
        { key: 'type', header: 'Type' },
        { key: 'itemStatus', header: 'Item' },
        { key: 'service', header: 'Mapped service' },
        { key: 'serviceStatus', header: 'Service' },
        { key: 'tariff', header: 'Tariff' },
        { key: 'actions', header: 'Actions' },
      ]}
      title={`Medication mapping (${medications.length})`}
      ariaLabel="Medication to billing-service mapping"
      emptyState={(
        <EmptyState
          title="No medications in the catalogue"
          description="Add medications in Inventory first — they then appear here to be mapped to a billable service."
        />
      )}
      toolbarLabel="Medication mapping"
      getExpandRowLabel={(row) => {
        const medication = medicationsById.get(String(row.id));
        return medication ? `Mapping history for ${medication.code}` : 'Mapping history';
      }}
      renderExpandedRow={(row) => {
        const entries = historyByItem.get(String(row.id)) ?? [];
        if (entries.length === 0) {
          return <p>No mapping has ever been set for this medication.</p>;
        }
        return (
          <Table aria-label="Mapping history" size="sm">
            <TableHead>
              <TableRow>
                <TableHeader>Service</TableHeader>
                <TableHeader>State</TableHeader>
                <TableHeader>Recorded</TableHeader>
              </TableRow>
            </TableHead>
            <TableBody>
              {entries.map((entry) => (
                <TableRow key={entry.mapId}>
                  <TableCell>{entry.serviceName ?? entry.billingServiceId}</TableCell>
                  <TableCell>{entry.active ? 'Active' : 'Superseded'}</TableCell>
                  <TableCell>{entry.occurredAt}</TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        );
      }}
      renderCell={(cell, row) => {
        const medication = medicationsById.get(String(row.id));
        if (!medication) return cell.value;

        if (cell.info.header === 'code') {
          return <code style={{ fontWeight: 600 }}>{medication.code}</code>;
        }
        if (cell.info.header === 'itemStatus') {
          return (
            <Status kind={medication.itemActive ? 'active' : 'inactive'}>
              {medication.itemActive ? 'Active' : 'Inactive'}
            </Status>
          );
        }
        if (cell.info.header === 'service') {
          if (!medication.mapped) {
            return <Tag type="red" size="sm">Not mapped</Tag>;
          }
          return <span>{cell.value}</span>;
        }
        if (cell.info.header === 'serviceStatus') {
          if (!medication.mapped) return '—';
          const status = medication.serviceStatus;
          const kind = status === 'ACTIVE' ? 'green' : status === 'INACTIVE' ? 'red' : 'gray';
          return <Tag type={kind} size="sm">{status}</Tag>;
        }
        if (cell.info.header === 'tariff') {
          if (!medication.mapped) return '—';
          if (medication.amount === null) {
            return <Tag type="magenta" size="sm">Unpriced</Tag>;
          }
          return <span style={{ fontVariantNumeric: 'tabular-nums' }}>{cell.value}</span>;
        }
        if (cell.info.header === 'actions') {
          return (
            <div style={{ display: 'flex', gap: '0.5rem', flexWrap: 'wrap' }}>
              <Button
                kind={medication.mapped ? 'tertiary' : 'primary'}
                size="sm"
                disabled={!canManageCatalog}
                onClick={() => onMap(medication.invItemId)}
              >
                {medication.mapped ? 'Change' : 'Map'}
              </Button>
              {medication.mapped ? (
                <Button
                  kind="danger--ghost"
                  size="sm"
                  disabled={!canManageCatalog}
                  onClick={() => deactivate(medication.invItemId)}
                >
                  Remove
                </Button>
              ) : null}
            </div>
          );
        }
        return cell.value;
      }}
    />
  );
}

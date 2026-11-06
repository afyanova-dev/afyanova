import {
  Button,
  TableToolbarSearch,
  Tag,
} from '@carbon/react';
import { Link } from '@inertiajs/react';
import { useState, type ReactNode } from 'react';
import { routes } from '@/app/routes';
import {
  CarbonDataTable,
  EmptyState,
  PageHeader,
  StatisticsCards,
} from '@/components/ui';
import { InstrumentDetails } from '@/features/laboratory/components/InstrumentDetails';
import { InstrumentEditorPanel } from '@/features/laboratory/components/InstrumentPanels';
import type {
  EquipmentItem,
  FacilityInstrument,
  MethodOption,
} from '@/features/laboratory/types';
import {
  countLabel,
  instrumentStatusLabel,
  instrumentStatusTone,
} from '@/features/laboratory/utils';
import { Add, ArrowLeft, Box, Checkmark, Microscope, WarningAlt } from '@/icons';
import { AppLayout } from '@/layouts/AppLayout/AppLayout';

interface LaboratoryInstrumentsProps {
  facilityId: string;
  instruments: FacilityInstrument[];
  methods: MethodOption[];
  equipmentItems: EquipmentItem[];
}

const INSTRUMENT_HEADERS = [
  { key: 'identifier', header: 'Identifier' },
  { key: 'instrument', header: 'Instrument' },
  { key: 'makeModel', header: 'Make / model' },
  { key: 'inventory', header: 'Inventory equipment' },
  { key: 'methods', header: 'Methods' },
  { key: 'status', header: 'Status' },
  { key: 'actions', header: 'Actions' },
];

export default function LaboratoryInstruments({
  instruments,
  methods,
  equipmentItems,
}: LaboratoryInstrumentsProps): ReactNode {
  const [editorOpen, setEditorOpen] = useState(false);
  const [editingInstrument, setEditingInstrument] = useState<FacilityInstrument | null>(null);

  const activeInstruments = instruments.filter((instrument) => instrument.status === 'ACTIVE');
  const outOfService = instruments.filter((instrument) => instrument.status === 'OUT_OF_SERVICE');
  const decommissioned = instruments.filter((instrument) => instrument.status === 'DECOMMISSIONED');

  function openEditor(instrument: FacilityInstrument | null): void {
    if (instrument?.status === 'DECOMMISSIONED') return;

    setEditingInstrument(instrument);
    setEditorOpen(true);
  }

  const rows = instruments.map((instrument) => {
    const makeModel = [instrument.manufacturer, instrument.model].filter(Boolean).join(' · ');

    return {
      id: instrument.instrumentId,
      identifier: instrument.identifier,
      instrument: instrument.name,
      makeModel: makeModel || 'Not recorded',
      inventory: instrument.inventoryCode && instrument.inventoryName
        ? `${instrument.inventoryCode} — ${instrument.inventoryName}`
        : 'Not linked',
      methods: countLabel(instrument.supportedMethods.length, 'method', 'methods'),
      status: instrumentStatusLabel(instrument.status),
      actions: '',
    };
  });

  return (
    <AppLayout>
      <main className="afyanova-page laboratory-page laboratory-instruments">
        <PageHeader
          title="Facility laboratory instruments"
          description="Register the instruments installed at this facility and select the active test methods they support. Inventory equipment remains the product record; each row here is the operational instrument instance."
          actions={(
            <>
              <Button
                as={Link}
                href={routes.workspaces.laboratoryCatalog}
                kind="ghost"
                size="lg"
                renderIcon={ArrowLeft}
              >
                Back to tests & panels
              </Button>
              <Button
                as={Link}
                href={routes.workspaces.laboratoryExecutions}
                kind="ghost"
                size="lg"
              >
                Test execution queue
              </Button>
            </>
          )}
        />

        <div className="afy-page__content">
          <StatisticsCards
            label="Facility instrument summary"
            cards={[
              {
                id: 'lab-instruments-total',
                label: 'Instruments',
                value: instruments.length,
                detail: 'Registered at this facility',
                icon: <Microscope />,
                borderTone: 'information',
              },
              {
                id: 'lab-instruments-active',
                label: 'Active',
                value: activeInstruments.length,
                detail: 'Available for laboratory work',
                icon: <Checkmark />,
                borderTone: 'positive',
              },
              {
                id: 'lab-instruments-out-of-service',
                label: 'Out of service',
                value: outOfService.length,
                detail: 'Temporarily unavailable',
                icon: <WarningAlt />,
                borderTone: 'caution',
              },
              {
                id: 'lab-instruments-decommissioned',
                label: 'Decommissioned',
                value: decommissioned.length,
                detail: 'Retained for history',
                icon: <Box />,
              },
            ]}
          />

          <section aria-label="Registered laboratory instruments">
            <CarbonDataTable
              rows={rows}
              headers={INSTRUMENT_HEADERS}
              title={(
                <span className="laboratory-table__title">
                  Registered instruments
                  <span>{instruments.length} total</span>
                </span>
              )}
              ariaLabel="Registered laboratory instruments"
              toolbarLabel="Laboratory instruments toolbar"
              toolbar={(onInputChange) => (
                <>
                  <TableToolbarSearch
                    persistent
                    onChange={onInputChange}
                    placeholder="Search instruments"
                    aria-label="Search laboratory instruments"
                  />
                  <Button size="lg" renderIcon={Add} onClick={() => openEditor(null)}>
                    Register instrument
                  </Button>
                </>
              )}
              emptyState={(
                <EmptyState
                  title={instruments.length === 0
                    ? 'No instruments registered'
                    : 'No instruments match your search'}
                  description={instruments.length === 0
                    ? 'Register an instrument to record its make, model, and the active test methods it supports.'
                    : 'Try a different search term.'}
                />
              )}
              getExpandRowLabel={(row) => {
                const instrument = instruments.find((item) => item.instrumentId === row.id);

                return instrument
                  ? `details for ${instrument.name}`
                  : 'laboratory instrument details';
              }}
              renderExpandedRow={(row) => {
                const instrument = instruments.find((item) => item.instrumentId === row.id);

                return instrument ? <InstrumentDetails instrument={instrument} /> : null;
              }}
              renderCell={(cell, row) => {
                const instrument = instruments.find((item) => item.instrumentId === row.id);
                if (!instrument) return cell.value;

                if (cell.info.header === 'identifier') {
                  return <span className="laboratory-table__code">{instrument.identifier}</span>;
                }
                if (cell.info.header === 'instrument') {
                  return (
                    <div className="laboratory-table__primary">
                      <strong>{instrument.name}</strong>
                      <span>{instrument.status === 'DECOMMISSIONED' ? 'Read-only record' : 'Operational instrument'}</span>
                    </div>
                  );
                }
                if (cell.info.header === 'status') {
                  return (
                    <Tag type={instrumentStatusTone(instrument.status)}>
                      {instrumentStatusLabel(instrument.status)}
                    </Tag>
                  );
                }
                if (cell.info.header === 'actions') {
                  const isDecommissioned = instrument.status === 'DECOMMISSIONED';

                  return (
                    <Button
                      kind="ghost"
                      size="sm"
                      disabled={isDecommissioned}
                      aria-label={isDecommissioned
                        ? `${instrument.name} is decommissioned and cannot be edited`
                        : `Edit instrument ${instrument.name}`}
                      onClick={() => openEditor(instrument)}
                    >
                      {isDecommissioned ? 'Decommissioned' : 'Edit instrument'}
                    </Button>
                  );
                }

                return cell.value;
              }}
            />
          </section>
        </div>
      </main>

      {editorOpen ? (
        <InstrumentEditorPanel
          key={editingInstrument?.instrumentId ?? 'new-instrument'}
          instrument={editingInstrument}
          methods={methods}
          equipmentItems={equipmentItems}
          onClose={() => setEditorOpen(false)}
        />
      ) : null}
    </AppLayout>
  );
}

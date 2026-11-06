import {
  Button,
  TabPanel,
  TabPanels,
  Tabs,
  TableToolbarSearch,
  Tag,
} from '@carbon/react';
import { Link } from '@inertiajs/react';
import { useState, type ReactNode } from 'react';
import { routes } from '@/app/routes';
import {
  CarbonDataTable,
  ContainedTabList,
  EmptyState,
  IconTab,
  PageHeader,
  StatisticsCards,
} from '@/components/ui';
import {
  MethodEditorPanel,
  PanelEditorPanel,
  TestEditorPanel,
} from '@/features/laboratory/components/CatalogPanels';
import { PanelDetails, TestDetails } from '@/features/laboratory/components/CatalogDetails';
import type {
  InventoryItem,
  LabPanel,
  LabTestMethod,
  TestDefinition,
} from '@/features/laboratory/types';
import {
  availabilityLabel,
  availabilityTone,
  countLabel,
} from '@/features/laboratory/utils';
import { usePersistentTab } from '@/hooks/usePersistentTab';
import { Add, Checkmark, Flow, List, Microscope, SettingsAdjust } from '@/icons';
import { AppLayout } from '@/layouts/AppLayout/AppLayout';

interface LaboratoryCatalogProps {
  definitions: TestDefinition[];
  panels: LabPanel[];
  methods: LabTestMethod[];
  inventoryItems: InventoryItem[];
  canManageCatalog: boolean;
  canManageInstruments: boolean;
}

interface MethodEditorState {
  definition: TestDefinition;
  method: LabTestMethod | null;
}

const CATALOG_TABS = ['tests', 'panels'] as const;

const TEST_HEADERS = [
  { key: 'test', header: 'Test' },
  { key: 'category', header: 'Category' },
  { key: 'specimen', header: 'Specimen' },
  { key: 'availability', header: 'Order availability' },
  { key: 'methods', header: 'Methods' },
  { key: 'status', header: 'Status' },
  { key: 'actions', header: 'Actions' },
];

const PANEL_HEADERS = [
  { key: 'panel', header: 'Panel' },
  { key: 'category', header: 'Category' },
  { key: 'members', header: 'Tests' },
  { key: 'status', header: 'Status' },
  { key: 'actions', header: 'Actions' },
];

export default function LaboratoryCatalog({
  definitions,
  panels,
  methods,
  inventoryItems,
  canManageCatalog,
  canManageInstruments,
}: LaboratoryCatalogProps): ReactNode {
  const [selectedTab, setSelectedTab] = usePersistentTab('tab', CATALOG_TABS);
  const [testEditorOpen, setTestEditorOpen] = useState(false);
  const [editingTest, setEditingTest] = useState<TestDefinition | null>(null);
  const [panelEditorOpen, setPanelEditorOpen] = useState(false);
  const [editingPanel, setEditingPanel] = useState<LabPanel | null>(null);
  const [methodEditor, setMethodEditor] = useState<MethodEditorState | null>(null);

  const availableTests = definitions.filter(
    (definition) => definition.active && definition.availabilityStatus === 'AVAILABLE',
  );
  const activePanels = panels.filter((panel) => panel.active);
  const activeMethods = methods.filter((method) => method.active);

  function methodsFor(definitionId: string): LabTestMethod[] {
    return methods.filter((method) => method.testDefinitionId === definitionId);
  }

  function openTestEditor(definition: TestDefinition | null): void {
    setEditingTest(definition);
    setTestEditorOpen(true);
  }

  function openPanelEditor(panel: LabPanel | null): void {
    setEditingPanel(panel);
    setPanelEditorOpen(true);
  }

  const testRows = definitions.map((definition) => {
    const testMethods = methodsFor(definition.definitionId);

    return {
      id: definition.definitionId,
      test: `${definition.name} (${definition.code})`,
      category: definition.category ?? 'Not categorized',
      specimen: definition.specimenType ?? 'Not specified',
      availability: availabilityLabel(definition.availabilityStatus),
      methods: countLabel(testMethods.length, 'method', 'methods'),
      status: definition.active ? 'Active' : 'Inactive',
      actions: '',
    };
  });

  const panelRows = panels.map((panel) => ({
    id: panel.panelId,
    panel: `${panel.name} (${panel.code})`,
    category: panel.category ?? 'Not categorized',
    members: countLabel(panel.members.length, 'test', 'tests'),
    status: panel.active ? 'Active' : 'Inactive',
    actions: '',
  }));

  return (
    <AppLayout>
      <main className="afyanova-page laboratory-page laboratory-catalog">
        <PageHeader
          title="Laboratory tests & panels"
          description={canManageCatalog
            ? 'Configure the tests and panels clinicians can order, including result parameters, specimen requirements, and the methods and materials used to perform them.'
            : 'Read-only view of the tests and panels clinicians can order, including result parameters, specimen requirements, and the methods and materials used to perform them.'}
          actions={canManageInstruments ? (
            <Button
              as={Link}
              href={routes.workspaces.laboratoryInstruments}
              kind="tertiary"
              size="lg"
              renderIcon={SettingsAdjust}
            >
              Configure instruments
            </Button>
          ) : undefined}
        />

        <div className="afy-page__content">
          <StatisticsCards
            label="Laboratory catalog summary"
            cards={[
              {
                id: 'lab-catalog-tests',
                label: 'Test definitions',
                value: definitions.length,
                detail: `${availableTests.length} available to order`,
                icon: <Microscope />,
                borderTone: 'information',
              },
              {
                id: 'lab-catalog-methods',
                label: 'Test methods',
                value: methods.length,
                detail: `${activeMethods.length} active`,
                icon: <Flow />,
              },
              {
                id: 'lab-catalog-panels',
                label: 'Panels',
                value: panels.length,
                detail: `${activePanels.length} active`,
                icon: <List />,
                borderTone: 'positive',
              },
              {
                id: 'lab-catalog-available',
                label: 'Available tests',
                value: availableTests.length,
                detail: 'Active and orderable',
                icon: <Checkmark />,
                borderTone: 'positive',
              },
            ]}
          />

          <Tabs
            selectedIndex={selectedTab}
            onChange={({ selectedIndex }) => setSelectedTab(selectedIndex)}
          >
            <ContainedTabList aria-label="Laboratory catalog">
              <IconTab icon={Microscope}>Tests ({definitions.length})</IconTab>
              <IconTab icon={List}>Panels ({panels.length})</IconTab>
            </ContainedTabList>
            <TabPanels>
              <TabPanel>
                <section aria-label="Laboratory test definitions">
                  <CarbonDataTable
                    rows={testRows}
                    headers={TEST_HEADERS}
                    title={(
                      <span className="laboratory-table__title">
                        Test definitions
                        <span>{definitions.length} total</span>
                      </span>
                    )}
                    ariaLabel="Laboratory test definitions"
                    toolbarLabel="Laboratory test definitions toolbar"
                    toolbar={(onInputChange) => (
                      <>
                        <TableToolbarSearch
                          persistent
                          onChange={onInputChange}
                          placeholder="Search tests"
                          aria-label="Search laboratory tests"
                        />
                        {canManageCatalog ? (
                          <Button size="lg" renderIcon={Add} onClick={() => openTestEditor(null)}>
                            Create test
                          </Button>
                        ) : null}
                      </>
                    )}
                    emptyState={(
                      <EmptyState
                        title={definitions.length === 0
                          ? 'No laboratory tests configured'
                          : 'No tests match your search'}
                        description={definitions.length === 0
                          ? 'Create the first test definition to make it orderable by clinicians.'
                          : 'Try a different search term.'}
                      />
                    )}
                    getExpandRowLabel={(row) => {
                      const definition = definitions.find((item) => item.definitionId === row.id);

                      return definition
                        ? `details for ${definition.name}`
                        : 'laboratory test details';
                    }}
                    renderExpandedRow={(row) => {
                      const definition = definitions.find((item) => item.definitionId === row.id);
                      if (!definition) return null;

                      return (
                        <TestDetails
                          definition={definition}
                          methods={methodsFor(definition.definitionId)}
                          canManageCatalog={canManageCatalog}
                          onAddMethod={() => setMethodEditor({ definition, method: null })}
                          onEditMethod={(method) => setMethodEditor({ definition, method })}
                        />
                      );
                    }}
                    renderCell={(cell, row) => {
                      const definition = definitions.find((item) => item.definitionId === row.id);
                      if (!definition) return cell.value;

                      if (cell.info.header === 'test') {
                        return (
                          <div className="laboratory-table__primary">
                            <strong>{definition.name}</strong>
                            <span>{definition.code}</span>
                          </div>
                        );
                      }
                      if (cell.info.header === 'availability') {
                        return (
                          <Tag type={availabilityTone(definition.availabilityStatus)}>
                            {availabilityLabel(definition.availabilityStatus)}
                          </Tag>
                        );
                      }
                      if (cell.info.header === 'status') {
                        return (
                          <Tag type={definition.active ? 'green' : 'gray'}>
                            {definition.active ? 'Active' : 'Inactive'}
                          </Tag>
                        );
                      }
                      if (cell.info.header === 'actions') {
                        return canManageCatalog ? (
                          <Button
                            kind="ghost"
                            size="sm"
                            aria-label={`Edit test ${definition.name}`}
                            onClick={() => openTestEditor(definition)}
                          >
                            Edit test
                          </Button>
                        ) : null;
                      }

                      return cell.value;
                    }}
                  />
                </section>
              </TabPanel>
              <TabPanel>
                <section aria-label="Laboratory panels">
                  <CarbonDataTable
                    rows={panelRows}
                    headers={PANEL_HEADERS}
                    title={(
                      <span className="laboratory-table__title">
                        Panels
                        <span>{panels.length} total</span>
                      </span>
                    )}
                    ariaLabel="Laboratory panels"
                    toolbarLabel="Laboratory panels toolbar"
                    toolbar={(onInputChange) => (
                      <>
                        <TableToolbarSearch
                          persistent
                          onChange={onInputChange}
                          placeholder="Search panels"
                          aria-label="Search laboratory panels"
                        />
                        {canManageCatalog ? (
                          <Button size="lg" renderIcon={Add} onClick={() => openPanelEditor(null)}>
                            Create panel
                          </Button>
                        ) : null}
                      </>
                    )}
                    emptyState={(
                      <EmptyState
                        title={panels.length === 0
                          ? 'No laboratory panels configured'
                          : 'No panels match your search'}
                        description={panels.length === 0
                          ? 'Group active, available tests into a panel clinicians can order together.'
                          : 'Try a different search term.'}
                      />
                    )}
                    getExpandRowLabel={(row) => {
                      const panel = panels.find((item) => item.panelId === row.id);

                      return panel ? `details for ${panel.name}` : 'laboratory panel details';
                    }}
                    renderExpandedRow={(row) => {
                      const panel = panels.find((item) => item.panelId === row.id);

                      return panel ? <PanelDetails panel={panel} /> : null;
                    }}
                    renderCell={(cell, row) => {
                      const panel = panels.find((item) => item.panelId === row.id);
                      if (!panel) return cell.value;

                      if (cell.info.header === 'panel') {
                        return (
                          <div className="laboratory-table__primary">
                            <strong>{panel.name}</strong>
                            <span>{panel.code}</span>
                          </div>
                        );
                      }
                      if (cell.info.header === 'status') {
                        return (
                          <Tag type={panel.active ? 'green' : 'gray'}>
                            {panel.active ? 'Active' : 'Inactive'}
                          </Tag>
                        );
                      }
                      if (cell.info.header === 'actions') {
                        return canManageCatalog ? (
                          <Button
                            kind="ghost"
                            size="sm"
                            aria-label={`Edit panel ${panel.name}`}
                            onClick={() => openPanelEditor(panel)}
                          >
                            Edit panel
                          </Button>
                        ) : null;
                      }

                      return cell.value;
                    }}
                  />
                </section>
              </TabPanel>
            </TabPanels>
          </Tabs>
        </div>
      </main>

      {canManageCatalog && testEditorOpen ? (
        <TestEditorPanel
          key={editingTest?.definitionId ?? 'new-test'}
          definition={editingTest}
          onClose={() => setTestEditorOpen(false)}
        />
      ) : null}

      {canManageCatalog && panelEditorOpen ? (
        <PanelEditorPanel
          key={editingPanel?.panelId ?? 'new-panel'}
          panel={editingPanel}
          definitions={definitions}
          onClose={() => setPanelEditorOpen(false)}
        />
      ) : null}

      {canManageCatalog && methodEditor ? (
        <MethodEditorPanel
          key={`${methodEditor.definition.definitionId}-${methodEditor.method?.methodId ?? 'new-method'}`}
          definition={methodEditor.definition}
          method={methodEditor.method}
          inventoryItems={inventoryItems}
          onClose={() => setMethodEditor(null)}
        />
      ) : null}
    </AppLayout>
  );
}

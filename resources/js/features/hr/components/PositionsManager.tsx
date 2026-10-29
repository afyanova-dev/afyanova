import { Add } from '@carbon/icons-react';
import {
  Button,
  Select,
  SelectItem,
  TabPanel,
  TabPanels,
  Tabs,
  TableToolbarSearch,
  TextArea,
  TextInput,
} from '@carbon/react';
import { useForm } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import { routes } from '@/app/routes';
import { DockedPanel } from '@/components/panels';
import { CarbonDataTable, CheckboxFilterPopover, ContainedTabList, EmptyState, IconTab } from '@/components/ui';
import { usePersistentTab } from '@/hooks/usePersistentTab';
import { Task, UserMultiple } from '@/icons';
import type { CadreOption, PositionOption } from '../types';

interface PositionsManagerProps {
  cadres: CadreOption[];
  positions: PositionOption[];
}

type PanelMode = 'cadre' | 'position';

const JOB_STRUCTURE_TABS = ['cadres', 'positions'] as const;

const CADRE_HEADERS = [
  { key: 'code', header: 'Code' },
  { key: 'name', header: 'Job cadre' },
  { key: 'category', header: 'Category' },
  { key: 'positions', header: 'Positions' },
];

const POSITION_HEADERS = [
  { key: 'code', header: 'Code' },
  { key: 'title', header: 'Position' },
  { key: 'cadre', header: 'Job cadre' },
];

export function PositionsManager({ cadres, positions }: PositionsManagerProps): React.JSX.Element {
  const [panelMode, setPanelMode] = useState<PanelMode | null>(null);
  const [selectedTab, setSelectedTab] = usePersistentTab('tab', JOB_STRUCTURE_TABS);
  const [selectedCadreIds, setSelectedCadreIds] = useState<string[]>([]);
  const cadreForm = useForm({ code: '', name: '', category: 'CLINICAL', description: '' });
  const positionForm = useForm({ code: '', title: '', cadre_id: '', description: '' });
  const visiblePositions = selectedCadreIds.length > 0
    ? positions.filter((position) => selectedCadreIds.includes(position.cadreId))
    : positions;
  const positionRows = visiblePositions.map((position) => ({
    id: position.id,
    code: position.code ?? '—',
    title: position.title,
    cadre: cadres.find((cadre) => cadre.id === position.cadreId)?.name ?? 'Cadre unavailable',
  }));
  const cadreRows = cadres.map((cadre) => ({
    id: cadre.id,
    code: cadre.code,
    name: cadre.name,
    category: cadre.category.replaceAll('_', ' '),
    positions: positions.filter((position) => position.cadreId === cadre.id).length,
  }));

  function createCadre(event: FormEvent): void {
    event.preventDefault();
    cadreForm.post(routes.workspaces.hrStaffCadres, {
      preserveScroll: true,
      onSuccess: () => {
        cadreForm.reset();
        setPanelMode(null);
      },
    });
  }

  function createPosition(event: FormEvent): void {
    event.preventDefault();
    positionForm.post(routes.workspaces.hrStaffPositions, {
      preserveScroll: true,
      onSuccess: () => {
        const selectedCadreId = positionForm.data.cadre_id;
        positionForm.reset();
        positionForm.setData('cadre_id', selectedCadreId);
        setPanelMode(null);
      },
    });
  }

  function openPositionPanel(): void {
    if (!cadres.some((cadre) => cadre.id === positionForm.data.cadre_id)) {
      positionForm.setData('cadre_id', cadres[0]?.id ?? '');
    }
    setPanelMode('position');
  }

  return (
    <>
      <section className="hr-position-manager" aria-label="Job cadres and positions">
        <Tabs
          selectedIndex={selectedTab}
          onChange={({ selectedIndex }) => setSelectedTab(selectedIndex)}
        >
          <ContainedTabList aria-label="Job structure">
            <IconTab icon={UserMultiple}>Cadres ({cadres.length})</IconTab>
            <IconTab icon={Task}>Positions ({positions.length})</IconTab>
          </ContainedTabList>
          <TabPanels>
            <TabPanel>
              <CarbonDataTable
                rows={cadreRows}
                headers={CADRE_HEADERS}
                title="Job cadres"
                ariaLabel="Job cadres"
                emptyState={<EmptyState title="No job cadres yet" description="Add a cadre to start building the job structure." />}
                toolbar={(onInputChange) => (
                  <>
                    <TableToolbarSearch
                      persistent
                      onChange={onInputChange}
                      placeholder="Search cadres"
                      aria-label="Search cadres"
                    />
                    <Button size="lg" kind="primary" renderIcon={Add} onClick={() => setPanelMode('cadre')}>
                      Add cadre
                    </Button>
                  </>
                )}
              />
            </TabPanel>
            <TabPanel>
              <CarbonDataTable
                rows={positionRows}
                headers={POSITION_HEADERS}
                title="Job positions"
                ariaLabel="Job positions"
                emptyState={<EmptyState title="No positions yet" description="Add a position to a job cadre." />}
                toolbar={(onInputChange) => (
                  <>
                    <TableToolbarSearch
                      persistent
                      onChange={onInputChange}
                      placeholder="Search positions"
                      aria-label="Search positions"
                    />
                    <PositionCadreFilter
                      cadres={cadres}
                      selectedCadreIds={selectedCadreIds}
                      onApply={setSelectedCadreIds}
                    />
                    <Button size="lg"
                      kind="primary"
                      renderIcon={Add}
                      disabled={cadres.length === 0}
                      onClick={openPositionPanel}
                    >
                      Add position
                    </Button>
                  </>
                )}
              />
            </TabPanel>
          </TabPanels>
        </Tabs>
      </section>

      {panelMode === 'cadre' ? (
        <DockedPanel
          heading="Add job cadre"
          onClose={() => setPanelMode(null)}
          onSubmit={createCadre}
          primaryLabel={cadreForm.processing ? 'Adding cadre…' : 'Add cadre'}
          primaryDisabled={cadreForm.processing || !cadreForm.data.code.trim() || !cadreForm.data.name.trim()}
          width="min(32rem, 100%)"
        >
          <p className="hr-position-manager__panel-intro">
            Cadres are the parent groups for related job positions. Add a cadre before defining its positions.
          </p>
          <div className="hr-position-manager__form">
            <TextInput
              id="hr-cadre-code"
              labelText="Cadre code"
              helperText="Use uppercase letters, numbers, hyphens, or underscores."
              value={cadreForm.data.code}
              onChange={(event) => cadreForm.setData('code', event.currentTarget.value.toUpperCase())}
              invalid={Boolean(cadreForm.errors.code)}
              invalidText={cadreForm.errors.code}
              required
            />
            <TextInput
              id="hr-cadre-name"
              labelText="Cadre name"
              value={cadreForm.data.name}
              onChange={(event) => cadreForm.setData('name', event.currentTarget.value)}
              invalid={Boolean(cadreForm.errors.name)}
              invalidText={cadreForm.errors.name}
              required
            />
            <Select
              id="hr-cadre-category"
              labelText="Category"
              value={cadreForm.data.category}
              onChange={(event) => cadreForm.setData('category', event.currentTarget.value)}
              invalid={Boolean(cadreForm.errors.category)}
              invalidText={cadreForm.errors.category}
            >
              <SelectItem value="CLINICAL" text="Clinical" />
              <SelectItem value="NURSING" text="Nursing" />
              <SelectItem value="ALLIED" text="Allied health" />
              <SelectItem value="PHARMACY" text="Pharmacy" />
              <SelectItem value="LABORATORY" text="Laboratory" />
              <SelectItem value="ADMIN" text="Administration" />
              <SelectItem value="SUPPORT" text="Support" />
            </Select>
            <TextArea
              id="hr-cadre-description"
              labelText="Description (optional)"
              value={cadreForm.data.description}
              onChange={(event) => cadreForm.setData('description', event.currentTarget.value)}
              invalid={Boolean(cadreForm.errors.description)}
              invalidText={cadreForm.errors.description}
              rows={3}
              maxLength={2000}
            />
          </div>
        </DockedPanel>
      ) : null}

      {panelMode === 'position' ? (
        <DockedPanel
          heading="Add position"
          onClose={() => setPanelMode(null)}
          onSubmit={createPosition}
          primaryLabel={positionForm.processing ? 'Adding position…' : 'Add position'}
          primaryDisabled={positionForm.processing || !positionForm.data.cadre_id
            || !positionForm.data.code.trim() || !positionForm.data.title.trim()}
          width="min(32rem, 100%)"
        >
          <p className="hr-position-manager__panel-intro">
            Select the cadre this position belongs to. You can add more positions under the same cadre.
          </p>
          <div className="hr-position-manager__form">
            <Select
              id="hr-position-cadre"
              labelText="Job cadre"
              value={positionForm.data.cadre_id}
              onChange={(event) => positionForm.setData('cadre_id', event.currentTarget.value)}
              invalid={Boolean(positionForm.errors.cadre_id)}
              invalidText={positionForm.errors.cadre_id}
              required
            >
              {cadres.map((cadre) => (
                <SelectItem key={cadre.id} value={cadre.id} text={`${cadre.name} · ${cadre.category}`} />
              ))}
            </Select>
            <TextInput
              id="hr-position-code"
              labelText="Position code"
              helperText="Use uppercase letters, numbers, hyphens, or underscores."
              value={positionForm.data.code}
              onChange={(event) => positionForm.setData('code', event.currentTarget.value.toUpperCase())}
              invalid={Boolean(positionForm.errors.code)}
              invalidText={positionForm.errors.code}
              required
            />
            <TextInput
              id="hr-position-title"
              labelText="Position title"
              value={positionForm.data.title}
              onChange={(event) => positionForm.setData('title', event.currentTarget.value)}
              invalid={Boolean(positionForm.errors.title)}
              invalidText={positionForm.errors.title}
              required
            />
            <TextArea
              id="hr-position-description"
              labelText="Description (optional)"
              value={positionForm.data.description}
              onChange={(event) => positionForm.setData('description', event.currentTarget.value)}
              invalid={Boolean(positionForm.errors.description)}
              invalidText={positionForm.errors.description}
              rows={3}
              maxLength={2000}
            />
          </div>
        </DockedPanel>
      ) : null}
    </>
  );
}

function PositionCadreFilter({
  cadres,
  selectedCadreIds,
  onApply,
}: {
  cadres: CadreOption[];
  selectedCadreIds: string[];
  onApply: (cadreIds: string[]) => void;
}): React.JSX.Element {
  const filterLabel = selectedCadreIds.length > 0
    ? `Filter positions by cadre, ${selectedCadreIds.length} active`
    : 'Filter positions by cadre';

  return (
    <CheckboxFilterPopover
      ariaLabel={filterLabel}
      legend="Filter by cadre"
      options={cadres.map((cadre) => ({ id: cadre.id, label: cadre.name }))}
      selectedIds={selectedCadreIds}
      onApply={onApply}
      onReset={() => onApply([])}
    />
  );
}

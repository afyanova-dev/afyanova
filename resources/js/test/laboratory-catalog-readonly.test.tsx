import { fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { LabPanel, LabTestMethod, TestDefinition } from '@/features/laboratory/types';
import { TestDetails } from '@/features/laboratory/components/CatalogDetails';
import LaboratoryCatalog from '@/pages/Laboratory/Catalog';

beforeEach(() => {
  window.localStorage.clear();

  Object.defineProperty(window, 'matchMedia', {
    writable: true,
    value: vi.fn().mockImplementation((query: string) => ({
      matches: false,
      media: query,
      onchange: null,
      addListener: vi.fn(),
      removeListener: vi.fn(),
      addEventListener: vi.fn(),
      removeEventListener: vi.fn(),
      dispatchEvent: vi.fn(),
    })),
  });

  globalThis.ResizeObserver = class ResizeObserver {
    observe(): void {}
    unobserve(): void {}
    disconnect(): void {}
  };
});

vi.mock('@/layouts/AppLayout/AppLayout', () => ({
  AppLayout: ({ children }: { children: React.ReactNode }) => <>{children}</>,
}));

const definition: TestDefinition = {
  definitionId: 'test-1',
  code: 'FBC',
  name: 'Full Blood Count',
  category: 'HAEMATOLOGY',
  description: null,
  specimenType: 'WHOLE_BLOOD',
  containerType: 'EDTA_TUBE',
  parameters: [],
  requiredCapabilityCode: null,
  availabilityStatus: 'AVAILABLE',
  turnaroundTargetMinutes: 45,
  active: true,
  version: 1,
};

const method: LabTestMethod = {
  methodId: 'method-1',
  testDefinitionId: 'test-1',
  code: 'MANUAL',
  name: 'Manual method',
  description: null,
  specimenType: null,
  containerType: null,
  active: true,
  version: 1,
  requirements: [],
};

const panels: LabPanel[] = [{
  panelId: 'panel-1',
  code: 'CBC',
  name: 'Complete Blood Count',
  category: 'HAEMATOLOGY',
  description: null,
  active: true,
  version: 1,
  members: [],
}];

describe('laboratory catalog read-only access', () => {
  it('omits catalog authoring controls for a read-only viewer', () => {
    render(
      <LaboratoryCatalog
        definitions={[definition]}
        panels={panels}
        methods={[method]}
        inventoryItems={[]}
        canManageCatalog={false}
        canManageInstruments={false}
      />,
    );

    expect(screen.getByText(/Read-only view of the tests and panels/)).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Create test' })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Create panel' })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Edit test Full Blood Count' })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Edit panel Complete Blood Count' })).not.toBeInTheDocument();
  });

  it('hides method editing controls while retaining method details', () => {
    render(
      <TestDetails
        definition={definition}
        methods={[method]}
        canManageCatalog={false}
        onAddMethod={vi.fn()}
        onEditMethod={vi.fn()}
      />,
    );

    expect(screen.getByText('Manual method')).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Add method' })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Edit method Manual method' })).not.toBeInTheDocument();
  });

  it('shows authoring controls to catalog editors', () => {
    render(
      <LaboratoryCatalog
        definitions={[]}
        panels={[]}
        methods={[]}
        inventoryItems={[]}
        canManageCatalog
        canManageInstruments={false}
      />,
    );

    expect(screen.getByRole('button', { name: 'Create test' })).toBeInTheDocument();

    fireEvent.click(screen.getByRole('tab', { name: /Panels/i }));
    expect(screen.getByRole('button', { name: 'Create panel' })).toBeInTheDocument();
  });
});

export type ValueType = 'NUMERIC' | 'CODED' | 'TEXT' | '';

export interface ReferenceRanges {
  low: number | null;
  high: number | null;
  text: string;
  source: string;
}

export interface CodedValue {
  code: string;
  name: string;
}

export interface LabParameter {
  code: string;
  name: string;
  value_type: ValueType;
  unit: string;
  reference_ranges: ReferenceRanges;
  coded_values: CodedValue[];
}

export interface TestDefinition {
  definitionId: string;
  code: string;
  name: string;
  category: string | null;
  description: string | null;
  specimenType: string | null;
  containerType: string | null;
  parameters: LabParameter[] | null;
  requiredCapabilityCode: string | null;
  availabilityStatus: string;
  turnaroundTargetMinutes: number | null;
  active: boolean;
  version: number;
}

export interface PanelMember {
  testDefinitionId: string;
  testCode: string;
  testName: string;
  sequence: number;
}

export interface LabPanel {
  panelId: string;
  code: string;
  name: string;
  category: string | null;
  description: string | null;
  active: boolean;
  version: number;
  members: PanelMember[];
}

export interface InventoryItem {
  itemId: string;
  code: string;
  name: string;
  uom: string;
  category: string | null;
  itemType: string;
}

export interface MethodRequirement {
  requirementId: string;
  inventoryItemId: string;
  itemCode: string;
  itemName: string;
  itemUom: string;
  itemType: string;
  itemCategory: string | null;
  role: string;
  required: boolean;
  usageScope: string;
  quantity: string;
}

export interface LabTestMethod {
  methodId: string;
  testDefinitionId: string;
  code: string;
  name: string;
  description: string | null;
  specimenType: string | null;
  containerType: string | null;
  active: boolean;
  version: number;
  requirements: MethodRequirement[];
}

export interface RequirementForm {
  inventory_item_id: string;
  role: string;
  required: boolean;
  usage_scope: string;
  quantity: string;
}

export interface MethodForm {
  code: string;
  name: string;
  description: string;
  specimen_type: string;
  container_type: string;
  active: boolean;
  requirements: RequirementForm[];
}

export interface TestForm {
  code: string;
  name: string;
  category: string;
  description: string;
  specimen_type: string;
  container_type: string;
  parameters: LabParameter[];
  required_capability_code: string;
  availability_status: string;
  turnaround_target_minutes: number | null;
  active: boolean;
}

export interface PanelForm {
  code: string;
  name: string;
  category: string;
  description: string;
  member_test_ids: string[];
  active: boolean;
}

export interface SupportedMethod {
  methodId: string;
  methodCode: string;
  methodName: string;
  methodVersion: number;
}

export interface FacilityInstrument {
  instrumentId: string;
  facilityId: string;
  identifier: string;
  name: string;
  manufacturer: string | null;
  model: string | null;
  serialNumber: string | null;
  inventoryItemId: string | null;
  inventoryCode: string | null;
  inventoryName: string | null;
  status: string;
  version: number;
  supportedMethods: SupportedMethod[];
}

export interface MethodOption {
  methodId: string;
  testDefinitionId: string;
  code: string;
  name: string;
  active: boolean;
}

export interface EquipmentItem {
  itemId: string;
  code: string;
  name: string;
}

export interface InstrumentForm {
  identifier: string;
  name: string;
  manufacturer: string;
  model: string;
  serial_number: string;
  inventory_item_id: string;
  status: string;
  supported_method_ids: string[];
}

export interface CatalogEditorCandidate {
  userId: string;
  name: string;
  email: string;
  isEditor: boolean;
}

export const EMPTY_RANGES: ReferenceRanges = { low: null, high: null, text: '', source: '' };

export const EMPTY_TEST: TestForm = {
  code: '',
  name: '',
  category: '',
  description: '',
  specimen_type: '',
  container_type: '',
  parameters: [],
  required_capability_code: '',
  availability_status: 'AVAILABLE',
  turnaround_target_minutes: null,
  active: true,
};

export const EMPTY_PANEL: PanelForm = {
  code: '',
  name: '',
  category: '',
  description: '',
  member_test_ids: [],
  active: true,
};

export const EMPTY_METHOD: MethodForm = {
  code: '',
  name: '',
  description: '',
  specimen_type: '',
  container_type: '',
  active: true,
  requirements: [],
};

export const EMPTY_INSTRUMENT: InstrumentForm = {
  identifier: '',
  name: '',
  manufacturer: '',
  model: '',
  serial_number: '',
  inventory_item_id: '',
  status: 'ACTIVE',
  supported_method_ids: [],
};

export function formForTest(definition: TestDefinition): TestForm {
  return {
    code: definition.code,
    name: definition.name,
    category: definition.category ?? '',
    description: definition.description ?? '',
    specimen_type: definition.specimenType ?? '',
    container_type: definition.containerType ?? '',
    parameters: normalizeParameters(definition.parameters),
    required_capability_code: definition.requiredCapabilityCode ?? '',
    availability_status: definition.availabilityStatus,
    turnaround_target_minutes: definition.turnaroundTargetMinutes,
    active: definition.active,
  };
}

export function formForPanel(panel: LabPanel): PanelForm {
  return {
    code: panel.code,
    name: panel.name,
    category: panel.category ?? '',
    description: panel.description ?? '',
    member_test_ids: panel.members.map((member) => member.testDefinitionId),
    active: panel.active,
  };
}

export function formForMethod(method: LabTestMethod): MethodForm {
  return {
    code: method.code,
    name: method.name,
    description: method.description ?? '',
    specimen_type: method.specimenType ?? '',
    container_type: method.containerType ?? '',
    active: method.active,
    requirements: method.requirements.map((requirement) => ({
      inventory_item_id: requirement.inventoryItemId,
      role: requirement.role,
      required: requirement.required,
      usage_scope: requirement.usageScope,
      quantity: requirement.quantity,
    })),
  };
}

export function formForInstrument(instrument: FacilityInstrument, methods: MethodOption[]): InstrumentForm {
  const selectableMethods = new Set(methods.map((method) => method.methodId));

  return {
    identifier: instrument.identifier,
    name: instrument.name,
    manufacturer: instrument.manufacturer ?? '',
    model: instrument.model ?? '',
    serial_number: instrument.serialNumber ?? '',
    inventory_item_id: instrument.inventoryItemId ?? '',
    status: instrument.status,
    supported_method_ids: instrument.supportedMethods
      .map((method) => method.methodId)
      .filter((methodId) => selectableMethods.has(methodId)),
  };
}

export function newParameter(): LabParameter {
  return {
    code: '',
    name: '',
    value_type: 'NUMERIC',
    unit: '',
    reference_ranges: { ...EMPTY_RANGES },
    coded_values: [],
  };
}

export function newRequirement(): RequirementForm {
  return {
    inventory_item_id: '',
    role: 'REAGENT',
    required: true,
    usage_scope: 'PER_TEST',
    quantity: '1',
  };
}

/**
 * The backend treats `code` as immutable on updates (it is `prohibited` in
 * the PUT requests), so edit submissions must omit it entirely.
 */
export function omitKey<T extends object, K extends keyof T>(value: T, key: K): Omit<T, K> {
  const copy = { ...value } as Partial<T>;
  delete copy[key];

  return copy as Omit<T, K>;
}

export function withoutCode<T extends { code: string }>(value: T): Omit<T, 'code'> {
  return omitKey(value, 'code');
}

export function normalizeParameters(parameters: LabParameter[] | null): LabParameter[] {
  return (parameters ?? []).map((parameter) => ({
    code: parameter.code ?? '',
    name: parameter.name ?? '',
    value_type: parameter.value_type ?? '',
    unit: parameter.unit ?? '',
    reference_ranges: {
      low: parameter.reference_ranges?.low ?? null,
      high: parameter.reference_ranges?.high ?? null,
      text: parameter.reference_ranges?.text ?? '',
      source: parameter.reference_ranges?.source ?? '',
    },
    coded_values: (parameter.coded_values ?? []).map((codedValue) => ({
      code: codedValue.code ?? '',
      name: codedValue.name ?? '',
    })),
  }));
}

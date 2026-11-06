import {
  Button,
  Checkbox,
  CheckboxGroup,
  InlineNotification,
  Select,
  SelectItem,
  Stack,
  TextArea,
  TextInput,
} from '@carbon/react';
import { useForm } from '@inertiajs/react';
import type { FormEvent, ReactNode } from 'react';
import { routes } from '@/app/routes';
import { DockedPanel } from '@/components/panels';
import { FormErrors } from '@/components/ui';
import {
  EMPTY_METHOD,
  EMPTY_PANEL,
  EMPTY_TEST,
  formForMethod,
  formForPanel,
  formForTest,
  newParameter,
  newRequirement,
  withoutCode,
} from '../types';
import type {
  InventoryItem,
  LabPanel,
  LabParameter,
  LabTestMethod,
  MethodForm,
  PanelForm,
  RequirementForm,
  TestDefinition,
  TestForm,
} from '../types';
import { roleLabel } from '../utils';

const TEST_ERROR_FIELDS = [
  'code',
  'name',
  'category',
  'description',
  'specimen_type',
  'container_type',
  'required_capability_code',
  'availability_status',
  'turnaround_target_minutes',
  'active',
] as const;

const PANEL_ERROR_FIELDS = [
  'code',
  'name',
  'category',
  'description',
  'active',
] as const;

const METHOD_ERROR_FIELDS = [
  'code',
  'name',
  'description',
  'specimen_type',
  'container_type',
  'active',
] as const;

const AVAILABILITY_OPTIONS = [
  { value: 'AVAILABLE', text: 'Available to order' },
  { value: 'TEMPORARILY_UNAVAILABLE', text: 'Temporarily unavailable' },
  { value: 'REFERRAL_ONLY', text: 'Referral only' },
  { value: 'DISABLED', text: 'Disabled' },
] as const;

const REQUIREMENT_ROLES = ['REAGENT', 'KIT', 'CALIBRATOR', 'CONTROL', 'CONSUMABLE'] as const;

const USAGE_SCOPES = [
  { value: 'PER_TEST', text: 'Per test' },
  { value: 'PER_RUN', text: 'Per run' },
] as const;

export interface TestEditorPanelProps {
  definition: TestDefinition | null;
  onClose: () => void;
}

export function TestEditorPanel({ definition, onClose }: TestEditorPanelProps): ReactNode {
  const isEditing = definition !== null;
  const form = useForm<TestForm>(definition !== null ? formForTest(definition) : EMPTY_TEST);

  function submit(event: FormEvent): void {
    event.preventDefault();

    if (definition !== null) {
      form.transform((data) => withoutCode(data));
      form.put(`${routes.workspaces.laboratoryCatalog}/tests/${definition.definitionId}`, {
        preserveScroll: true,
        onSuccess: onClose,
      });

      return;
    }

    form.post(`${routes.workspaces.laboratoryCatalog}/tests`, {
      preserveScroll: true,
      onSuccess: onClose,
    });
  }

  function updateParameter(index: number, parameter: LabParameter): void {
    form.setData('parameters', form.data.parameters.map((item, itemIndex) => (
      itemIndex === index ? parameter : item
    )));
  }

  function removeParameter(index: number): void {
    form.setData('parameters', form.data.parameters.filter((_, itemIndex) => itemIndex !== index));
  }

  return (
    <DockedPanel
      heading={isEditing ? 'Edit test definition' : 'Create test definition'}
      description="Tests define the result parameters, specimen requirements, and order availability."
      onClose={onClose}
      onSubmit={(event) => submit(event)}
      noValidate
      primaryLabel={form.processing ? 'Saving…' : isEditing ? 'Save test changes' : 'Create test'}
      primaryDisabled={form.processing || form.data.parameters.length === 0}
      width="min(42rem, 100%)"
      expandedWidth="min(56rem, 100%)"
      expandable
    >
      <Stack gap={5}>
        <FormErrors errors={form.errors} fields={TEST_ERROR_FIELDS} />

        <TextInput
          id="lab-test-code"
          labelText="Test code"
          helperText={isEditing ? 'Test codes cannot change after creation.' : 'A short, unique code used on orders and results.'}
          value={form.data.code}
          maxLength={100}
          disabled={isEditing}
          onChange={(event) => form.setData('code', event.target.value)}
          invalid={Boolean(form.errors.code)}
          invalidText={form.errors.code}
          required
        />
        <TextInput
          id="lab-test-name"
          labelText="Test name"
          value={form.data.name}
          maxLength={200}
          onChange={(event) => form.setData('name', event.target.value)}
          invalid={Boolean(form.errors.name)}
          invalidText={form.errors.name}
          required
        />
        <div className="laboratory-editor__row">
          <TextInput
            id="lab-test-category"
            labelText="Category"
            value={form.data.category}
            maxLength={100}
            onChange={(event) => form.setData('category', event.target.value)}
            invalid={Boolean(form.errors.category)}
            invalidText={form.errors.category}
            required
          />
          <TextInput
            id="lab-test-capability"
            labelText="Required facility capability (optional)"
            value={form.data.required_capability_code}
            maxLength={100}
            onChange={(event) => form.setData('required_capability_code', event.target.value)}
            invalid={Boolean(form.errors.required_capability_code)}
            invalidText={form.errors.required_capability_code}
          />
        </div>
        <TextArea
          id="lab-test-description"
          labelText="Description (optional)"
          value={form.data.description}
          maxLength={2000}
          rows={3}
          onChange={(event) => form.setData('description', event.target.value)}
          invalid={Boolean(form.errors.description)}
          invalidText={form.errors.description}
        />
        <div className="laboratory-editor__row">
          <TextInput
            id="lab-test-specimen"
            labelText="Specimen type"
            value={form.data.specimen_type}
            maxLength={100}
            onChange={(event) => form.setData('specimen_type', event.target.value)}
            invalid={Boolean(form.errors.specimen_type)}
            invalidText={form.errors.specimen_type}
            required
          />
          <TextInput
            id="lab-test-container"
            labelText="Container type"
            value={form.data.container_type}
            maxLength={100}
            onChange={(event) => form.setData('container_type', event.target.value)}
            invalid={Boolean(form.errors.container_type)}
            invalidText={form.errors.container_type}
            required
          />
        </div>
        <div className="laboratory-editor__row">
          <Select
            id="lab-test-availability"
            labelText="Order availability"
            value={form.data.availability_status}
            onChange={(event) => form.setData('availability_status', event.target.value)}
            invalid={Boolean(form.errors.availability_status)}
            invalidText={form.errors.availability_status}
          >
            {AVAILABILITY_OPTIONS.map((option) => (
              <SelectItem key={option.value} value={option.value} text={option.text} />
            ))}
          </Select>
          <TextInput
            id="lab-test-turnaround"
            labelText="Turnaround target in minutes (optional)"
            type="number"
            min={1}
            max={525600}
            value={form.data.turnaround_target_minutes ?? ''}
            onChange={(event) => form.setData(
              'turnaround_target_minutes',
              event.target.value === '' ? null : Number(event.target.value),
            )}
            invalid={Boolean(form.errors.turnaround_target_minutes)}
            invalidText={form.errors.turnaround_target_minutes}
          />
        </div>
        {isEditing ? (
          <Checkbox
            id="lab-test-active"
            labelText="Active"
            checked={form.data.active}
            onChange={(_, { checked }) => form.setData('active', checked)}
          />
        ) : null}

        <fieldset className="laboratory-editor__group">
          <legend className="laboratory-editor__group-legend">Structured result parameters</legend>
          <p className="laboratory-editor__hint">
            Add at least one row for every result this test reports. Numeric parameters can carry
            reference limits; coded parameters need at least one result option.
          </p>
          {form.data.parameters.length === 0 ? (
            <p className="laboratory-editor__hint">No result parameters have been added yet.</p>
          ) : null}
          {form.data.parameters.map((parameter, index) => (
            <ParameterEditor
              key={`parameter-${index}`}
              index={index}
              parameter={parameter}
              errors={form.errors}
              onChange={(updated) => updateParameter(index, updated)}
              onRemove={() => removeParameter(index)}
            />
          ))}
          <div className="laboratory-editor__actions">
            <Button
              type="button"
              kind="tertiary"
              size="sm"
              onClick={() => form.setData('parameters', [...form.data.parameters, newParameter()])}
            >
              Add result parameter
            </Button>
          </div>
        </fieldset>
      </Stack>
    </DockedPanel>
  );
}

function ParameterEditor({
  index,
  parameter,
  errors,
  onChange,
  onRemove,
}: {
  index: number;
  parameter: LabParameter;
  errors: Record<string, string>;
  onChange: (parameter: LabParameter) => void;
  onRemove: () => void;
}): ReactNode {
  const prefix = `parameters.${index}`;
  const codeError = errors[`${prefix}.code`];
  const nameError = errors[`${prefix}.name`];
  const typeError = errors[`${prefix}.value_type`];
  const unitError = errors[`${prefix}.unit`];
  const rangesError = errors[`${prefix}.reference_ranges`];
  const lowError = errors[`${prefix}.reference_ranges.low`];
  const highError = errors[`${prefix}.reference_ranges.high`];
  const textError = errors[`${prefix}.reference_ranges.text`];
  const sourceError = errors[`${prefix}.reference_ranges.source`];
  const codedError = errors[`${prefix}.coded_values`];

  function updateReference(patch: Partial<LabParameter['reference_ranges']>): void {
    onChange({
      ...parameter,
      reference_ranges: { ...parameter.reference_ranges, ...patch },
    });
  }

  function updateCodedValue(optionIndex: number, patch: Partial<LabParameter['coded_values'][number]>): void {
    onChange({
      ...parameter,
      coded_values: parameter.coded_values.map((option, itemIndex) => (
        itemIndex === optionIndex ? { ...option, ...patch } : option
      )),
    });
  }

  function removeCodedValue(optionIndex: number): void {
    onChange({
      ...parameter,
      coded_values: parameter.coded_values.filter((_, itemIndex) => itemIndex !== optionIndex),
    });
  }

  return (
    <fieldset className="laboratory-parameter">
      <legend className="laboratory-editor__group-legend">Parameter {index + 1}</legend>

      <div className="laboratory-editor__row">
        <TextInput
          id={`lab-parameter-${index}-code`}
          labelText="Parameter code"
          value={parameter.code}
          maxLength={100}
          onChange={(event) => onChange({ ...parameter, code: event.target.value })}
          invalid={Boolean(codeError)}
          invalidText={codeError}
          required
        />
        <TextInput
          id={`lab-parameter-${index}-name`}
          labelText="Parameter name"
          value={parameter.name}
          maxLength={200}
          onChange={(event) => onChange({ ...parameter, name: event.target.value })}
          invalid={Boolean(nameError)}
          invalidText={nameError}
          required
        />
      </div>

      <div className="laboratory-editor__row">
        <Select
          id={`lab-parameter-${index}-type`}
          labelText="Result type"
          value={parameter.value_type}
          onChange={(event) => onChange({
            ...parameter,
            value_type: event.target.value as LabParameter['value_type'],
          })}
          invalid={Boolean(typeError)}
          invalidText={typeError}
        >
          <SelectItem value="" text="Select result type" />
          <SelectItem value="NUMERIC" text="Numeric" />
          <SelectItem value="CODED" text="Coded choice" />
          <SelectItem value="TEXT" text="Text" />
        </Select>
        <TextInput
          id={`lab-parameter-${index}-unit`}
          labelText="Unit (optional)"
          value={parameter.unit}
          maxLength={100}
          onChange={(event) => onChange({ ...parameter, unit: event.target.value })}
          invalid={Boolean(unitError)}
          invalidText={unitError}
        />
      </div>

      {parameter.value_type === 'NUMERIC' ? (
        <div className="laboratory-editor__row">
          <TextInput
            id={`lab-parameter-${index}-low`}
            labelText="Reference low (optional)"
            type="number"
            step="any"
            value={parameter.reference_ranges.low ?? ''}
            onChange={(event) => updateReference({
              low: event.target.value === '' ? null : Number(event.target.value),
            })}
            invalid={Boolean(lowError ?? rangesError)}
            invalidText={lowError ?? rangesError}
          />
          <TextInput
            id={`lab-parameter-${index}-high`}
            labelText="Reference high (optional)"
            type="number"
            step="any"
            value={parameter.reference_ranges.high ?? ''}
            onChange={(event) => updateReference({
              high: event.target.value === '' ? null : Number(event.target.value),
            })}
            invalid={Boolean(highError ?? rangesError)}
            invalidText={highError ?? rangesError}
          />
        </div>
      ) : null}

      <div className="laboratory-editor__row">
        <TextInput
          id={`lab-parameter-${index}-reference-text`}
          labelText="Reference text (optional)"
          value={parameter.reference_ranges.text}
          maxLength={255}
          onChange={(event) => updateReference({ text: event.target.value })}
          invalid={Boolean(textError)}
          invalidText={textError}
        />
        <TextInput
          id={`lab-parameter-${index}-reference-source`}
          labelText="Reference source (optional)"
          value={parameter.reference_ranges.source}
          maxLength={255}
          onChange={(event) => updateReference({ source: event.target.value })}
          invalid={Boolean(sourceError)}
          invalidText={sourceError}
        />
      </div>

      {parameter.value_type === 'CODED' ? (
        <div className="laboratory-coded-values">
          <p className="laboratory-editor__hint">
            Result options for this coded parameter (at least one is required).
          </p>
          {parameter.coded_values.map((codedValue, optionIndex) => (
            <div className="laboratory-coded-value" key={`coded-value-${optionIndex}`}>
              <TextInput
                id={`lab-parameter-${index}-option-${optionIndex}-code`}
                labelText="Option code"
                value={codedValue.code}
                maxLength={100}
                onChange={(event) => updateCodedValue(optionIndex, { code: event.target.value })}
                invalid={Boolean(codedError)}
                invalidText={codedError}
                required
              />
              <TextInput
                id={`lab-parameter-${index}-option-${optionIndex}-name`}
                labelText="Option name"
                value={codedValue.name}
                maxLength={200}
                onChange={(event) => updateCodedValue(optionIndex, { name: event.target.value })}
                required
              />
              <Button
                type="button"
                kind="danger--ghost"
                size="sm"
                onClick={() => removeCodedValue(optionIndex)}
              >
                Remove
              </Button>
            </div>
          ))}
          <div className="laboratory-editor__actions">
            <Button
              type="button"
              kind="tertiary"
              size="sm"
              onClick={() => onChange({
                ...parameter,
                coded_values: [...parameter.coded_values, { code: '', name: '' }],
              })}
            >
              Add result option
            </Button>
          </div>
        </div>
      ) : null}

      <div className="laboratory-editor__actions">
        <Button type="button" kind="danger--ghost" size="sm" onClick={onRemove}>
          Remove parameter
        </Button>
      </div>
    </fieldset>
  );
}

export interface PanelEditorPanelProps {
  panel: LabPanel | null;
  definitions: TestDefinition[];
  onClose: () => void;
}

export function PanelEditorPanel({
  panel,
  definitions,
  onClose,
}: PanelEditorPanelProps): ReactNode {
  const isEditing = panel !== null;
  const form = useForm<PanelForm>(panel !== null ? formForPanel(panel) : EMPTY_PANEL);
  const activeTests = definitions.filter(
    (definition) => definition.active && definition.availabilityStatus === 'AVAILABLE',
  );
  const unavailableMembers = panel?.members.filter(
    (member) => !activeTests.some((definition) => definition.definitionId === member.testDefinitionId),
  ) ?? [];

  function submit(event: FormEvent): void {
    event.preventDefault();

    if (panel !== null) {
      form.transform((data) => withoutCode(data));
      form.put(`${routes.workspaces.laboratoryCatalog}/panels/${panel.panelId}`, {
        preserveScroll: true,
        onSuccess: onClose,
      });

      return;
    }

    form.post(`${routes.workspaces.laboratoryCatalog}/panels`, {
      preserveScroll: true,
      onSuccess: onClose,
    });
  }

  function toggleMember(definitionId: string, checked: boolean): void {
    form.setData(
      'member_test_ids',
      checked
        ? [...form.data.member_test_ids, definitionId]
        : form.data.member_test_ids.filter((id) => id !== definitionId),
    );
  }

  return (
    <DockedPanel
      heading={isEditing ? 'Edit panel' : 'Create panel'}
      description="Panels group active, available tests that can be ordered together."
      onClose={onClose}
      onSubmit={(event) => submit(event)}
      noValidate
      primaryLabel={form.processing ? 'Saving…' : isEditing ? 'Save panel changes' : 'Create panel'}
      primaryDisabled={form.processing || form.data.member_test_ids.length === 0}
      width="min(38rem, 100%)"
    >
      <Stack gap={5}>
        <FormErrors errors={form.errors} fields={PANEL_ERROR_FIELDS} />

        {unavailableMembers.length > 0 ? (
          <InlineNotification
            kind="warning"
            lowContrast
            title="Some panel members are no longer selectable"
            subtitle={`${unavailableMembers.map((member) => member.testCode).join(', ')} will be removed when you save this panel.`}
          />
        ) : null}

        <TextInput
          id="lab-panel-code"
          labelText="Panel code"
          helperText={isEditing ? 'Panel codes cannot change after creation.' : 'A short, unique code used on orders.'}
          value={form.data.code}
          maxLength={100}
          disabled={isEditing}
          onChange={(event) => form.setData('code', event.target.value)}
          invalid={Boolean(form.errors.code)}
          invalidText={form.errors.code}
          required
        />
        <TextInput
          id="lab-panel-name"
          labelText="Panel name"
          value={form.data.name}
          maxLength={200}
          onChange={(event) => form.setData('name', event.target.value)}
          invalid={Boolean(form.errors.name)}
          invalidText={form.errors.name}
          required
        />
        <TextInput
          id="lab-panel-category"
          labelText="Category (optional)"
          value={form.data.category}
          maxLength={100}
          onChange={(event) => form.setData('category', event.target.value)}
          invalid={Boolean(form.errors.category)}
          invalidText={form.errors.category}
        />
        <TextArea
          id="lab-panel-description"
          labelText="Description (optional)"
          value={form.data.description}
          maxLength={2000}
          rows={3}
          onChange={(event) => form.setData('description', event.target.value)}
          invalid={Boolean(form.errors.description)}
          invalidText={form.errors.description}
        />
        {isEditing ? (
          <Checkbox
            id="lab-panel-active"
            labelText="Active"
            checked={form.data.active}
            onChange={(_, { checked }) => form.setData('active', checked)}
          />
        ) : null}

        <CheckboxGroup
          legendText="Panel tests"
          helperText="Select at least one active test with available order status."
          invalid={Boolean(form.errors.member_test_ids)}
          invalidText={form.errors.member_test_ids}
        >
          {activeTests.length === 0 ? (
            <p className="laboratory-editor__hint">
              No active, available tests exist yet. Create a test before building a panel.
            </p>
          ) : activeTests.map((definition) => (
            <Checkbox
              key={definition.definitionId}
              id={`lab-panel-member-${definition.definitionId}`}
              labelText={`${definition.code} — ${definition.name}`}
              checked={form.data.member_test_ids.includes(definition.definitionId)}
              onChange={(_, { checked }) => toggleMember(definition.definitionId, checked)}
            />
          ))}
        </CheckboxGroup>
      </Stack>
    </DockedPanel>
  );
}

export interface MethodEditorPanelProps {
  definition: TestDefinition;
  method: LabTestMethod | null;
  inventoryItems: InventoryItem[];
  onClose: () => void;
}

export function MethodEditorPanel({
  definition,
  method,
  inventoryItems,
  onClose,
}: MethodEditorPanelProps): ReactNode {
  const isEditing = method !== null;
  const form = useForm<MethodForm>(method !== null ? formForMethod(method) : EMPTY_METHOD);

  function submit(event: FormEvent): void {
    event.preventDefault();

    if (method !== null) {
      form.transform((data) => withoutCode(data));
      form.put(routes.workspaces.laboratoryTestMethod(definition.definitionId, method.methodId), {
        preserveScroll: true,
        onSuccess: onClose,
      });

      return;
    }

    form.post(routes.workspaces.laboratoryTestMethods(definition.definitionId), {
      preserveScroll: true,
      onSuccess: onClose,
    });
  }

  function updateRequirement(index: number, patch: Partial<RequirementForm>): void {
    form.setData('requirements', form.data.requirements.map((requirement, itemIndex) => (
      itemIndex === index ? { ...requirement, ...patch } : requirement
    )));
  }

  function removeRequirement(index: number): void {
    form.setData('requirements', form.data.requirements.filter((_, itemIndex) => itemIndex !== index));
  }

  return (
    <DockedPanel
      heading={isEditing ? 'Edit test method' : 'Add test method'}
      description={`${definition.code} — ${definition.name}`}
      onClose={onClose}
      onSubmit={(event) => submit(event)}
      noValidate
      primaryLabel={form.processing ? 'Saving…' : isEditing ? 'Save method changes' : 'Create method'}
      primaryDisabled={form.processing}
      width="min(42rem, 100%)"
      expandedWidth="min(56rem, 100%)"
      expandable
    >
      <Stack gap={5}>
        <FormErrors errors={form.errors} fields={METHOD_ERROR_FIELDS} />

        <TextInput
          id="lab-method-code"
          labelText="Method code"
          helperText={isEditing ? 'Method codes cannot change after creation.' : 'A short, unique code for this method.'}
          value={form.data.code}
          maxLength={100}
          disabled={isEditing}
          onChange={(event) => form.setData('code', event.target.value)}
          invalid={Boolean(form.errors.code)}
          invalidText={form.errors.code}
          required
        />
        <TextInput
          id="lab-method-name"
          labelText="Method name"
          value={form.data.name}
          maxLength={200}
          onChange={(event) => form.setData('name', event.target.value)}
          invalid={Boolean(form.errors.name)}
          invalidText={form.errors.name}
          required
        />
        <TextArea
          id="lab-method-description"
          labelText="Description (optional)"
          value={form.data.description}
          maxLength={2000}
          rows={3}
          onChange={(event) => form.setData('description', event.target.value)}
          invalid={Boolean(form.errors.description)}
          invalidText={form.errors.description}
        />
        <div className="laboratory-editor__row">
          <TextInput
            id="lab-method-specimen"
            labelText="Method-specific specimen type (optional)"
            value={form.data.specimen_type}
            maxLength={100}
            onChange={(event) => form.setData('specimen_type', event.target.value)}
            invalid={Boolean(form.errors.specimen_type)}
            invalidText={form.errors.specimen_type}
          />
          <TextInput
            id="lab-method-container"
            labelText="Method-specific container type (optional)"
            value={form.data.container_type}
            maxLength={100}
            onChange={(event) => form.setData('container_type', event.target.value)}
            invalid={Boolean(form.errors.container_type)}
            invalidText={form.errors.container_type}
          />
        </div>
        {isEditing ? (
          <Checkbox
            id="lab-method-active"
            labelText="Active"
            checked={form.data.active}
            onChange={(_, { checked }) => form.setData('active', checked)}
          />
        ) : null}

        <fieldset className="laboratory-editor__group">
          <legend className="laboratory-editor__group-legend">Inventory materials</legend>
          <p className="laboratory-editor__hint">
            Record the base-item quantities this method consumes. No stock is reserved or consumed
            by saving a method.
          </p>
          {inventoryItems.length === 0 ? (
            <p className="laboratory-editor__hint">
              No active supplies or consumables are available to attach.
            </p>
          ) : null}
          {form.data.requirements.map((requirement, index) => (
            <RequirementEditor
              key={`requirement-${index}`}
              index={index}
              requirement={requirement}
              inventoryItems={inventoryItems}
              errors={form.errors}
              onChange={(patch) => updateRequirement(index, patch)}
              onRemove={() => removeRequirement(index)}
            />
          ))}
          <div className="laboratory-editor__actions">
            <Button
              type="button"
              kind="tertiary"
              size="sm"
              disabled={inventoryItems.length === 0}
              onClick={() => form.setData('requirements', [...form.data.requirements, newRequirement()])}
            >
              Add material requirement
            </Button>
          </div>
        </fieldset>
      </Stack>
    </DockedPanel>
  );
}

function RequirementEditor({
  index,
  requirement,
  inventoryItems,
  errors,
  onChange,
  onRemove,
}: {
  index: number;
  requirement: RequirementForm;
  inventoryItems: InventoryItem[];
  errors: Record<string, string>;
  onChange: (patch: Partial<RequirementForm>) => void;
  onRemove: () => void;
}): ReactNode {
  const prefix = `requirements.${index}`;
  const itemError = errors[`${prefix}.inventory_item_id`];
  const roleError = errors[`${prefix}.role`];
  const scopeError = errors[`${prefix}.usage_scope`];
  const quantityError = errors[`${prefix}.quantity`];
  const selectedItem = inventoryItems.find((item) => item.itemId === requirement.inventory_item_id);
  const selectedItemLabel = selectedItem
    ? `${selectedItem.code} — ${selectedItem.name}`
    : `Material ${index + 1}`;

  return (
    <div className="laboratory-requirement">
      <div className="laboratory-requirement__head">
        <strong>{selectedItemLabel}</strong>
        <Button type="button" kind="danger--ghost" size="sm" onClick={onRemove}>
          Remove
        </Button>
      </div>
      <Select
        id={`lab-requirement-${index}-item`}
        labelText="Inventory item"
        value={requirement.inventory_item_id}
        onChange={(event) => onChange({ inventory_item_id: event.target.value })}
        invalid={Boolean(itemError)}
        invalidText={itemError}
      >
        <SelectItem value="" text="Select an active supply or consumable" />
        {inventoryItems.map((item) => (
          <SelectItem
            key={item.itemId}
            value={item.itemId}
            text={`${item.code} — ${item.name} (${item.itemType}${item.category ? ` · ${item.category}` : ''}; ${item.uom})`}
          />
        ))}
      </Select>
      <div className="laboratory-editor__row">
        <Select
          id={`lab-requirement-${index}-role`}
          labelText="Requirement role"
          value={requirement.role}
          onChange={(event) => onChange({ role: event.target.value })}
          invalid={Boolean(roleError)}
          invalidText={roleError}
        >
          {REQUIREMENT_ROLES.map((role) => (
            <SelectItem key={role} value={role} text={roleLabel(role)} />
          ))}
        </Select>
        <Select
          id={`lab-requirement-${index}-scope`}
          labelText="Quantity basis"
          value={requirement.usage_scope}
          onChange={(event) => onChange({ usage_scope: event.target.value })}
          invalid={Boolean(scopeError)}
          invalidText={scopeError}
        >
          {USAGE_SCOPES.map((scope) => (
            <SelectItem key={scope.value} value={scope.value} text={scope.text} />
          ))}
        </Select>
      </div>
      <div className="laboratory-editor__row">
        <TextInput
          id={`lab-requirement-${index}-quantity`}
          labelText={`Quantity (${selectedItem?.uom ?? 'item unit'})`}
          type="number"
          min="0.0001"
          max="99999999999999.9999"
          step="0.0001"
          value={requirement.quantity}
          onChange={(event) => onChange({ quantity: event.target.value })}
          invalid={Boolean(quantityError)}
          invalidText={quantityError}
          required
        />
        <div className="laboratory-requirement__required">
          <Checkbox
            id={`lab-requirement-${index}-required`}
            labelText="Required for this method"
            checked={requirement.required}
            onChange={(_, { checked }) => onChange({ required: checked })}
          />
        </div>
      </div>
    </div>
  );
}

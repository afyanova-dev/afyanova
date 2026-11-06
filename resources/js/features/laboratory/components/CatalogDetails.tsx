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
import type { ReactNode } from 'react';
import { Add } from '@/icons';
import type { LabPanel, LabTestMethod, TestDefinition } from '../types';
import {
  availabilityLabel,
  availabilityTone,
  countLabel,
  materialSummary,
  parameterOptions,
  parameterReference,
  parameterTypeLabel,
} from '../utils';

export interface TestDetailsProps {
  definition: TestDefinition;
  methods: LabTestMethod[];
  canManageCatalog: boolean;
  onAddMethod: () => void;
  onEditMethod: (method: LabTestMethod) => void;
}

export function TestDetails({
  definition,
  methods,
  canManageCatalog,
  onAddMethod,
  onEditMethod,
}: TestDetailsProps): ReactNode {
  const parameters = definition.parameters ?? [];

  return (
    <div className="laboratory-details">
      {definition.description ? (
        <p className="laboratory-details__description">{definition.description}</p>
      ) : null}

      <dl className="laboratory-details__meta">
        <div>
          <dt>Category</dt>
          <dd>{definition.category ?? 'Not categorized'}</dd>
        </div>
        <div>
          <dt>Specimen</dt>
          <dd>{definition.specimenType ?? 'Not specified'}</dd>
        </div>
        <div>
          <dt>Container</dt>
          <dd>{definition.containerType ?? 'Not specified'}</dd>
        </div>
        <div>
          <dt>Required capability</dt>
          <dd>{definition.requiredCapabilityCode ?? 'None'}</dd>
        </div>
        <div>
          <dt>Turnaround target</dt>
          <dd>
            {definition.turnaroundTargetMinutes !== null
              ? `${definition.turnaroundTargetMinutes} minutes`
              : 'Not set'}
          </dd>
        </div>
        <div>
          <dt>Catalog state</dt>
          <dd className="laboratory-details__tags">
            <Tag type={definition.active ? 'green' : 'gray'}>
              {definition.active ? 'Active' : 'Inactive'}
            </Tag>
            <Tag type={availabilityTone(definition.availabilityStatus)}>
              {availabilityLabel(definition.availabilityStatus)}
            </Tag>
            <span>Version {definition.version}</span>
          </dd>
        </div>
      </dl>

      <section className="laboratory-details__section" aria-label="Result parameters">
        <div className="laboratory-details__section-heading">
          <h4>Result parameters</h4>
          <span>{countLabel(parameters.length, 'parameter', 'parameters')}</span>
        </div>
        {parameters.length === 0 ? (
          <p className="laboratory-editor__hint">No result parameters are configured.</p>
        ) : (
          <Table size="sm" aria-label={`Result parameters for ${definition.name}`}>
            <TableHead>
              <TableRow>
                <TableHeader>Parameter</TableHeader>
                <TableHeader>Type</TableHeader>
                <TableHeader>Unit</TableHeader>
                <TableHeader>Reference</TableHeader>
                <TableHeader>Options</TableHeader>
              </TableRow>
            </TableHead>
            <TableBody>
              {parameters.map((parameter, index) => (
                <TableRow key={`${parameter.code}-${index}`}>
                  <TableCell>
                    <strong>{parameter.name}</strong>
                    <span className="laboratory-details__cell-code">{parameter.code}</span>
                  </TableCell>
                  <TableCell>{parameterTypeLabel(parameter.value_type)}</TableCell>
                  <TableCell>{parameter.unit || '—'}</TableCell>
                  <TableCell>{parameterReference(parameter)}</TableCell>
                  <TableCell>{parameterOptions(parameter)}</TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        )}
      </section>

      <section className="laboratory-details__section" aria-label="Test methods and materials">
        <div className="laboratory-details__section-heading">
          <h4>Methods and materials</h4>
          <div className="laboratory-details__section-actions">
            <span>{countLabel(methods.length, 'method', 'methods')}</span>
            {canManageCatalog ? (
              <Button size="sm" kind="tertiary" renderIcon={Add} onClick={onAddMethod}>
                Add method
              </Button>
            ) : null}
          </div>
        </div>
        {methods.length === 0 ? (
          <p className="laboratory-editor__hint">
            No methods are configured. Add a method to record how this test is performed and which
            inventory items it consumes.
          </p>
        ) : (
          <div className="laboratory-method-list">
            {methods.map((method) => (
              <article className="laboratory-method" key={method.methodId}>
                <div className="laboratory-method__head">
                  <div className="laboratory-method__identity">
                    <strong>{method.name}</strong>
                    <span>{method.code}</span>
                  </div>
                  <div className="laboratory-details__tags">
                    <Tag type={method.active ? 'green' : 'gray'}>
                      {method.active ? 'Active' : 'Inactive'}
                    </Tag>
                    <span>Version {method.version}</span>
                    {canManageCatalog ? (
                      <Button
                        size="sm"
                        kind="ghost"
                        aria-label={`Edit method ${method.name}`}
                        onClick={() => onEditMethod(method)}
                      >
                        Edit method
                      </Button>
                    ) : null}
                  </div>
                </div>
                {method.description ? (
                  <p className="laboratory-method__description">{method.description}</p>
                ) : null}
                {method.specimenType || method.containerType ? (
                  <p className="laboratory-method__description">
                    {[method.specimenType, method.containerType].filter(Boolean).join(' · ')}
                  </p>
                ) : null}
                {method.requirements.length === 0 ? (
                  <p className="laboratory-method__materials">No material requirements configured.</p>
                ) : (
                  <ul className="laboratory-method__materials" aria-label={`Materials for ${method.name}`}>
                    {materialSummary(method).split('\n').map((line, index) => (
                      <li key={`${method.methodId}-material-${index}`}>{line}</li>
                    ))}
                  </ul>
                )}
              </article>
            ))}
          </div>
        )}
      </section>
    </div>
  );
}

export interface PanelDetailsProps {
  panel: LabPanel;
}

export function PanelDetails({ panel }: PanelDetailsProps): ReactNode {
  return (
    <div className="laboratory-details">
      {panel.description ? (
        <p className="laboratory-details__description">{panel.description}</p>
      ) : null}

      <dl className="laboratory-details__meta">
        <div>
          <dt>Category</dt>
          <dd>{panel.category ?? 'Not categorized'}</dd>
        </div>
        <div>
          <dt>Tests in panel</dt>
          <dd>{countLabel(panel.members.length, 'test', 'tests')}</dd>
        </div>
        <div>
          <dt>Version</dt>
          <dd>{panel.version}</dd>
        </div>
        <div>
          <dt>Catalog state</dt>
          <dd className="laboratory-details__tags">
            <Tag type={panel.active ? 'green' : 'gray'}>
              {panel.active ? 'Active' : 'Inactive'}
            </Tag>
          </dd>
        </div>
      </dl>

      <section className="laboratory-details__section" aria-label="Panel member tests">
        <div className="laboratory-details__section-heading">
          <h4>Panel member tests</h4>
          <span>{countLabel(panel.members.length, 'test', 'tests')}</span>
        </div>
        {panel.members.length === 0 ? (
          <p className="laboratory-editor__hint">This panel has no member tests.</p>
        ) : (
          <Table size="sm" aria-label={`Member tests for ${panel.name}`}>
            <TableHead>
              <TableRow>
                <TableHeader>Sequence</TableHeader>
                <TableHeader>Test</TableHeader>
                <TableHeader>Code</TableHeader>
              </TableRow>
            </TableHead>
            <TableBody>
              {[...panel.members]
                .sort((left, right) => left.sequence - right.sequence)
                .map((member) => (
                  <TableRow key={member.testDefinitionId}>
                    <TableCell>{member.sequence}</TableCell>
                    <TableCell>{member.testName}</TableCell>
                    <TableCell>{member.testCode}</TableCell>
                  </TableRow>
                ))}
            </TableBody>
          </Table>
        )}
      </section>
    </div>
  );
}

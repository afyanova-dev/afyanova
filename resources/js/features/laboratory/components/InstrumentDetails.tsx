import { Tag } from '@carbon/react';
import type { ReactNode } from 'react';
import type { FacilityInstrument } from '../types';
import { countLabel, instrumentStatusLabel, instrumentStatusTone } from '../utils';

export interface InstrumentDetailsProps {
  instrument: FacilityInstrument;
}

export function InstrumentDetails({ instrument }: InstrumentDetailsProps): ReactNode {
  return (
    <div className="laboratory-details">
      <dl className="laboratory-details__meta">
        <div>
          <dt>Manufacturer</dt>
          <dd>{instrument.manufacturer ?? 'Not recorded'}</dd>
        </div>
        <div>
          <dt>Model</dt>
          <dd>{instrument.model ?? 'Not recorded'}</dd>
        </div>
        <div>
          <dt>Serial number</dt>
          <dd>{instrument.serialNumber ?? 'Not recorded'}</dd>
        </div>
        <div>
          <dt>Inventory equipment</dt>
          <dd>
            {instrument.inventoryCode && instrument.inventoryName
              ? `${instrument.inventoryCode} — ${instrument.inventoryName}`
              : 'Not linked'}
          </dd>
        </div>
        <div>
          <dt>Operational status</dt>
          <dd className="laboratory-details__tags">
            <Tag type={instrumentStatusTone(instrument.status)}>
              {instrumentStatusLabel(instrument.status)}
            </Tag>
            <span>Version {instrument.version}</span>
          </dd>
        </div>
      </dl>

      <section className="laboratory-details__section" aria-label="Supported test methods">
        <div className="laboratory-details__section-heading">
          <h4>Supported test methods</h4>
          <span>{countLabel(instrument.supportedMethods.length, 'method', 'methods')}</span>
        </div>
        {instrument.supportedMethods.length === 0 ? (
          <p className="laboratory-editor__hint">
            No active test methods are assigned to this instrument.
          </p>
        ) : (
          <ul className="laboratory-method-list laboratory-method-list--compact">
            {instrument.supportedMethods.map((method) => (
              <li className="laboratory-method" key={method.methodId}>
                <div className="laboratory-method__identity">
                  <strong>{method.methodName}</strong>
                  <span>{method.methodCode} · Version {method.methodVersion}</span>
                </div>
              </li>
            ))}
          </ul>
        )}
      </section>
    </div>
  );
}

import { Link, useForm } from '@inertiajs/react';
import { useState } from 'react';
import type { FormEvent, ReactNode } from 'react';
import { routes } from '@/app/routes';
import { PageHeader } from '@/components/ui';
import { AppLayout } from '@/layouts/AppLayout/AppLayout';

interface QueueItem {
  orderId: string;
  orderNumber: string;
  priority: string;
  orderItemId: string;
  testDefinitionId: string;
  testCode: string;
  testName: string;
  acceptedSpecimens: AcceptedSpecimen[];
  executionId: string | null;
}

interface AcceptedSpecimen {
  specimenId: string;
  accessionNumber: string;
  specimenType: string;
  containerType: string | null;
}

interface MethodRequirement {
  requirementId: string;
  itemCode: string;
  itemName: string;
  required: boolean;
}

interface TestMethod {
  methodId: string;
  testDefinitionId: string;
  code: string;
  name: string;
  specimenType: string | null;
  containerType: string | null;
  active: boolean;
  version: number;
  requirements: MethodRequirement[];
}

interface SupportedMethod {
  methodId: string;
  methodVersion: number;
}

interface FacilityInstrument {
  instrumentId: string;
  identifier: string;
  name: string;
  status: string;
  supportedMethods: SupportedMethod[];
}

interface StockLocation {
  locationId: string;
  code: string;
  name: string;
}

interface ExecutionWorklistProps {
  queue: QueueItem[];
  methods: TestMethod[];
  instruments: FacilityInstrument[];
  stockLocations: StockLocation[];
  canViewStockLocations: boolean;
}

export default function ExecutionWorklist({
  queue,
  methods,
  instruments,
}: ExecutionWorklistProps): ReactNode {
  return (
    <AppLayout>
      <div className="afyanova-page">
        <PageHeader
          title="Laboratory test execution"
          description="Start an execution only after specimen acceptance. Method and instrument selections are snapshotted for the run."
        />
        <div className="afy-page__content space-y-6">
          <div className="flex flex-wrap gap-3">
            <Link className="afy-button afy-button--secondary" href={routes.workspaces.laboratoryCatalog}>
              Tests and methods
            </Link>
            <Link className="afy-button afy-button--secondary" href={routes.workspaces.laboratoryInstruments}>
              Facility instruments
            </Link>
          </div>
          {queue.length === 0 ? (
            <p role="status">There are no accepted specimens awaiting test execution.</p>
          ) : (
            <ul className="grid gap-4">
              {queue.map((item) => (
                <li key={item.orderItemId} className="rounded border p-4">
                  <div className="mb-4">
                    <h2 className="text-lg font-semibold">{item.testCode} — {item.testName}</h2>
                    <p>Order {item.orderNumber} · {item.priority}</p>
                  </div>
                  {item.executionId ? (
                    <Link
                      className="afy-button afy-button--primary"
                      href={routes.workspaces.laboratoryExecution(item.executionId)}
                    >
                      Continue execution
                    </Link>
                  ) : (
                    <StartExecutionForm
                      item={item}
                      methods={methods.filter((method) => method.testDefinitionId === item.testDefinitionId)}
                      instruments={instruments}
                    />
                  )}
                </li>
              ))}
            </ul>
          )}
        </div>
      </div>
    </AppLayout>
  );
}

function StartExecutionForm({
  item,
  methods,
  instruments,
}: {
  item: QueueItem;
  methods: TestMethod[];
  instruments: FacilityInstrument[];
}): ReactNode {
  const [executionId] = useState(() => globalThis.crypto.randomUUID());
  const form = useForm<{
    method_id: string;
    instrument_id: string;
    execution_id: string;
    specimen_id: string;
    order_item_id?: string;
  }>({
    method_id: '',
    instrument_id: '',
    execution_id: executionId,
    specimen_id: '',
  });
  const availableInstruments = instruments.filter((instrument) => instrument.supportedMethods.some(
    (supported) => supported.methodId === form.data.method_id
      && supported.methodVersion === methods.find((method) => method.methodId === form.data.method_id)?.version,
  ));
  const selectedMethod = methods.find((method) => method.methodId === form.data.method_id);
  const specimenOptions = item.acceptedSpecimens.filter((specimen) => (
    (selectedMethod?.specimenType == null
      || selectedMethod.specimenType.trim().toUpperCase() === specimen.specimenType.trim().toUpperCase())
    && (selectedMethod?.containerType == null
      || selectedMethod.containerType.trim().toUpperCase() === specimen.containerType?.trim().toUpperCase())
  ));

  function submit(event: FormEvent<HTMLFormElement>): void {
    event.preventDefault();
    form.post(routes.workspaces.laboratoryExecutionStart(item.orderId, item.orderItemId), {
      preserveScroll: true,
    });
  }

  if (methods.length === 0) {
    return <p>No active method is configured for this test. Configure one before starting execution.</p>;
  }

  return (
    <form className="grid max-w-2xl gap-3" onSubmit={submit}>
      <label className="grid gap-1">
        Test method
        <select
          required
          value={form.data.method_id}
          onChange={(event) => form.setData({ ...form.data, method_id: event.target.value, instrument_id: '' })}
        >
          <option value="">Select a method</option>
          {methods.map((method) => (
            <option key={method.methodId} value={method.methodId}>
              {method.code} — {method.name}
            </option>
          ))}
        </select>
      </label>
      {form.errors.method_id ? <p role="alert">{form.errors.method_id}</p> : null}
      <label className="grid gap-1">
        Accepted specimen
        <select
          required
          value={form.data.specimen_id}
          onChange={(event) => form.setData('specimen_id', event.target.value)}
          disabled={!form.data.method_id}
        >
          <option value="">Select an accepted specimen</option>
          {specimenOptions.map((specimen) => (
            <option key={specimen.specimenId} value={specimen.specimenId}>
              {specimen.accessionNumber} — {specimen.specimenType}
            </option>
          ))}
        </select>
      </label>
      {specimenOptions.length === 0 && form.data.method_id ? (
        <p role="alert">No accepted specimen matches this method. Receive and accept a compatible specimen first.</p>
      ) : null}
      {form.errors.specimen_id ? <p role="alert">{form.errors.specimen_id}</p> : null}
      <label className="grid gap-1">
        Facility instrument (optional for manual methods)
        <select
          value={form.data.instrument_id}
          onChange={(event) => form.setData('instrument_id', event.target.value)}
          disabled={!form.data.method_id}
        >
          <option value="">No instrument / manual method</option>
          {availableInstruments.map((instrument) => (
            <option key={instrument.instrumentId} value={instrument.instrumentId}>
              {instrument.identifier} — {instrument.name}
            </option>
          ))}
        </select>
      </label>
      {form.errors.instrument_id ? <p role="alert">{form.errors.instrument_id}</p> : null}
      {form.data.method_id ? (
        <p>
          Configured materials: {selectedMethod?.requirements.length ?? 0}
          {' '}· posting actual use requires Inventory consumption permission.
        </p>
      ) : null}
      <button className="afy-button afy-button--primary w-fit" type="submit" disabled={form.processing || !form.data.method_id || specimenOptions.length === 0}>
        {form.processing ? 'Starting…' : 'Start test execution'}
      </button>
      {form.errors.order_item_id ? <p role="alert">{form.errors.order_item_id}</p> : null}
      {form.errors.execution_id ? <p role="alert">{form.errors.execution_id}</p> : null}
    </form>
  );
}

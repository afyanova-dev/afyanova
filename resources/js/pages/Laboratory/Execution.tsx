import { Link, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import type { FormEvent, ReactNode } from 'react';
import { routes } from '@/app/routes';
import { PageHeader } from '@/components/ui';
import { AppLayout } from '@/layouts/AppLayout/AppLayout';
import type { SharedProps } from '@/types/auth';

interface MaterialUse {
  useId: string;
  locationId: string;
  batchNumber: string | null;
  quantity: string;
  status: string;
  failureMessage: string | null;
  consumedAt: string | null;
}

interface MaterialRequirement {
  requirementId: string;
  inventoryItemId: string;
  itemCode: string;
  itemName: string;
  itemUom: string;
  role: string;
  required: boolean;
  usageScope: string;
  requiredQuantity: string;
  consumedQuantity: string;
  remainingQuantity: string;
  uses: MaterialUse[];
}

interface Execution {
  executionId: string;
  orderNumber: string;
  testCode: string;
  testName: string;
  attemptNumber: number;
  methodCode: string;
  methodName: string;
  methodVersion: number;
  instrumentIdentifier: string | null;
  status: string;
  startedAt: string;
  completedAt: string | null;
  abortedAt: string | null;
  abortReason: string | null;
  materials: MaterialRequirement[];
}

interface StockLocation {
  locationId: string;
  code: string;
  name: string;
}

interface ExecutionProps {
  execution: Execution;
  stockLocations: StockLocation[];
  canViewStockLocations: boolean;
}

export default function LaboratoryExecution({
  execution,
  stockLocations,
  canViewStockLocations,
}: ExecutionProps): ReactNode {
  const { props: shared } = usePage<SharedProps>();
  const transition = useForm({ reason: '' });
  const complete = useForm<{ materials?: string }>({});
  const inProgress = execution.status === 'IN_PROGRESS';

  function abortExecution(event: FormEvent<HTMLFormElement>): void {
    event.preventDefault();
    transition.post(routes.workspaces.laboratoryExecutionAbort(execution.executionId), {
      preserveScroll: true,
    });
  }

  return (
    <AppLayout>
      <div className="afyanova-page">
        <PageHeader
          title={`Execution: ${execution.testCode}`}
          description={`${execution.testName} · order ${execution.orderNumber} · attempt ${execution.attemptNumber}`}
        />
        <div className="afy-page__content space-y-6">
          <div className="flex flex-wrap gap-3">
            <Link className="afy-button afy-button--secondary" href={routes.workspaces.laboratoryExecutions}>
              Back to execution queue
            </Link>
          </div>
          {typeof shared.flash?.status === 'string' ? <p role="status">{shared.flash.status}</p> : null}
          <section className="rounded border p-4">
            <h2 className="text-lg font-semibold">Recorded method and instrument</h2>
            <p>{execution.methodCode} — {execution.methodName} · method version {execution.methodVersion}</p>
            <p>Instrument: {execution.instrumentIdentifier ?? 'Manual / no instrument recorded'}</p>
            <p>Status: {execution.status.replaceAll('_', ' ')}</p>
            {execution.abortReason ? <p>Stop reason: {execution.abortReason}</p> : null}
          </section>

          <section className="space-y-4">
            <h2 className="text-xl font-semibold">Method material requirements and actual use</h2>
            {execution.materials.length === 0 ? (
              <p>This method has no configured Inventory material requirements.</p>
            ) : null}
            {execution.materials.map((material) => (
              <MaterialUsePanel
                key={`${material.requirementId}:${material.uses.at(-1)?.useId ?? 'new'}:${material.uses.at(-1)?.status ?? ''}`}
                executionId={execution.executionId}
                material={material}
                stockLocations={stockLocations}
                canViewStockLocations={canViewStockLocations}
                canRecord={inProgress}
              />
            ))}
          </section>

          {inProgress ? (
            <section className="grid gap-4 border-t pt-4">
              <div>
                <button
                  type="button"
                  className="afy-button afy-button--primary"
                  disabled={complete.processing}
                  onClick={() => complete.post(routes.workspaces.laboratoryExecutionComplete(execution.executionId), {
                    preserveScroll: true,
                  })}
                >
                  {complete.processing ? 'Completing…' : 'Complete execution'}
                </button>
                {complete.errors.materials ? <p role="alert">{complete.errors.materials}</p> : null}
              </div>
              <form className="grid max-w-xl gap-3" onSubmit={abortExecution}>
                <label className="grid gap-1">
                  Stop or cancel this execution — reason required
                  <textarea
                    required
                    maxLength={1000}
                    value={transition.data.reason}
                    onChange={(event) => transition.setData('reason', event.target.value)}
                  />
                </label>
                {transition.errors.reason ? <p role="alert">{transition.errors.reason}</p> : null}
                <p>Consumed Inventory is not automatically restored when an execution is stopped.</p>
                <button className="afy-button afy-button--secondary w-fit" type="submit" disabled={transition.processing}>
                  {transition.processing ? 'Stopping…' : 'Stop execution'}
                </button>
              </form>
            </section>
          ) : null}
        </div>
      </div>
    </AppLayout>
  );
}

function MaterialUsePanel({
  executionId,
  material,
  stockLocations,
  canViewStockLocations,
  canRecord,
}: {
  executionId: string;
  material: MaterialRequirement;
  stockLocations: StockLocation[];
  canViewStockLocations: boolean;
  canRecord: boolean;
}): ReactNode {
  const hasPendingUse = material.uses.some((use) => use.status === 'PENDING');
  const hasMetRequirement = !material.required || Number(material.remainingQuantity) <= 0;

  return (
    <article className="rounded border p-4">
      <h3 className="font-semibold">{material.itemCode} — {material.itemName}</h3>
      <p>{material.role.replaceAll('_', ' ')} · {material.required ? 'Required' : 'Optional'} · {material.usageScope.replaceAll('_', ' ')}</p>
      <p>
        Required: {material.requiredQuantity} {material.itemUom}
        {' · '}Consumed: {material.consumedQuantity} {material.itemUom}
        {' · '}Remaining: {material.remainingQuantity} {material.itemUom}
      </p>
      {material.uses.map((use) => (
        <div key={use.useId} className="mt-2 text-sm">
          <p>
            Attempt: {use.status.replaceAll('_', ' ')} · {use.quantity} {material.itemUom}
            {use.batchNumber ? ` · Batch ${use.batchNumber}` : ''}
          </p>
          {use.failureMessage ? <p role="alert">{use.failureMessage}</p> : null}
        </div>
      ))}
      {canRecord && (!material.required || !hasMetRequirement || hasPendingUse) ? (
        <MaterialUseForm
          executionId={executionId}
          material={material}
          previousUse={hasPendingUse ? material.uses.find((use) => use.status === 'PENDING') ?? null : null}
          stockLocations={stockLocations}
          canViewStockLocations={canViewStockLocations}
        />
      ) : null}
    </article>
  );
}

function MaterialUseForm({
  executionId,
  material,
  previousUse,
  stockLocations,
  canViewStockLocations,
}: {
  executionId: string;
  material: MaterialRequirement;
  previousUse: MaterialUse | null;
  stockLocations: StockLocation[];
  canViewStockLocations: boolean;
}): ReactNode {
  const [useId] = useState(() => previousUse?.useId ?? globalThis.crypto.randomUUID());
  const form = useForm<{
    use_id: string;
    location_id: string;
    quantity: string;
    batch_number: string;
    material?: string;
  }>({
    use_id: useId,
    location_id: previousUse?.locationId ?? '',
    quantity: previousUse?.quantity ?? material.remainingQuantity,
    batch_number: previousUse?.batchNumber ?? '',
  });
  const isRetry = previousUse?.status === 'PENDING';

  function submit(event: FormEvent<HTMLFormElement>): void {
    event.preventDefault();
    form.post(routes.workspaces.laboratoryExecutionMaterialUses(executionId, material.requirementId), {
      preserveScroll: true,
    });
  }

  return (
    <form className="mt-4 grid max-w-xl gap-3" onSubmit={submit}>
      {isRetry ? (
        <p role="status">
          This Inventory movement is pending confirmation. Retry uses the saved quantity, location, and batch without creating a second movement.
        </p>
      ) : null}
      {canViewStockLocations ? (
        <label className="grid gap-1">
          Stock location
          <select
            required
            value={form.data.location_id}
            onChange={(event) => form.setData('location_id', event.target.value)}
            disabled={isRetry}
          >
            <option value="">Select a facility stock location</option>
            {stockLocations.map((location) => (
              <option key={location.locationId} value={location.locationId}>
                {location.code} — {location.name}
              </option>
            ))}
            {previousUse && !stockLocations.some((location) => location.locationId === previousUse.locationId) ? (
              <option value={previousUse.locationId}>{previousUse.locationId}</option>
            ) : null}
          </select>
        </label>
      ) : (
        <label className="grid gap-1">
          Stock location ID
          <input
            required
            value={form.data.location_id}
            onChange={(event) => form.setData('location_id', event.target.value)}
            disabled={isRetry}
          />
        </label>
      )}
      {!canViewStockLocations ? (
        <p role="status">Inventory location lookup requires inventory.access. Material posting separately requires inventory.consumption.record.</p>
      ) : null}
      <label className="grid gap-1">
        Actual quantity used ({material.itemUom})
        <input
          required
          type="number"
          min="0.0001"
          step="0.0001"
          value={form.data.quantity}
          onChange={(event) => form.setData('quantity', event.target.value)}
          disabled={isRetry}
        />
      </label>
      <label className="grid gap-1">
        Batch number (required for batch-tracked items)
        <input
          maxLength={100}
          value={form.data.batch_number}
          onChange={(event) => form.setData('batch_number', event.target.value)}
          disabled={isRetry}
        />
      </label>
      {form.errors.material ? <p role="alert">{form.errors.material}</p> : null}
      {form.errors.location_id ? <p role="alert">{form.errors.location_id}</p> : null}
      {form.errors.quantity ? <p role="alert">{form.errors.quantity}</p> : null}
      {form.errors.batch_number ? <p role="alert">{form.errors.batch_number}</p> : null}
      <button className="afy-button afy-button--secondary w-fit" type="submit" disabled={form.processing}>
        {form.processing ? 'Recording…' : isRetry ? 'Retry pending Inventory movement' : 'Record actual material use'}
      </button>
    </form>
  );
}

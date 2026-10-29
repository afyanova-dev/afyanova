import { fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ProcedureQueueRoutingAction } from './ProcedureQueueRoutingAction';

const actionState = vi.hoisted(() => ({
  put: vi.fn(),
}));

vi.mock('@inertiajs/react', () => ({
  router: { put: actionState.put },
}));

vi.mock('@/lib/authorization', () => ({
  permissions: {
    queue: { definitionManage: 'queue.definition.manage' },
    clinical: { proceduresRead: 'clinical.procedures.read' },
  },
  useCan: () => () => true,
}));

beforeEach(() => {
  globalThis.ResizeObserver ??= class {
    observe(): void {}
    unobserve(): void {}
    disconnect(): void {}
  };
  actionState.put.mockReset();
  actionState.put.mockImplementation((_url, _data, options) => {
    options?.onSuccess?.();
    options?.onFinish?.();
  });
});

describe('ProcedureQueueRoutingAction', () => {
  it('lets authorized queue administrators map a procedure to a service queue', () => {
    render(
      <ProcedureQueueRoutingAction
        routing={{
          definitions: [{
            definitionId: 'definition-dressing',
            code: 'DRESSING',
            name: 'Wound Dressing',
            queueId: '',
          }],
          queues: [{
            queueId: 'queue-procedure',
            queue: 'Procedure Room',
            code: 'PROC',
          }],
        }}
      />,
    );

    fireEvent.click(screen.getByRole('button', { name: 'Configure procedure queues' }));
    fireEvent.change(screen.getByLabelText('Wound Dressing (DRESSING)'), {
      target: { value: 'queue-procedure' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Save routing' }));

    expect(actionState.put).toHaveBeenCalledWith(
      '/workspaces/service-queues/procedure-routing',
      {
        mappings: [{
          definition_id: 'definition-dressing',
          queue_id: 'queue-procedure',
        }],
      },
      expect.objectContaining({ preserveScroll: true }),
    );
  });
});

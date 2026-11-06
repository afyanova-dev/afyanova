import type { ReactNode } from 'react';
import { PageHeader } from '@/components/ui';
import {
  QueueBoard,
  type QueueBoardProps,
} from '@/features/service-queues';
import { AppLayout } from '@/layouts/AppLayout';

export default function ServiceQueuesIndex(props: QueueBoardProps): ReactNode {
  return (
    <AppLayout>
      <PageHeader
        title="Patient Flow"
        description="Monitor queue activity and move patients through care."
      />
      <QueueBoard {...props} />
    </AppLayout>
  );
}

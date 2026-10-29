import { InlineNotification } from '@carbon/react';
import type { ReactNode } from 'react';

export function ErrorState({ title, message }: { title: string; message?: string }): ReactNode {
  return (
    <div role="alert">
      <InlineNotification kind="error" title={title} subtitle={message} lowContrast />
    </div>
  );
}

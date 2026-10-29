import { SkeletonText } from '@carbon/react';
import type { ReactNode } from 'react';

export function LoadingState({ label = 'Loading' }: { label?: string }): ReactNode {
  return (
    <div role="status" aria-label={label} aria-busy="true">
      <SkeletonText paragraph lineCount={3} />
    </div>
  );
}

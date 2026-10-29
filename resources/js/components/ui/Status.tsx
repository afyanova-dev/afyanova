import { Tag } from '@carbon/react';
import type { ReactNode } from 'react';

type StatusKind = 'active' | 'inactive' | 'warning' | 'error' | 'info';

const KIND_MAP: Record<StatusKind, 'green' | 'gray' | 'cool-gray' | 'red' | 'blue'> = {
  active: 'green',
  inactive: 'gray',
  warning: 'cool-gray',
  error: 'red',
  info: 'blue',
};

export function Status({ kind, children }: { kind: StatusKind; children: ReactNode }): ReactNode {
  return <Tag type={KIND_MAP[kind]}>{children}</Tag>;
}

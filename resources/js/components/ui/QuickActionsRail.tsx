import type { ReactNode } from 'react';

export function QuickActionsRail({ children }: { children: ReactNode }): ReactNode {
  return (
    <aside aria-label="Quick actions" className="afyanova-quick-actions">
      {children}
    </aside>
  );
}

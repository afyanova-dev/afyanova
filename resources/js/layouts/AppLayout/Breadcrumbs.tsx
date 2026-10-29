import type { ReactNode } from 'react';
import {
  Breadcrumb,
  BreadcrumbItem,
} from '@carbon/react';

export interface Crumb {
  label: string;
  href?: string;
}

export function Breadcrumbs({ items }: { items: Crumb[] }): ReactNode {
  if (items.length === 0) return null;
  return (
    <Breadcrumb aria-label="Breadcrumb" className="cds--breadcrumb--no-trailing-slash">
      {items.map((item) =>
        item.href ? (
          <BreadcrumbItem key={item.label} href={item.href}>
            {item.label}
          </BreadcrumbItem>
        ) : (
          <BreadcrumbItem key={item.label} isCurrentPage>
            {item.label}
          </BreadcrumbItem>
        ),
      )}
    </Breadcrumb>
  );
}

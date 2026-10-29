import { usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { PageHeader } from '@/components/ui';
import { CatalogWorkspace, type CatalogWorkspaceProps } from '@/features/billing';
import { AppLayout } from '@/layouts/AppLayout';
import type { SharedProps } from '@/types/auth';

/**
 * The canonical Billing price list (Bill.2 + Bill.3a) and the medication →
 * service mapping worklist (Phase 5). Thin page: shared flash/errors in, the
 * workspace owns filters, tabs, and panels.
 */
export default function BillingCatalog(
  props: Omit<CatalogWorkspaceProps, 'errors' | 'flashStatus'>,
): ReactNode {
  const { props: shared } = usePage<SharedProps>();
  const errors = toErrorBag(shared.errors);
  const status = typeof shared.flash?.status === 'string' ? shared.flash.status : null;

  return (
    <AppLayout activeSidebarItemId="billing-catalog">
      <PageHeader
        title="Billing catalogue"
        description="Price list, tariffs, and the mapping that makes dispensing chargeable."
      />
      <CatalogWorkspace {...props} errors={errors} flashStatus={status} />
    </AppLayout>
  );
}

function toErrorBag(value: unknown): Record<string, string[]> {
  if (typeof value !== 'object' || value === null) {
    return {};
  }
  const bag: Record<string, string[]> = {};
  for (const [key, raw] of Object.entries(value as Record<string, unknown>)) {
    if (typeof raw === 'string') {
      bag[key] = [raw];
    } else if (Array.isArray(raw)) {
      bag[key] = raw.filter((item): item is string => typeof item === 'string');
    }
  }
  return bag;
}

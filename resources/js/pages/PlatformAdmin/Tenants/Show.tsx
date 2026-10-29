import type { ReactNode } from 'react';
import { PlatformAdminWorkspace } from '@/features/platform-admin/components/PlatformAdminWorkspace';
import type { FacilityReferences, TenantDetails, TenantSummary } from '@/features/platform-admin/types/platform-admin';

export default function TenantShow({
  tenants,
  facilityReferences,
  tenant,
}: {
  tenants: TenantSummary[];
  facilityReferences: FacilityReferences;
  tenant: TenantDetails;
}): ReactNode {
  return (
    <PlatformAdminWorkspace
      tenants={tenants}
      facilityReferences={facilityReferences}
      section="tenants"
      managedTenant={tenant}
    />
  );
}

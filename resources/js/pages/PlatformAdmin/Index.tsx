import { type ReactNode } from 'react';
import { PlatformAdminWorkspace, type PlatformAdminSection } from '@/features/platform-admin/components/PlatformAdminWorkspace';
import type { FacilityReferences, TenantSummary } from '@/features/platform-admin/types/platform-admin';

export default function PlatformAdminConsole({
  tenants,
  facilityReferences,
  section = 'overview',
}: {
  tenants: TenantSummary[];
  facilityReferences: FacilityReferences;
  section?: PlatformAdminSection;
}): ReactNode {
  return <PlatformAdminWorkspace tenants={tenants} facilityReferences={facilityReferences} section={section} />;
}

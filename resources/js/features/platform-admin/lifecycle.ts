// Shared tenant lifecycle derivation for the Platform Admin control plane.
// Both the overview pipeline and the registry onboarding column read this so
// stage labels and "requires attention" reasons are never re-stated per page.

import type { TenantSummary } from './types/platform-admin';

export type TenantStage = 'registered' | 'organization' | 'facility' | 'operational';

export const STAGE_ORDER: readonly TenantStage[] = ['registered', 'organization', 'facility', 'operational'];

export const STAGE_LABELS: Record<TenantStage, string> = {
  registered: 'Registered',
  organization: 'Organization set',
  facility: 'Facility set',
  operational: 'Operational',
};

/** Furthest lifecycle stage the tenant has actually reached. */
export function tenantStage(tenant: TenantSummary): TenantStage {
  if (tenant.status === 'ACTIVE' && tenant.hasActiveAdmin) return 'operational';
  if (tenant.facilityCount > 0) return 'facility';
  if (tenant.organizationCount > 0) return 'organization';
  return 'registered';
}

export function stageIndex(stage: TenantStage): number {
  return STAGE_ORDER.indexOf(stage);
}

export interface TenantAttentionReason {
  label: string;
  action: string;
}

/**
 * First blocking gap in onboarding, or null when the tenant is operational.
 * Evaluated in pipeline order so the reason always matches the stage.
 */
export function tenantAttentionReason(tenant: TenantSummary): TenantAttentionReason | null {
  if (tenant.status !== 'ACTIVE') return { label: 'Tenant not active', action: 'Review tenant' };
  if (tenant.organizationCount === 0) return { label: 'No healthcare organization', action: 'Open tenant' };
  if (tenant.facilityCount === 0) return { label: 'No facility configured', action: 'Open tenant' };
  if (!tenant.hasActiveAdmin) return { label: 'No Tenant Administrator', action: 'Open tenant' };
  return null;
}

export interface TenantSummary {
  id: string;
  name: string;
  slug: string;
  status: string;
  organizationCount: number;
  facilityCount: number;
  organizations: TenantOrganization[];
  facilities: TenantFacility[];
  hasActiveAdmin: boolean;
  pendingAdminEmail: string | null;
  pendingAdminExpiresAt: string | null;
  pendingAdminInvitationExpired: boolean;
  canInviteAdmin: boolean;
}

export interface TenantOrganization {
  id: string;
  name: string;
  code: string;
  status: string;
}

export interface TenantFacility {
  id: string;
  name: string;
  code: string;
  type: string;
  careLevel: string;
  ownershipType?: string;
  status: string;
}

export interface TenantAdministrator {
  id: string;
  assignmentId: string;
  name: string;
  email: string;
  accountStatus: string;
}

export interface FacilityAdministrator {
  id: string;
  assignmentId: string;
  name: string;
  email: string;
  accountStatus: string;
}

export interface FacilityAdminInvitation {
  name: string;
  email: string;
  expiresAt: string;
  expired: boolean;
}

export interface FacilityAdminRequest {
  id: string;
  name: string;
  email: string;
  setupComplete?: boolean;
}

export interface EmergencyAccessReview {
  id: string;
  reason: string;
  createdAt: string;
  targetName: string;
  initiatorName: string;
}

export interface FacilityDetails extends TenantFacility {
  tenantId: string;
  organizationId: string;
  organizationName: string;
  ownershipType: string;
  administrators: TenantAdministrator[];
  pendingInvitations: FacilityAdminInvitation[];
  pendingPromotions: FacilityAdminRequest[];
  pendingRevocations: FacilityAdminRequest[];
}

export interface TenantDetails extends TenantSummary {
  slug: string;
  organizations: TenantOrganization[];
  facilities: TenantFacility[];
  administrators: FacilityAdministrator[];
  pendingRevocations: FacilityAdminRequest[];
  emergencyReviews: EmergencyAccessReview[];
  hasActiveTenantAdmin: boolean;
  pendingAdminEmail: string | null;
  pendingAdminExpiresAt: string | null;
  pendingAdminInvitationExpired: boolean;
}

export interface FacilityReference {
  code: string;
  name: string;
}

export interface FacilityReferences {
  facilityTypes: FacilityReference[];
  careLevels: FacilityReference[];
  ownershipTypes: FacilityReference[];
}

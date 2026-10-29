export interface AuthUser {
  id: string | number;
  name: string;
  email: string;
  roles: string[];
}

export interface AuthFacility {
  id: string;
  name: string;
}

export interface SharedAuth {
  user: AuthUser | null;
  roles: string[];
  can: Record<string, boolean>;
  facilities: AuthFacility[];
  activeFacility: string | null;
  contextMode: 'general' | 'facility' | 'unresolved';
  canUseGeneral: boolean;
  isPlatformSuperAdmin: boolean;
}

export interface SharedFlash {
  id: string | null;
  status: string | null;
  error: string | null;
  info: string | null;
  registered_patient?: unknown;
  duplicate_collision?: unknown;
  duplicate_review?: unknown;
  pending_walkin_values?: unknown;
  recorded_payment_id?: unknown;
}

export interface SharedProps {
  app: { name: string };
  auth: SharedAuth;
  flash: SharedFlash;
  correlationId: string | null;
  [key: string]: unknown;
}

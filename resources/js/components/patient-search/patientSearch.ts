import { routes } from '@/app/routes';
import { getJson } from '@/lib/http';

/**
 * The single shared patient-search contract (OpenMRS `esm-patient-search-app`
 * pattern: one search implementation, consumed as overlay, workspace, or
 * bar). Every patient finder — topbar quick search, Front Desk board search —
 * runs through this module against the verified `GET patient.api.search`
 * JSON contract. The backend owns active-facility scoping, matching,
 * minimum-length, and too-broad handling; this module only fetches and types.
 */
export interface PatientSearchRow {
  patientId: string;
  patientNumber: string;
  displayName: string;
  sex: string | null;
  formattedDob: string | null;
  age: string | null;
  photoUrl: string | null;
  activeEncounter: { encounterNumber: string } | null;
  // Whether this patient identity can be used directly at the active facility
  // (false when the canonical patient record exists but has no registration
  // at the active facility). The backend populates this to let the UI refuse
  // selecting remote-only patients and offer a local registration/import.
  hasAccessibleFacilityRegistration?: boolean;
  // Whether the patient has a registration at the current active facility
  // (explicit per-facility flag used by UIs that must avoid cross-facility
  // selections for transactional flows).
  registeredAtActiveFacility?: boolean;
  // Provisionally created patient identities in the system (e.g. created by
  // pharmacy) — displayed and handled slightly differently in some UIs.
  isProvisional?: boolean;
}

export interface PatientSearchResponse {
  patients: PatientSearchRow[];
  total: number;
  currentPage: number;
  lastPage: number;
  error?: string;
}

export const MIN_SEARCH_CHARACTERS = 2;

export function patientSearchUrl(query: string, perPage: number): string {
  const params = new URLSearchParams({ per_page: String(perPage) });
  if (query !== '') params.set('q', query);
  return `${routes.api.patientSearch}?${params.toString()}`;
}

export function searchPatients(query: string, perPage: number): Promise<PatientSearchResponse> {
  return getJson<PatientSearchResponse>(patientSearchUrl(query, perPage));
}

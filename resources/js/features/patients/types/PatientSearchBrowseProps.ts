export interface PatientSearchResult {
  patientId: string;
  patientNumber: string;
  displayName: string;
  sex: string;
  status: string;
  isProvisional: boolean;
  hasAccessibleFacilityRegistration: boolean;
  survivingPatientId: string | null;
}

export interface PatientSearchBrowseProps {
  q: string;
  mode: 'browse' | 'search';
  patients: PatientSearchResult[];
  total: number;
  currentPage: number;
  lastPage: number;
  perPage: number;
  searchError: string | null;
}

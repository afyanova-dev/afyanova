export type PatientSex = 'MALE' | 'FEMALE' | 'UNKNOWN';
export type DobPrecision = 'KNOWN' | 'ESTIMATED' | 'UNKNOWN';
export type AfterRegistrationDestination = 'FRONT_DESK' | 'PATIENT_CHART' | 'PATIENT_LIST';

export interface PatientRegistrationData {
  given_name: string;
  middle_name: string;
  family_name: string;
  display_name: string;
  sex: PatientSex | '';
  dob_precision: DobPrecision | '';
  date_of_birth: string;
  estimated_years: string;
  estimated_months: string;
  estimated_days: string;
  primary_phone: string;
  email: string;
  national_id: string;
  insurance_member_number: string;
  region: string;
  district: string;
  ward: string;
  street_or_village: string;
  postal_code: string;
  provisional: boolean;
  override_duplicate: boolean;
  override_reason: string;
  after_registration: AfterRegistrationDestination;
  return_to_reception: boolean;
  return_date: string;
  return_status: string;
  return_filter: string;
  photo: File | null;
}

export interface DuplicateCandidate {
  patientId: string;
  patientNumber: string;
  displayName: string;
  dateOfBirth: string | null;
}

export interface DuplicateReview {
  message: string;
  confidenceBand: string | null;
  outcome: 'REVIEW';
  candidates: DuplicateCandidate[];
}

export interface PatientRegistrationPageProps {
  duplicateReview: DuplicateReview | null;
  formValues: Record<string, unknown> | null;
  hasSavedDraft?: boolean;
  defaultAfterRegistration?: AfterRegistrationDestination;
}

export function isDuplicateReview(value: unknown): value is DuplicateReview {
  if (typeof value !== 'object' || value === null) return false;

  const review = value as Record<string, unknown>;
  if (
    typeof review.message !== 'string'
    || review.outcome !== 'REVIEW'
    || (review.confidenceBand !== null && typeof review.confidenceBand !== 'string')
    || !Array.isArray(review.candidates)
  ) {
    return false;
  }

  return review.candidates.every((candidate: unknown) => {
    if (typeof candidate !== 'object' || candidate === null) return false;
    const item = candidate as Record<string, unknown>;

    return typeof item.patientId === 'string'
      && typeof item.patientNumber === 'string'
      && typeof item.displayName === 'string'
      && (item.dateOfBirth === null || typeof item.dateOfBirth === 'string');
  });
}

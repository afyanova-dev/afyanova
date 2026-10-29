export const STAFF_STATUS_LABELS = {
  PROSPECT: 'Pending onboarding',
  ONBOARDING: 'Onboarding',
  ACTIVE: 'Active',
  SUSPENDED: 'Suspended',
  TERMINATED: 'Terminated',
} as const;

export function staffStatusLabel(status: string): string {
  return STAFF_STATUS_LABELS[status as keyof typeof STAFF_STATUS_LABELS] ?? status;
}

export function staffStatusTagType(status: string): 'green' | 'red' | 'gray' | 'cool-gray' {
  if (status === 'ACTIVE') return 'green';
  if (status === 'TERMINATED') return 'red';
  if (status === 'SUSPENDED') return 'gray';
  return 'cool-gray';
}

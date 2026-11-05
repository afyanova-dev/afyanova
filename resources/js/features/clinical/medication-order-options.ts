export const OTHER_MEDICATION_OPTION = 'Other — enter manually';

export const MEDICATION_FREQUENCY_OPTIONS = [
  'Once',
  'Once daily',
  'BID (twice daily)',
  'TID (three times daily)',
  'QID (four times daily)',
  'Every 4 hours',
  'Every 6 hours',
  'Every 8 hours',
  'Every 12 hours',
  'Weekly',
  'As needed (PRN)',
];

export const MEDICATION_ROUTE_OPTIONS = [
  'Oral',
  'Topical',
  'Intramuscular',
  'Intravenous',
  'Subcutaneous',
  'Inhalation',
  'Sublingual',
  'Rectal',
  'Ophthalmic',
  'Otic',
  'Nasal',
];

export const MEDICATION_DURATION_UNITS = [
  { value: 'hour', label: 'Hours', singular: 'hour', plural: 'hours' },
  { value: 'day', label: 'Days', singular: 'day', plural: 'days' },
  { value: 'week', label: 'Weeks', singular: 'week', plural: 'weeks' },
  { value: 'month', label: 'Months', singular: 'month', plural: 'months' },
] as const;

export const CUSTOM_MEDICATION_DURATION = 'custom';

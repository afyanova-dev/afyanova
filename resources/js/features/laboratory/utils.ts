import type { LabParameter, LabTestMethod } from './types';

export type TagTone = 'red' | 'magenta' | 'purple' | 'blue' | 'cyan' | 'teal' | 'green' | 'gray' | 'cool-gray' | 'warm-gray' | 'high-contrast' | 'outline';

export function availabilityLabel(status: string): string {
  switch (status) {
    case 'AVAILABLE':
      return 'Available';
    case 'TEMPORARILY_UNAVAILABLE':
      return 'Temporarily unavailable';
    case 'REFERRAL_ONLY':
      return 'Referral only';
    case 'DISABLED':
      return 'Disabled';
    default:
      return status.replaceAll('_', ' ').toLocaleLowerCase();
  }
}

export function availabilityTone(status: string): TagTone {
  switch (status) {
    case 'AVAILABLE':
      return 'green';
    case 'TEMPORARILY_UNAVAILABLE':
      return 'magenta';
    case 'REFERRAL_ONLY':
      return 'blue';
    case 'DISABLED':
      return 'gray';
    default:
      return 'cool-gray';
  }
}

export function instrumentStatusLabel(status: string): string {
  switch (status) {
    case 'ACTIVE':
      return 'Active';
    case 'OUT_OF_SERVICE':
      return 'Out of service';
    case 'DECOMMISSIONED':
      return 'Decommissioned';
    default:
      return status.replaceAll('_', ' ').toLocaleLowerCase();
  }
}

export function instrumentStatusTone(status: string): TagTone {
  switch (status) {
    case 'ACTIVE':
      return 'green';
    case 'OUT_OF_SERVICE':
      return 'magenta';
    case 'DECOMMISSIONED':
      return 'gray';
    default:
      return 'cool-gray';
  }
}

export function parameterTypeLabel(valueType: LabParameter['value_type']): string {
  switch (valueType) {
    case 'NUMERIC':
      return 'Numeric';
    case 'CODED':
      return 'Coded';
    case 'TEXT':
      return 'Text';
    default:
      return 'Not classified';
  }
}

export function parameterReference(parameter: LabParameter): string {
  const { low, high, text } = parameter.reference_ranges;
  if (low !== null || high !== null) {
    if (low !== null && high !== null) {
      return `${low} – ${high}`;
    }

    return low !== null ? `≥ ${low}` : `≤ ${high}`;
  }

  return text.trim() !== '' ? text : '—';
}

export function parameterOptions(parameter: LabParameter): string {
  if (parameter.value_type !== 'CODED') {
    return '—';
  }

  return parameter.coded_values.length > 0
    ? parameter.coded_values.map((option) => `${option.code} — ${option.name}`).join(', ')
    : 'No options configured';
}

export function materialSummary(method: LabTestMethod): string {
  if (method.requirements.length === 0) {
    return 'No materials configured';
  }

  return method.requirements
    .map((requirement) => {
      const scope = requirement.usageScope === 'PER_RUN' ? 'per run' : 'per test';
      const optional = requirement.required ? '' : ' · optional';

      return `${requirement.itemCode} · ${requirement.quantity} ${requirement.itemUom} ${scope}${optional}`;
    })
    .join('\n');
}

export function countLabel(count: number, singular: string, plural: string): string {
  return `${count} ${count === 1 ? singular : plural}`;
}

export function roleLabel(role: string): string {
  return role
    .replaceAll('_', ' ')
    .toLocaleLowerCase()
    .replace(/\b[a-z]/g, (letter) => letter.toLocaleUpperCase());
}

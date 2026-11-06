import { Tag } from '@carbon/react';
import type { ReactNode } from 'react';
import { isActiveAllergy } from './patientChartData';
import type { PatientChartData } from '@/features/patients/types/chart';

function snakeToLabel(value: string): string {
  return value
    .toLowerCase()
    .split(/[_\s]+/)
    .filter(Boolean)
    .map((part) => part[0]!.toUpperCase() + part.slice(1))
    .join(' ');
}

function severityKind(severity: string | null): 'red' | 'magenta' | 'cool-gray' {
  if (!severity) return 'cool-gray';

  const normalized = severity.toUpperCase();
  if (normalized.includes('SEVERE') || normalized.includes('HIGH') || normalized.includes('LIFE')) {
    return 'red';
  }

  if (normalized.includes('MODERATE') || normalized.includes('MEDIUM')) {
    return 'magenta';
  }

  return 'cool-gray';
}

/**
 * Compact safety context within the patient banner, visible on every chart
 * section without duplicating visit and record status already shown there.
 */
export function PatientBannerSafety({
  chart,
}: {
  chart: PatientChartData;
}): ReactNode {
  const allergiesRestricted = chart.restricted.includes('allergies');
  const allergiesUnavailable = chart.unavailable.includes('allergies');
  const activeAllergies = chart.allergies.filter((allergy) => isActiveAllergy(allergy.status));
  const showSafetySummary = allergiesUnavailable || !allergiesRestricted;

  if (!showSafetySummary) {
    return null;
  }

  return (
    <section aria-label="Patient safety summary" className="patient-chart__banner-badges">
      {allergiesUnavailable ? (
        <Tag type="red">Allergies unavailable</Tag>
      ) : !allergiesRestricted ? (
        activeAllergies.length === 0 ? (
          <Tag type="cool-gray">No active allergies recorded</Tag>
        ) : (
          activeAllergies.map((allergy) => (
            <Tag key={allergy.id} type={severityKind(allergy.severity)}>
              Allergy: {allergy.substance}
              {allergy.severity ? ` · ${snakeToLabel(allergy.severity)}` : ''}
              {allergy.reaction ? ` · ${allergy.reaction}` : ''}
            </Tag>
          ))
        )
      ) : null}
    </section>
  );
}

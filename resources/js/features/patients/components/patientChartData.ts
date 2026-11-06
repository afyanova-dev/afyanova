import { routes } from '@/app/routes';
import { formatClinicalDateTime } from '@/lib/formatting';
import type {
  PatientChartData,
  PatientChartLabResult,
  PatientChartMedication,
  PatientChartPatient,
  PatientChartVital,
} from '@/features/patients/types/chart';

export type TimelineEventKind =
  | 'registration'
  | 'appointment'
  | 'visit'
  | 'vitals'
  | 'note'
  | 'problem'
  | 'allergy'
  | 'medication'
  | 'lab-order'
  | 'lab-result'
  | 'imaging'
  | 'procedure'
  | 'plan'
  | 'program'
  | 'referral'
  | 'billing';

export interface PatientTimelineEvent {
  id: string;
  kind: TimelineEventKind;
  at: string;
  title: string;
  detail: string | null;
  status: string | null;
  href: string;
}

export interface RecentLabResult {
  id: string;
  orderId: string;
  orderNumber: string;
  at: string;
  result: PatientChartLabResult;
  status: string;
}

export function humanizeClinicalValue(value: string | null | undefined): string {
  if (!value) return '—';

  return value
    .toLowerCase()
    .split(/[_\s]+/)
    .filter(Boolean)
    .map((part) => part[0]!.toUpperCase() + part.slice(1))
    .join(' ');
}

export function clinicalTagType(value: string | null | undefined): 'green' | 'red' | 'blue' | 'magenta' | 'cool-gray' {
  const normalized = value?.toUpperCase() ?? '';

  if (['ACTIVE', 'COMPLETED', 'DISPENSED', 'VERIFIED', 'PAID', 'SETTLED', 'NORMAL', 'FINAL', 'WITHIN_RANGE'].includes(normalized)) {
    return 'green';
  }

  if (['CANCELLED', 'DECEASED', 'CRITICAL', 'SEVERE', 'NO_SHOW', 'REJECTED', 'ABNORMAL', 'HIGH', 'LOW', 'EXPIRED'].includes(normalized)) {
    return 'red';
  }

  if (['MODERATE', 'MEDIUM'].includes(normalized)) {
    return 'magenta';
  }

  if (['RECEIVED', 'BOOKED', 'CONFIRMED', 'SCHEDULED', 'PENDING', 'IN_PROGRESS', 'IN_SERVICE', 'ARRIVED', 'ORDERED'].includes(normalized)) {
    return 'blue';
  }

  return 'cool-gray';
}

export function isActiveProblem(status: string): boolean {
  return status.toUpperCase() === 'ACTIVE';
}

export function isActiveAllergy(status: string | null): boolean {
  return status?.toUpperCase() === 'ACTIVE';
}

export function isCurrentMedication(medication: PatientChartMedication): boolean {
  const status = medication.status.toUpperCase();

  return status === 'ACTIVE' || status === 'RECEIVED';
}

export function isAbnormalFlag(flag: string | null | undefined): boolean {
  if (!flag) return false;

  return !['N', 'NORMAL', 'NEGATIVE', 'WITHIN_RANGE'].includes(flag.toUpperCase());
}

export function referenceRange(result: PatientChartLabResult): string | null {
  if (result.referenceText) return result.referenceText;

  if (result.referenceLow !== null && result.referenceLow !== undefined
    && result.referenceHigh !== null && result.referenceHigh !== undefined) {
    return `${result.referenceLow} – ${result.referenceHigh}`;
  }

  return result.referenceLow ?? result.referenceHigh ?? null;
}

export function latestVital(vitals: PatientChartVital[]): PatientChartVital | null {
  return [...vitals]
    .filter((vital) => vital.occurredAt)
    .sort((left, right) => Date.parse(right.occurredAt) - Date.parse(left.occurredAt))[0] ?? null;
}

export function recentLabResults(chart: PatientChartData, limit = 5): RecentLabResult[] {
  const results = chart.labOrders.flatMap((order) => (
    (chart.labResults[order.orderId] ?? []).map((result, index) => ({
      id: result.id ?? `${order.orderId}-${index}`,
      orderId: order.orderId,
      orderNumber: order.orderNumber,
      at: result.releasedAt ?? order.createdAt,
      result,
      status: result.status ?? order.status,
    }))
  ));

  return results
    .sort((left, right) => {
      const abnormalDelta = Number(isAbnormalFlag(right.result.flag)) - Number(isAbnormalFlag(left.result.flag));
      if (abnormalDelta !== 0) return abnormalDelta;

      return Date.parse(right.at) - Date.parse(left.at);
    })
    .slice(0, limit);
}

export function labOrderResultCount(chart: PatientChartData): number {
  return Object.values(chart.labResults).reduce((total, results) => total + results.length, 0);
}

/**
 * Common adult screening ranges used to flag vitals on the summary card.
 * A flag is a scan aid, not a diagnosis — full values stay on the vitals
 * section, and any exception (obstetrics, pediatrics) overrides it there.
 */
type VitalFlag = 'high' | 'low';

const vitalRanges: Record<string, { low: number | null; high: number | null }> = {
  temperature: { low: 36.1, high: 37.5 },
  heartRate: { low: 60, high: 100 },
  respiratoryRate: { low: 12, high: 20 },
  systolicBp: { low: 90, high: 130 },
  diastolicBp: { low: 60, high: 85 },
  oxygenSaturation: { low: 94, high: null },
};

export function vitalFlag(measureKey: string, value: number | null): VitalFlag | null {
  if (value === null) return null;
  const range = vitalRanges[measureKey];
  if (!range) return null;

  if (range.low !== null && value < range.low) return 'low';
  if (range.high !== null && value > range.high) return 'high';

  return null;
}

export function nextAppointment(chart: PatientChartData, now = new Date()): PatientChartData['appointments'][number] | null {
  return [...chart.appointments]
    .filter((appointment) => (
      appointment.status.toUpperCase() !== 'CANCELLED'
      && Date.parse(appointment.start) >= now.getTime()
    ))
    .sort((left, right) => Date.parse(left.start) - Date.parse(right.start))[0] ?? null;
}

export function nextFollowUp(chart: PatientChartData, now = new Date()): PatientChartData['plans'][number] | null {
  const today = now.toISOString().slice(0, 10);

  return [...chart.plans]
    .filter((plan) => (
      plan.followUp !== null
      && plan.followUp.slice(0, 10) >= today
      && !['CANCELLED', 'COMPLETED', 'RESOLVED'].includes(plan.status.toUpperCase())
    ))
    .sort((left, right) => Date.parse(left.followUp ?? '') - Date.parse(right.followUp ?? ''))[0] ?? null;
}

function addEvent(events: PatientTimelineEvent[], event: PatientTimelineEvent | null): void {
  if (event && Number.isFinite(Date.parse(event.at))) events.push(event);
}

export function buildTimelineEvents(patient: PatientChartPatient, chart: PatientChartData): PatientTimelineEvent[] {
  const events: PatientTimelineEvent[] = [];
  const chartHref = (section: string): string => routes.patient.chart(patient.patientId, section);

  addEvent(events, patient.registeredAt ? {
    id: `registration-${patient.patientId}`,
    kind: 'registration',
    at: patient.registeredAt,
    title: 'Patient registered',
    detail: patient.registrationSource ? `Source: ${humanizeClinicalValue(patient.registrationSource)}` : patient.patientNumber,
    status: patient.status,
    href: chartHref('overview'),
  } : null);

  for (const appointment of chart.appointments) {
    addEvent(events, {
      id: `appointment-${appointment.id}`,
      kind: 'appointment',
      at: appointment.start,
      title: `Appointment ${appointment.number}`,
      detail: [appointment.appointmentTypeName, appointment.resourceName].filter(Boolean).join(' · ') || appointment.reason,
      status: appointment.status,
      href: chartHref('appointments'),
    });
  }

  for (const visit of chart.visits) {
    addEvent(events, {
      id: `visit-${visit.id}`,
      kind: 'visit',
      at: visit.startedAt ?? visit.arrivedAt ?? visit.admittedAt ?? visit.completedAt ?? '',
      title: `Visit ${visit.number}`,
      detail: [humanizeClinicalValue(visit.encounterType), visit.departmentName, visit.unitName].filter(Boolean).join(' · ') || visit.disposition,
      status: visit.status,
      href: chartHref('visits'),
    });
  }

  for (const vital of chart.vitals) {
    addEvent(events, {
      id: `vitals-${vital.id}`,
      kind: 'vitals',
      at: vital.occurredAt,
      title: 'Vitals recorded',
      detail: [
        vital.systolicBp !== null && vital.diastolicBp !== null ? `BP ${vital.systolicBp}/${vital.diastolicBp}` : null,
        vital.heartRate !== null ? `HR ${vital.heartRate}` : null,
        vital.temperature !== null ? `Temp ${vital.temperature}` : null,
        vital.oxygenSaturation !== null ? `SpO₂ ${vital.oxygenSaturation}%` : null,
      ].filter(Boolean).join(' · ') || null,
      status: null,
      href: chartHref('vitals'),
    });
  }

  for (const problem of chart.conditions) {
    const at = problem.recordedAt ?? problem.onset;
    addEvent(events, at ? {
      id: `problem-${problem.id}`,
      kind: 'problem',
      at,
      title: problem.description,
      detail: problem.code ? `Problem · ${problem.code}` : 'Problem recorded',
      status: problem.status,
      href: chartHref('conditions'),
    } : null);
  }

  for (const allergy of chart.allergies) {
    const at = allergy.recordedAt ?? allergy.onset;
    addEvent(events, at ? {
      id: `allergy-${allergy.id}`,
      kind: 'allergy',
      at,
      title: `Allergy: ${allergy.substance}`,
      detail: [allergy.reaction, allergy.severity ? humanizeClinicalValue(allergy.severity) : null].filter(Boolean).join(' · ') || null,
      status: allergy.status,
      href: chartHref('allergies'),
    } : null);
  }

  for (const medication of chart.medications) {
    addEvent(events, {
      id: `medication-${medication.id}`,
      kind: 'medication',
      at: medication.occurredAt ?? medication.recordedAt,
      title: medication.medication ?? medication.number,
      detail: [medication.dose, medication.frequency, medication.route].filter(Boolean).join(' · ') || null,
      status: medication.status,
      href: chartHref('medications'),
    });
  }

  for (const order of chart.labOrders) {
    addEvent(events, {
      id: `lab-order-${order.orderId}`,
      kind: 'lab-order',
      at: order.createdAt,
      title: `Lab order ${order.orderNumber}`,
      detail: order.items.map((item) => item.testName).join(', ') || null,
      status: order.status,
      href: chartHref('lab-results'),
    });

    for (const [index, result] of (chart.labResults[order.orderId] ?? []).entries()) {
      const at = result.releasedAt ?? order.createdAt;
      addEvent(events, {
        id: `lab-result-${result.id ?? `${order.orderId}-${index}`}`,
        kind: 'lab-result',
        at,
        title: `${result.parameter} result`,
        detail: [
          [result.value, result.unit].filter((part) => part !== null && part !== '').join(' '),
          result.flag,
        ].filter(Boolean).join(' · ') || null,
        status: result.status ?? null,
        href: chartHref('lab-results'),
      });
    }
  }

  for (const order of chart.imagingOrders) {
    addEvent(events, {
      id: `imaging-${order.orderId}`,
      kind: 'imaging',
      at: order.createdAt,
      title: `Imaging ${order.orderNumber}`,
      detail: order.items.map((item) => item.serviceName ?? item.serviceCode ?? 'Imaging').join(', ') || null,
      status: order.status,
      href: chartHref('imaging'),
    });
  }

  for (const procedure of chart.procedures) {
    addEvent(events, procedure.completedAt ? {
      id: `procedure-${procedure.id}`,
      kind: 'procedure',
      at: procedure.completedAt,
      title: `Procedure ${procedure.number}`,
      detail: [
        procedure.category ? humanizeClinicalValue(procedure.category) : null,
        procedure.outcome ? humanizeClinicalValue(procedure.outcome) : null,
      ].filter(Boolean).join(' · ') || null,
      status: procedure.status,
      href: chartHref('procedures'),
    } : null);
  }

  for (const plan of chart.plans) {
    addEvent(events, plan.followUp ? {
      id: `plan-${plan.id}`,
      kind: 'plan',
      at: plan.followUp,
      title: `Follow-up planned: ${humanizeClinicalValue(plan.type)}`,
      detail: humanizeClinicalValue(plan.intent),
      status: plan.status,
      href: chartHref('plans'),
    } : null);
  }

  for (const program of chart.programs) {
    addEvent(events, program.openedAt ? {
      id: `program-${program.id}`,
      kind: 'program',
      at: program.openedAt,
      title: `Care program opened: ${humanizeClinicalValue(program.type)}`,
      detail: program.subtype ? humanizeClinicalValue(program.subtype) : null,
      status: program.status,
      href: chartHref('programs'),
    } : null);
  }

  for (const [index, entry] of chart.billing.ledger.entries()) {
    addEvent(events, {
      id: `billing-${entry.postedAt}-${entry.entryType}-${index}`,
      kind: 'billing',
      at: entry.postedAt,
      title: humanizeClinicalValue(entry.entryType),
      detail: [humanizeClinicalValue(entry.direction), entry.amount, entry.currency].filter(Boolean).join(' · ') || null,
      status: null,
      href: chartHref('billing-history'),
    });
  }

  return events.sort((left, right) => Date.parse(right.at) - Date.parse(left.at));
}

export function timelineEventTime(event: PatientTimelineEvent): string {
  return formatClinicalDateTime(event.at) ?? event.at;
}

export function timelineEventDate(event: PatientTimelineEvent): string {
  return event.at.slice(0, 10);
}

import { describe, expect, it } from 'vitest';
import { routes } from './routes';

describe('canonical route builders', () => {
  it('points at the canonical chart URL, with optional tab', () => {
    expect(routes.patient.chart('abc')).toBe('/patient/abc/chart');
    expect(routes.patient.chart('abc', 'visits')).toBe('/patient/abc/chart/visits');
  });

  it('exposes the verified JSON endpoints', () => {
    expect(routes.api.patientSearch).toBe('/workspaces/patient/api/search');
    expect(routes.api.facilityStructure).toBe('/workspaces/organization/api/structure');
    expect(routes.api.appointmentCalendar).toBe('/workspaces/appointments/api/calendar');
    expect(routes.workspaces.patientIndex).toBe('/workspaces/patient');
  });

  it('builds the patient update URL', () => {
    expect(routes.patient.update('abc')).toBe('/workspaces/patient/abc');
  });

  it('builds the appointment reschedule URL', () => {
    expect(routes.workspaces.appointmentReschedule('appt-1')).toBe('/workspaces/appointments/appt-1/reschedule');
  });

  it('builds the appointment arrival URL', () => {
    expect(routes.workspaces.appointmentArrive('appt-1')).toBe('/workspaces/appointments/appt-1/arrive');
  });

  it('exposes the reception board URL', () => {
    expect(routes.workspaces.reception).toBe('/workspaces/reception');
  });
});

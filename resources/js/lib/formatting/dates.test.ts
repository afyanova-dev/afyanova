import { describe, expect, it } from 'vitest';
import { ageDisplay, formatClinicalDateTime, formatDobDate } from './index';
import { dayInZone, formatDateTimeInZone, formatTimeInZone } from './facility-time';

// Fixed "now" so age bands are deterministic: 2026-10-02T12:00:00Z.
const NOW = new Date('2026-10-02T12:00:00Z');

describe('clinical date display (mirrors backend dobDisplay)', () => {
  it('formats date-only input as d-M-Y', () => {
    expect(formatDobDate('1994-06-02')).toBe('02-Jun-1994');
    expect(formatDobDate(null)).toBeNull();
    expect(formatDobDate('')).toBeNull();
    expect(formatDobDate('1994-13-40')).toBeNull();
    expect(formatDobDate('not-a-date')).toBeNull();
  });

  it('renders datetimes in UTC with an explicit zone', () => {
    expect(formatClinicalDateTime('2026-10-02T09:56:07+00:00')).toBe('02-Oct-2026, 09:56 UTC');
    expect(formatClinicalDateTime('1994-06-02')).toBe('02-Jun-1994');
    expect(formatClinicalDateTime(null)).toBeNull();
  });

  it('computes backend-identical age bands', () => {
    expect(ageDisplay('1994-06-02', NOW)).toBe('32 yrs, 4 mths');
    expect(ageDisplay('1994-10-02', NOW)).toBe('32 yrs');
    expect(ageDisplay('2025-10-02', NOW)).toBe('1 yr');
    expect(ageDisplay('2025-08-02', NOW)).toBe('1 yr, 2 mths');
    expect(ageDisplay('2026-07-02', NOW)).toBe('3 mths');
    expect(ageDisplay('2026-09-20', NOW)).toBe('12 days');
    expect(ageDisplay('2026-10-02', NOW)).toBe('0 days');
  });

  it('returns null for future or invalid birth dates', () => {
    expect(ageDisplay('2027-01-01', NOW)).toBeNull();
    expect(ageDisplay(null, NOW)).toBeNull();
    expect(ageDisplay('unknown', NOW)).toBeNull();
  });
});

describe('facility wall-clock display (Africa/Dar_es_Salaam)', () => {
  const ZONE = 'Africa/Dar_es_Salaam';

  it('derives the calendar day in the facility zone', () => {
    // 21:03 UTC Oct 3 is already Oct 4 in Tanzania.
    expect(dayInZone(ZONE, new Date('2026-10-03T21:03:00+00:00'))).toBe('2026-10-04');
    expect(dayInZone(ZONE, new Date('2026-10-04T17:42:00+00:00'))).toBe('2026-10-04');
    expect(dayInZone(ZONE, new Date('2026-10-04T20:00:00+00:00'))).toBe('2026-10-04');
  });

  it('renders wall times with an explicit offset', () => {
    expect(formatTimeInZone('2026-10-03T08:15:00+03:00', ZONE)).toBe('08:15 GMT+3');
    expect(formatTimeInZone('2026-10-03T21:03:00+00:00', ZONE)).toBe('00:03 GMT+3');
    expect(formatTimeInZone(null, ZONE)).toBeNull();
    expect(formatTimeInZone('not-a-date', ZONE)).toBeNull();
  });

  it('renders wall datetimes with the facility calendar day', () => {
    expect(formatDateTimeInZone('2026-10-03T21:03:00+00:00', ZONE)).toBe('04-Oct-2026, 00:03 GMT+3');
    expect(formatDateTimeInZone('2026-10-03T08:15:00+03:00', ZONE)).toBe('03-Oct-2026, 08:15 GMT+3');
    expect(formatDateTimeInZone('2026-10-03', ZONE)).toBe('03-Oct-2026');
    expect(formatDateTimeInZone(null, ZONE)).toBeNull();
  });
});

import { formatDobDate, SHORT_MONTHS } from './dates';

/**
 * Facility wall-clock formatting. Day buckets, "today", and displayed
 * times share the backend's facility zone — never UTC, never the browser
 * zone — so rows always agree with the selected date. The zone itself
 * arrives as a backend prop (Rule 04); these helpers only render in it.
 */

interface ZoneFields {
  day: string;
  month: string;
  year: string;
  hour: string;
  minute: string;
  timeZoneName: string;
}

function partsInZone(value: Date, timeZone: string): ZoneFields | null {
  try {
    const parts = new Intl.DateTimeFormat('en', {
      timeZone,
      day: '2-digit',
      month: 'numeric',
      year: 'numeric',
      hour: '2-digit',
      minute: '2-digit',
      hourCycle: 'h23',
      timeZoneName: 'shortOffset',
    }).formatToParts(value);
    const fields: Record<string, string> = {};
    for (const part of parts) {
      if (part.type !== 'literal') fields[part.type] = part.value;
    }
    const { day, month, year, hour, minute, timeZoneName } = fields;

    return day && month && year && hour && minute && timeZoneName
      ? { day, month, year, hour, minute, timeZoneName }
      : null;
  } catch {
    return null;
  }
}

function parseInstant(value: string | null | undefined): Date | null {
  if (!value) return null;
  const trimmed = value.trim();
  if (trimmed === '') return null;
  const parsed = new Date(trimmed);

  return Number.isNaN(parsed.getTime()) ? null : parsed;
}

/** Current calendar day (`Y-m-d`) in the facility zone. Injectable `now` for tests. */
export function dayInZone(timeZone: string, now: Date = new Date()): string {
  return new Intl.DateTimeFormat('en-CA', {
    timeZone,
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
  }).format(now);
}

/** Instant -> `HH:mm GMT+3` wall time in the facility zone. Null when unparseable. */
export function formatTimeInZone(value: string | null | undefined, timeZone: string): string | null {
  const parsed = parseInstant(value);
  if (!parsed) return null;
  const fields = partsInZone(parsed, timeZone);
  if (!fields) return null;

  return `${fields.hour}:${fields.minute} ${fields.timeZoneName}`;
}

/**
 * Instant -> `03-Oct-2026, 08:15 GMT+3` wall datetime in the facility zone.
 * Date-only input renders without a time. Null when unparseable.
 */
export function formatDateTimeInZone(value: string | null | undefined, timeZone: string): string | null {
  const parsed = parseInstant(value);
  if (!parsed) return null;
  const dateOnly = formatDobDate(value);
  if (dateOnly) return dateOnly;
  const fields = partsInZone(parsed, timeZone);
  if (!fields) return null;

  return `${dateOnlyFor(fields)}, ${fields.hour}:${fields.minute} ${fields.timeZoneName}`;
}

function dateOnlyFor(fields: ZoneFields): string {
  const day = fields.day.padStart(2, '0');
  const month = SHORT_MONTHS[Number(fields.month) - 1] ?? fields.month;

  return `${day}-${month}-${fields.year}`;
}

/**
 * Clinical date display (mirrors the backend's dobDisplay in
 * PatientSearchApiController: `d-M-Y` dates, `X yrs[, Y mths]` ages).
 * Intl-based, no date library. Future or unparseable inputs yield null and
 * render as em-dash via printable() — never invented values.
 */

const DATE_ONLY = /^(\d{4})-(\d{2})-(\d{2})$/;

const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

export { MONTHS as SHORT_MONTHS };

const FULL_MONTHS = [
  'January', 'February', 'March', 'April', 'May', 'June',
  'July', 'August', 'September', 'October', 'November', 'December',
];

/** '2026-10' -> 'October 2026'. Null for anything else. */
export function formatMonthYear(value: string | null | undefined): string | null {
  if (!value) return null;
  const match = value.match(/^(\d{4})-(\d{2})$/);
  if (!match) return null;
  const month = Number(match[2]);
  if (month < 1 || month > 12) return null;
  return `${FULL_MONTHS[month - 1]} ${match[1]}`;
}

function isValidDate(year: number, month: number, day: number): boolean {
  if (month < 1 || month > 12 || day < 1 || day > 31) return false;
  const date = new Date(Date.UTC(year, month - 1, day));
  return date.getUTCFullYear() === year && date.getUTCMonth() === month - 1 && date.getUTCDate() === day;
}

function pad(value: number): string {
  return value.toString().padStart(2, '0');
}

/** '1994-06-02' -> '02-Jun-1994'. Null for missing/invalid input. */
export function formatDobDate(value: string | null | undefined): string | null {  if (!value) return null;
  const match = value.trim().match(DATE_ONLY);
  if (!match) return null;
  const year = Number(match[1]);
  const month = Number(match[2]);
  const day = Number(match[3]);
  if (!isValidDate(year, month, day)) return null;
  return `${pad(day)}-${MONTHS[month - 1]}-${year}`;
}

/**
 * Any backend date/datetime -> display string. Date-only input renders
 * without a time; datetimes render in UTC (suffixed, so the zone is never
 * ambiguous). Null for missing/unparseable input.
 */
export function formatClinicalDateTime(value: string | null | undefined): string | null {
  if (!value) return null;
  const trimmed = value.trim();
  if (trimmed === '') return null;
  const dateOnly = formatDobDate(trimmed);
  if (dateOnly) return dateOnly;
  const parsed = new Date(trimmed);
  if (Number.isNaN(parsed.getTime())) return trimmed;
  const date = `${pad(parsed.getUTCDate())}-${MONTHS[parsed.getUTCMonth()]}-${parsed.getUTCFullYear()}`;
  return `${date}, ${pad(parsed.getUTCHours())}:${pad(parsed.getUTCMinutes())} UTC`;
}

interface AgeParts {
  years: number;
  months: number;
  days: number;
}

function ageParts(dob: { year: number; month: number; day: number }, now: Date): AgeParts | null {
  if (now.getTime() < Date.UTC(dob.year, dob.month - 1, dob.day)) return null;
  let years = now.getUTCFullYear() - dob.year;
  let months = now.getUTCMonth() + 1 - dob.month;
  let days = now.getUTCDate() - dob.day;
  if (days < 0) {
    months -= 1;
    days += new Date(Date.UTC(now.getUTCFullYear(), now.getUTCMonth(), 0)).getUTCDate();
  }
  if (months < 0) {
    years -= 1;
    months += 12;
  }
  return { years, months, days };
}

/**
 * '1994-06-02' -> '32 yrs, 4 mths' (bands mirror the backend exactly:
 * `X yrs[, Y mths]` / `1 yr[, Y mths]` / `X mths[, Y days]` / `X days`).
 * Null when the age cannot be computed. `now` is injectable for tests.
 */
export function ageDisplay(dob: string | null | undefined, now: Date = new Date()): string | null {
  if (!dob) return null;
  const match = dob.trim().match(DATE_ONLY);
  if (!match) return null;
  const parts = ageParts(
    { year: Number(match[1]), month: Number(match[2]), day: Number(match[3]) },
    now,
  );
  if (!parts) return null;
  const { years, months, days } = parts;
  if (years >= 2) return months > 0 ? `${years} yrs, ${months} mths` : `${years} yrs`;
  if (years === 1) return months > 0 ? `1 yr, ${months} mths` : '1 yr';
  if (months > 0) return days > 0 ? `${months} mths, ${days} days` : `${months} mths`;
  return `${days} days`;
}

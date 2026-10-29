import { describe, expect, it } from 'vitest';
import { resolveTypeDurationMinutes } from './AppointmentLifecycleButtons';

const types = [
  { id: 'type-1', name: 'General Consultation', durationMinutes: 30 },
  { id: 'type-2', name: 'Review', durationMinutes: 15 },
];

describe('resolveTypeDurationMinutes', () => {
  it('prefers the exact id match used by chart rows', () => {
    expect(resolveTypeDurationMinutes(types, 'type-2', 'General Consultation')).toBe(15);
  });

  it('falls back to the name match used by agenda rows', () => {
    expect(resolveTypeDurationMinutes(types, null, 'Review')).toBe(15);
  });

  it('returns null for unknown types so callers use the 30-minute default', () => {
    expect(resolveTypeDurationMinutes(types, 'missing', 'Missing')).toBeNull();
    expect(resolveTypeDurationMinutes([], 'type-1', 'General Consultation')).toBeNull();
    expect(resolveTypeDurationMinutes(types, null, null)).toBeNull();
  });
});

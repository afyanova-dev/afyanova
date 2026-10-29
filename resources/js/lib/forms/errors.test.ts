import { describe, expect, it } from 'vitest';
import { bagErrors, firstMessage } from './index';

describe('bagErrors (named validation bags)', () => {
  const errors = {
    email: 'The email field is required.',
    updatePassword: {
      password: 'The password must be at least 12 characters.',
    },
  };

  it('reads a named bag nested under errors', () => {
    expect(bagErrors(errors, 'updatePassword')).toEqual({
      password: 'The password must be at least 12 characters.',
    });
  });

  it('returns empty for missing bags and missing errors', () => {
    expect(bagErrors(errors, 'nope')).toEqual({});
    expect(bagErrors(undefined, 'updatePassword')).toEqual({});
    expect(bagErrors(null, 'updatePassword')).toEqual({});
  });

  it('reads the first message of array values', () => {
    expect(firstMessage(['a', 'b'])).toBe('a');
    expect(firstMessage('a')).toBe('a');
    expect(firstMessage(undefined)).toBeUndefined();
  });
});

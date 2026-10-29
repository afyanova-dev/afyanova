import { describe, expect, it } from 'vitest';
import { shortNotificationTitle } from '@/layouts/AppLayout/AppLayout';

describe('flash notification titles', () => {
  it('keeps a short first sentence unchanged', () => {
    expect(shortNotificationTitle('Staff created. Invitation sent.')).toBe('Staff created.');
  });

  it('uses only the first sentence when the message contains extra detail', () => {
    expect(shortNotificationTitle('Staff created. Placement is pending confirmation.')).toBe('Staff created.');
  });

  it('limits long titles to 72 characters without cutting a word', () => {
    const title = shortNotificationTitle(
      'Staff record created; HR placement is awaiting confirmation by a Facility Admin or another authorized assignment reviewer.',
    );

    expect(title.length).toBeLessThanOrEqual(72);
    expect(title).toBe('Staff record created; HR placement is awaiting confirmation by a…');
  });
});

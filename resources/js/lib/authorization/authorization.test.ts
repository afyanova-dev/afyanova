import { describe, expect, it } from 'vitest';
import { checkAll, checkAny, checkCan, permissions } from './index';
import type { PermissionMap } from './index';

// Representative effective-permission sets. Roles are backend-owned and never
// appear here — only the capabilities each user holds.
const userA: PermissionMap = {
  [permissions.queue.ticketView]: true,
  'patient.patients.update': true,
};
const userB: PermissionMap = { [permissions.queue.ticketView]: true };
const userC: PermissionMap = {
  [permissions.queue.ticketView]: true,
  [permissions.scheduling.access]: true,
};
const userD: PermissionMap = {};

describe('authorization (rule: capability, never role)', () => {
  it('grants only held capabilities', () => {
    expect(checkCan(userA, permissions.queue.ticketView)).toBe(true);
    expect(checkCan(userA, 'patient.patients.update')).toBe(true);
    expect(checkCan(userB, 'patient.patients.update')).toBe(false);
    expect(checkCan(userD, permissions.queue.ticketView)).toBe(false);
  });

  it('distinguishes view from update (User A vs User B)', () => {
    expect(checkCan(userA, 'patient.patients.update')).toBe(true);
    expect(checkCan(userB, permissions.queue.ticketView)).toBe(true);
    expect(checkCan(userB, 'patient.patients.update')).toBe(false);
  });

  it('composes independent capabilities (User C)', () => {
    expect(checkCan(userC, permissions.queue.ticketView)).toBe(true);
    expect(checkCan(userC, permissions.scheduling.access)).toBe(true);
    expect(checkCan(userC, permissions.billing.access)).toBe(false);
  });

  it('fails closed on missing or malformed data', () => {
    expect(checkCan(undefined, permissions.queue.ticketView)).toBe(false);
    expect(checkCan(null, permissions.queue.ticketView)).toBe(false);
    expect(checkCan({}, permissions.queue.ticketView)).toBe(false);
    // Explicit false (backend denies) is not granted.
    expect(checkCan({ [permissions.queue.ticketView]: false }, permissions.queue.ticketView)).toBe(false);
    expect(checkAny(undefined, [permissions.queue.ticketView])).toBe(false);
    expect(checkAll(undefined, [permissions.queue.ticketView])).toBe(false);
  });

  it('supports any/all composition for sections and tabs', () => {
    const needed = [permissions.scheduling.access, permissions.billing.access] as const;
    expect(checkAny(userC, needed)).toBe(true);
    expect(checkAny(userB, needed)).toBe(false);
    expect(checkAll(userC, needed)).toBe(false);
    expect(
      checkAll({ ...userC, [permissions.billing.access]: true }, needed),
    ).toBe(true);
  });

  it('ignores role changes: new capabilities light up with no code change', () => {
    // Backend grants User B a new capability tomorrow — same checks pass.
    const userBPlus = { ...userB, [permissions.pharmacy.access]: true };
    expect(checkCan(userBPlus, permissions.pharmacy.access)).toBe(true);
    expect(checkCan(userB, permissions.pharmacy.access)).toBe(false);
  });
});

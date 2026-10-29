import { describe, expect, it } from 'vitest';
import { resourceCan, type CapableResource } from './index';

type Row = CapableResource & Record<string, unknown>;

describe('resourceCan (rule 02: consume, never derive)', () => {
  it('reads backend-provided per-resource flags', () => {
    const row: Row = { id: '1', can: { update: true, delete: false } };
    expect(resourceCan(row, 'update')).toBe(true);
    expect(resourceCan(row, 'delete')).toBe(false);
  });

  it('fails closed on missing data', () => {
    const bare: Row = { id: '1' };
    expect(resourceCan(null, 'update')).toBe(false);
    expect(resourceCan(undefined, 'update')).toBe(false);
    expect(resourceCan(bare, 'update')).toBe(false);
    expect(resourceCan({ can: null }, 'update')).toBe(false);
    expect(resourceCan({ can: {} }, 'update')).toBe(false);
    expect(resourceCan({ can: { update: false } }, 'update')).toBe(false);
  });

  it('never consults resource fields', () => {
    // Ownership / status / facility look-alikes must not grant anything.
    const owned: Row = { id: '1', createdBy: 'me', status: 'OPEN', can: { update: false } };
    expect(resourceCan(owned, 'update')).toBe(false);
  });
});

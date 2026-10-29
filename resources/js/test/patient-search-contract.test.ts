import { beforeEach, describe, expect, it, vi } from 'vitest';
import {
  patientSearchUrl,
  searchPatients,
} from '@/components/patient-search/patientSearch';

const state = vi.hoisted(() => ({
  getJson: vi.fn(),
}));

vi.mock('@/lib/http', () => ({
  getJson: state.getJson,
}));

beforeEach(() => {
  vi.clearAllMocks();
});

describe('shared patient search contract', () => {
  it('builds one search URL for every consumer', () => {
    expect(patientSearchUrl('Amina', 7)).toBe('/workspaces/patient/api/search?per_page=7&q=Amina');
    expect(patientSearchUrl('', 10)).toBe('/workspaces/patient/api/search?per_page=10');
    expect(patientSearchUrl('', 10)).not.toContain('q=');
  });

  it('fetches through the single JSON contract', async () => {
    const response = { patients: [], total: 0, currentPage: 1, lastPage: 1 };
    state.getJson.mockResolvedValue(response);

    await expect(searchPatients('Amina', 7)).resolves.toBe(response);
    expect(state.getJson).toHaveBeenCalledOnce();
    expect(state.getJson).toHaveBeenCalledWith('/workspaces/patient/api/search?per_page=7&q=Amina');
  });
});

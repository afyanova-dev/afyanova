import { act, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { CarbonThemeProvider } from '@/app/CarbonThemeProvider';
import { PlatformAdminQuickSearch } from '@/components/navigation/PlatformAdminQuickSearch';

const state = vi.hoisted(() => ({
  visit: vi.fn(),
  getJson: vi.fn(),
}));

vi.mock('@inertiajs/react', () => ({
  router: { visit: state.visit },
}));

vi.mock('@/lib/http', () => ({
  getJson: state.getJson,
}));

beforeEach(() => {
  state.visit.mockReset();
  state.getJson.mockReset();
  state.getJson.mockResolvedValue({
    results: [{
      type: 'facility',
      id: 'facility-1',
      tenantId: 'tenant-1',
      name: 'DSK Dispensary',
      tenantName: 'DSK Health Network',
      status: 'ACTIVE',
    }],
  });
});

describe('PlatformAdminQuickSearch', () => {
  it('searches for tenants and facilities and navigates to a selected facility', async () => {
    render(<PlatformAdminQuickSearch />, { wrapper: CarbonThemeProvider });

    fireEvent.click(screen.getByRole('button', { name: 'Search tenants and facilities' }));
    fireEvent.change(screen.getByRole('combobox', { name: 'Search tenants and facilities' }), {
      target: { value: 'DSK' },
    });
    await act(async () => new Promise((resolve) => window.setTimeout(resolve, 350)));

    await waitFor(() => expect(state.getJson).toHaveBeenCalledWith('/platform-admin/search?q=DSK'));
    expect(await screen.findByText('DSK Dispensary')).toBeInTheDocument();
    fireEvent.click(screen.getByRole('link', { name: /DSK Dispensary/ }));
    expect(state.visit).toHaveBeenCalledWith('/platform-admin/tenants/tenant-1/facilities/facility-1');
  });
});

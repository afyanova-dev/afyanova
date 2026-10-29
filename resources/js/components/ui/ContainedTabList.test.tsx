import { render, screen } from '@testing-library/react';
import { Tabs } from '@carbon/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { Checkmark } from '@/icons';
import { ContainedTabList, IconTab } from './ContainedTabList';

afterEach(() => {
  vi.unstubAllGlobals();
});

describe('contained Carbon tabs', () => {
  it('renders accessible tabs with icons in a contained tab list', () => {
    vi.stubGlobal('ResizeObserver', class {
      observe(): void {}
      unobserve(): void {}
      disconnect(): void {}
    });
    vi.stubGlobal('matchMedia', vi.fn((media: string) => ({
      matches: false,
      media,
      onchange: null,
      addListener: vi.fn(),
      removeListener: vi.fn(),
      addEventListener: vi.fn(),
      removeEventListener: vi.fn(),
      dispatchEvent: vi.fn(),
    })));

    render(
      <Tabs>
        <ContainedTabList aria-label="Example views">
          <IconTab icon={Checkmark}>Active</IconTab>
        </ContainedTabList>
      </Tabs>,
    );

    const tabList = screen.getByRole('tablist', { name: 'Example views' });
    const tab = screen.getByRole('tab', { name: 'Active' });

    expect(tabList.closest('.cds--tabs--contained')).toBeInTheDocument();
    expect(tab.querySelector('svg')).toBeInTheDocument();
  });
});

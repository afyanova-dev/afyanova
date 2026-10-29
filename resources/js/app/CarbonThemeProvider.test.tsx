import { fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it } from 'vitest';
import { CarbonThemeProvider, useCarbonTheme } from './CarbonThemeProvider';

function ThemeControls() {
  const { theme, setTheme } = useCarbonTheme();

  return (
    <button type="button" onClick={() => setTheme('g10')}>
      {theme}
    </button>
  );
}

describe('CarbonThemeProvider', () => {
  beforeEach(() => {
    window.localStorage.clear();
    document.documentElement.classList.remove('cds--white', 'cds--g10', 'cds--g90', 'cds--g100');
    document.documentElement.style.colorScheme = '';
  });

  it('defaults to the white Carbon theme and persists a selected theme', () => {
    render(
      <CarbonThemeProvider>
        <ThemeControls />
      </CarbonThemeProvider>,
    );

    expect(screen.getByRole('button')).toHaveTextContent('white');
    fireEvent.click(screen.getByRole('button'));

    expect(document.documentElement).toHaveClass('cds--g10');
    expect(document.documentElement.style.colorScheme).toBe('light');
    expect(window.localStorage.getItem('afyanova-theme')).toBe('g10');
  });

  it('restores a previously selected Carbon theme', () => {
    window.localStorage.setItem('afyanova-theme', 'g90');

    render(
      <CarbonThemeProvider>
        <ThemeControls />
      </CarbonThemeProvider>,
    );

    expect(screen.getByRole('button')).toHaveTextContent('g90');
    expect(document.documentElement).toHaveClass('cds--g90');
    expect(document.documentElement.style.colorScheme).toBe('dark');
  });
});

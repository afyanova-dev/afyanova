import { Theme } from '@carbon/react';
import { createContext, useCallback, useContext, useEffect, useState, type ReactNode } from 'react';

export const carbonThemes = ['white', 'g10', 'g90', 'g100'] as const;

export type CarbonThemeName = (typeof carbonThemes)[number];

const THEME_STORAGE_KEY = 'afyanova-theme';
const darkThemes: CarbonThemeName[] = ['g90', 'g100'];

interface CarbonThemeContextValue {
  theme: CarbonThemeName;
  setTheme: (theme: CarbonThemeName) => void;
}

const CarbonThemeContext = createContext<CarbonThemeContextValue | null>(null);

function isCarbonTheme(value: string | null): value is CarbonThemeName {
  return carbonThemes.some((theme) => theme === value);
}

function readStoredTheme(): CarbonThemeName {
  try {
    const storedTheme = window.localStorage.getItem(THEME_STORAGE_KEY);

    if (isCarbonTheme(storedTheme)) return storedTheme;
    if (storedTheme === 'dark') return 'g100';
    if (storedTheme === 'light') return 'white';
  } catch (error) {
    console.error('Unable to read the saved appearance preference.', error);
  }

  return 'white';
}

function applyDocumentTheme(theme: CarbonThemeName): void {
  const root = document.documentElement;
  root.classList.remove(...carbonThemes.map((name) => `cds--${name}`));
  root.classList.add(`cds--${theme}`);
  root.style.colorScheme = darkThemes.includes(theme) ? 'dark' : 'light';
}

export function CarbonThemeProvider({ children }: { children: ReactNode }): ReactNode {
  const [theme, setThemeState] = useState(readStoredTheme);

  useEffect(() => {
    applyDocumentTheme(theme);
  }, [theme]);

  const setTheme = useCallback((nextTheme: CarbonThemeName) => {
    setThemeState(nextTheme);
    applyDocumentTheme(nextTheme);

    try {
      window.localStorage.setItem(THEME_STORAGE_KEY, nextTheme);
    } catch (error) {
      console.error('Unable to save the appearance preference.', error);
    }
  }, []);

  return (
    <CarbonThemeContext.Provider value={{ theme, setTheme }}>
      <Theme as="div" className="afyanova-theme-root" theme={theme}>
        {children}
      </Theme>
    </CarbonThemeContext.Provider>
  );
}

export function useCarbonTheme(): CarbonThemeContextValue {
  const context = useContext(CarbonThemeContext);

  if (!context) {
    throw new Error('useCarbonTheme must be used within CarbonThemeProvider.');
  }

  return context;
}

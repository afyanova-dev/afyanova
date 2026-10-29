import '../css/app.css';

import { createInertiaApp } from '@inertiajs/react';
import { createElement } from 'react';
import { createRoot } from 'react-dom/client';
import { initInertiaDefaults } from './app/bootstrap';
import { CarbonThemeProvider } from './app/CarbonThemeProvider';
import './types/inertia';

initInertiaDefaults();

const pages = import.meta.glob<{ default: React.ComponentType }>('./pages/**/*.tsx');

async function resolvePage(name: string): Promise<React.ComponentType> {
  const componentName = name === 'Patient/Register' ? 'Patients/Register' : name;
  const path = `./pages/${componentName}.tsx`;
  const loadPage = pages[path];
  if (!loadPage) {
    // No client fallback by design: with server-side ensure_pages_exist the
    // backend 500s into the branded errors/500 view; a client fallback would
    // mask backend/frontend contract drift.
    throw new Error(`Inertia page not found: ${path}. Backend requested "${name}".`);
  }
  const page = await loadPage();
  return page.default;
}

void createInertiaApp({
  title: (title) => (title ? `${title} — AfyaNova` : 'AfyaNova'),
  resolve: (name) => resolvePage(name),
  setup({ el, App, props }) {
    createRoot(el).render(
      createElement(CarbonThemeProvider, null, createElement(App, props)),
    );
  },
  progress: { color: '#0f62fe', showSpinner: false },
});

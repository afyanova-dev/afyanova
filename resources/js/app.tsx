import '../css/app.css';

import { createInertiaApp } from '@inertiajs/react';
import { createElement } from 'react';
import { createRoot } from 'react-dom/client';
import { initInertiaDefaults } from './app/bootstrap';
import { CarbonThemeProvider } from './app/CarbonThemeProvider';
import { resolveInertiaPage } from './app/resolveInertiaPage';
import './types/inertia';

initInertiaDefaults();

void createInertiaApp({
  title: (title) => (title ? `${title} — AfyaNova` : 'AfyaNova'),
  resolve: resolveInertiaPage,
  setup({ el, App, props }) {
    createRoot(el).render(
      createElement(CarbonThemeProvider, null, createElement(App, props)),
    );
  },
  progress: { color: '#0f62fe', showSpinner: false },
});

import type { ComponentType } from 'react';

const pages = import.meta.glob<{ default: ComponentType }>('../pages/**/*.tsx');

export async function resolveInertiaPage(name: string): Promise<ComponentType> {
  const componentName = name === 'Patient/Register' ? 'Patients/Register' : name;
  const path = `./pages/${componentName}.tsx`;
  const modulePath = `../pages/${componentName}.tsx`;
  const loadPage = pages[modulePath];

  if (!loadPage) {
    // Do not hide backend/frontend contract drift with a client fallback.
    throw new Error(`Inertia page not found: ${path}. Backend requested "${name}".`);
  }

  const page = await loadPage();

  return page.default;
}

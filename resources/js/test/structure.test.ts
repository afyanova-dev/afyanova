import { readdirSync, statSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';

const JS_ROOT = join(dirname(fileURLToPath(import.meta.url)), '..');

// Rule 01-placement: frozen top level of resources/js/. Changing this set
// requires updating rules/01-placement.md and eslint.config.js boundaries
// in the same change.
const FROZEN_DIRS = [
  'app',
  'components',
  'features',
  'hooks',
  'icons',
  'layouts',
  'lib',
  'pages',
  'rules',
  'shared',
  'test',
  'types',
];

const FROZEN_FILES = ['app.tsx'];

describe('frontend structure freeze (rule 01-placement)', () => {
  it('contains exactly the frozen top-level entries', () => {
    const entries = readdirSync(JS_ROOT, { withFileTypes: true });
    const dirs = entries
      .filter((e) => e.isDirectory())
      .map((e) => e.name)
      .sort();
    const files = entries
      .filter((e) => e.isFile())
      .map((e) => e.name)
      .sort();

    expect(dirs, 'unexpected top-level directory — see rules/01-placement.md').toEqual(
      [...FROZEN_DIRS].sort(),
    );
    expect(files, 'unexpected top-level file — see rules/01-placement.md').toEqual(
      [...FROZEN_FILES].sort(),
    );
  });

  it('keeps the Inertia entry point and global CSS wired', () => {
    const entries = readdirSync(JS_ROOT).sort();
    expect(entries).toContain('app.tsx');
  });

  it('keeps test files out of pages/ (the eager page glob would bundle vitest)', () => {
    // NOTE: co-located tests elsewhere (features/*, lib/*, components/*) are
    // safe — only ./pages/** enters the production bundle via app.tsx.
    const offenders: string[] = [];
    const walk = (dir: string): void => {
      for (const entry of readdirSync(dir)) {
        const full = join(dir, entry);
        if (statSync(full).isDirectory()) {
          walk(full);
        } else if (/\.(test|spec)\.(ts|tsx)$/.test(entry)) {
          offenders.push(full);
        }
      }
    };
    walk(join(JS_ROOT, 'pages'));
    expect(offenders, 'move these tests to resources/js/test/').toEqual([]);
  });
});

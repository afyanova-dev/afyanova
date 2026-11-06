import { cleanup } from '@testing-library/react';
import '@testing-library/jest-dom/vitest';
import { afterEach } from 'vitest';

afterEach(cleanup);

if (!Element.prototype.scrollIntoView) {
  Element.prototype.scrollIntoView = () => {};
}

import type { SharedProps } from './auth';

declare module '@inertiajs/core' {
  // Augmentation must be an interface; it inherits all shared props.
  // eslint-disable-next-line @typescript-eslint/no-empty-object-type
  interface PageProps extends SharedProps {}
}

export {};

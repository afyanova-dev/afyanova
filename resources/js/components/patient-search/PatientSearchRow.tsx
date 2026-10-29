import type { ReactNode } from 'react';
import type { PatientSearchRow } from './patientSearch';

type IdentityRow = Pick<
  PatientSearchRow,
  'displayName' | 'patientNumber' | 'sex' | 'age' | 'formattedDob' | 'photoUrl'
>;

/** Shared patient identity block: photo/initial plus the two-line summary. */
export function PatientSearchPhoto({
  row,
  className,
}: {
  row: Pick<IdentityRow, 'displayName' | 'photoUrl'>;
  className?: string;
}): ReactNode {
  if (!row.photoUrl) {
    return (
      <span
        aria-hidden="true"
        className={className}
        style={{
          inlineSize: '2rem',
          blockSize: '2rem',
          flexShrink: 0,
          display: 'grid',
          placeItems: 'center',
        }}
      >
        {row.displayName.charAt(0).toUpperCase()}
      </span>
    );
  }
  return (
    <img
      src={row.photoUrl}
      alt=""
      width={32}
      height={32}
      className={className}
      style={{ flexShrink: 0 }}
    />
  );
}

export function PatientSearchText({ row }: { row: Omit<IdentityRow, 'photoUrl'> }): ReactNode {
  return (
    <span style={{ minInlineSize: 0 }}>
      <strong style={{ display: 'block', overflow: 'hidden', textOverflow: 'ellipsis' }}>{row.displayName}</strong>
      <span style={{ display: 'block', overflow: 'hidden', textOverflow: 'ellipsis' }}>
        {row.patientNumber}
        {row.sex ? ` · ${row.sex}` : null}
        {row.age ? ` · ${row.age}` : row.formattedDob ? ` · ${row.formattedDob}` : null}
      </span>
    </span>
  );
}

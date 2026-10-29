import type { ReactNode } from 'react';
import { FacilityDetail } from '@/features/platform-admin/components/FacilityDetail';
import type { FacilityDetails, FacilityReferences } from '@/features/platform-admin/types/platform-admin';

interface FacilityShowProps {
  facility: FacilityDetails;
  facilityReferences: FacilityReferences;
}

export default function FacilityShow({ facility, facilityReferences }: FacilityShowProps): ReactNode {
  return <FacilityDetail facility={facility} facilityReferences={facilityReferences} />;
}

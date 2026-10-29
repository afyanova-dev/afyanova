import { PageHeader } from '@/components/ui';
import { HRWorkflowFeedback } from '@/features/hr/components/HRWorkflowFeedback';
import { PositionsManager } from '@/features/hr/components/PositionsManager';
import type { CadreOption, FacilitySummary, PositionOption } from '@/features/hr/types';
import { AppLayout } from '@/layouts/AppLayout/AppLayout';

interface PositionsPageProps {
  facility: FacilitySummary;
  cadres: CadreOption[];
  positions: PositionOption[];
  errors?: Record<string, string>;
}

export default function PositionsPage({
  facility,
  cadres,
  positions,
  errors,
}: PositionsPageProps): React.JSX.Element {
  return (
    <AppLayout>
      <main className="afyanova-page facility-staff">
        <PageHeader
          title="Job cadres & positions"
          description={`Manage job cadres and positions for ${facility.name}.`}
        />
        <HRWorkflowFeedback errors={errors} />
        <PositionsManager cadres={cadres} positions={positions} />
      </main>
    </AppLayout>
  );
}

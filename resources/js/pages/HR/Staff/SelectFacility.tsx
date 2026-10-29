import { Button, RadioButton, RadioButtonGroup } from '@carbon/react';
import { router } from '@inertiajs/react';
import { useState } from 'react';
import { routes } from '@/app/routes';
import { PageHeader } from '@/components/ui';
import { AppLayout } from '@/layouts/AppLayout/AppLayout';
import { ArrowRight } from '@/icons';

interface FacilityOption {
  id: string;
  name: string;
}

interface SelectFacilityPageProps {
  facilities: FacilityOption[];
}

export default function SelectFacilityPage({ facilities }: SelectFacilityPageProps): React.JSX.Element {
  const [selectedFacility, setSelectedFacility] = useState('');

  function selectFacility(): void {
    if (selectedFacility === '') return;
    router.post(routes.facilityContext, { facility_id: selectedFacility });
  }

  return (
    <AppLayout>
      <main className="afyanova-page facility-staff">
        <PageHeader
          title="Select a facility"
          description="Choose which facility you want to manage staff for."
        />
        <section className="facility-staff__section" aria-labelledby="select-facility-heading">
          <h2 id="select-facility-heading">Available facilities</h2>
          <RadioButtonGroup
            legendText="Facility"
            name="hr-facility"
            valueSelected={selectedFacility || undefined}
            onChange={(value) => setSelectedFacility(String(value))}
          >
            {facilities.map((facility) => (
              <RadioButton
                key={facility.id}
                id={`hr-facility-${facility.id}`}
                value={facility.id}
                labelText={facility.name}
              />
            ))}
          </RadioButtonGroup>
          <div className="facility-staff__form-actions">
            <Button size="lg" renderIcon={ArrowRight} disabled={!selectedFacility} onClick={selectFacility}>Continue</Button>
          </div>
        </section>
      </main>
    </AppLayout>
  );
}
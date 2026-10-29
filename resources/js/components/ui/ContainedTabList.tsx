import { Tab as CarbonTab, TabList as CarbonTabList } from '@carbon/react';
import type { ComponentProps, ReactNode } from 'react';

type CarbonTabProps = ComponentProps<typeof CarbonTab>;
type CarbonTabListProps = ComponentProps<typeof CarbonTabList>;

interface IconTabProps extends Omit<CarbonTabProps, 'renderIcon'> {
  icon: NonNullable<CarbonTabProps['renderIcon']>;
}

export function ContainedTabList(props: CarbonTabListProps): ReactNode {
  return <CarbonTabList {...props} contained />;
}

export function IconTab({ icon, ...props }: IconTabProps): ReactNode {
  return <CarbonTab {...props} renderIcon={icon} />;
}

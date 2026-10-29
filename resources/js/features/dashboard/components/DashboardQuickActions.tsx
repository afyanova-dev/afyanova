import { router } from '@inertiajs/react';
import { Button, ComboButton, MenuItem } from '@carbon/react';
import type { ReactNode } from 'react';
import type { QuickAction } from '@/features/dashboard/types/dashboard';
import { Add, ArrowRight, Calendar, Time, UserFollow } from '@/icons';

/** Icon per quick action id: glyph language matches the app header. */
function actionIcon(id: string): typeof ArrowRight {
  switch (id) {
    case 'register-patient':
      return UserFollow;
    case 'hr-add-staff':
      return Add;
    case 'check-in-walk-in':
      return Time;
    case 'book-appointment':
      return Calendar;
    default:
      return ArrowRight;
  }
}

/**
 * Page-title actions: the first permitted action renders as the primary
 * action; any further actions collapse into a Carbon combo button menu so the
 * header keeps one decision. Icon glyphs carry onto buttons and menu items.
 * Notably covers roles (e.g. check-in-only) that earn no workspace link.
 * Renders nothing when the role holds no action.
 */
export function DashboardQuickActions({
  actions,
}: {
  actions: QuickAction[];
}): ReactNode {
  const [primary, ...overflow] = actions;
  if (!primary) return null;

  return (
    <div className="afy-page__actions">
      {overflow.length > 0 ? (
        <ComboButton
          label={primary.title}
          size="lg"
          menuAlignment="bottom-end"
          onClick={() => router.visit(primary.href)}
        >
          {overflow.map((action) => (
            <MenuItem
              key={action.id}
              label={action.title}
              renderIcon={actionIcon(action.id)}
              onClick={() => router.visit(action.href)}
            />
          ))}
        </ComboButton>
      ) : (
        <Button size="lg" renderIcon={actionIcon(primary.id)}
          kind="primary"
          type="button"
          onClick={() => router.visit(primary.href)}
        >
          {primary.title}
        </Button>
      )}
    </div>
  );
}
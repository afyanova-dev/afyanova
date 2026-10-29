/**
 * Mirrored pharmacy option lists. The backend owns these sets
 * (`PharmacyOrderReason`, FormRequest `in:` rules) — this module only labels
 * them for selects. Unknown codes fail server-side and surface through the
 * `order` error bag; never invent codes here.
 */

export interface CodedOption {
  value: string;
  label: string;
}

export const ORDER_PRIORITIES: readonly CodedOption[] = [
  { value: 'ROUTINE', label: 'Routine' },
  { value: 'URGENT', label: 'Urgent' },
  { value: 'STAT', label: 'Stat' },
];

export const ORDER_REJECT_REASONS: readonly CodedOption[] = [
  { value: 'INVALID_PRESCRIPTION', label: 'Invalid prescription' },
  { value: 'EXPIRED_PRESCRIPTION', label: 'Expired prescription' },
  { value: 'SAFETY', label: 'Safety concern' },
  { value: 'CLINICAL_CLARIFICATION', label: 'Needs clinical clarification' },
  { value: 'DUPLICATE', label: 'Duplicate order' },
  { value: 'OTHER', label: 'Other' },
];

export const ORDER_HOLD_REASONS: readonly CodedOption[] = [
  { value: 'PAYMENT_REQUIRED', label: 'Payment required' },
  { value: 'INSURANCE_PENDING', label: 'Insurance pending' },
  { value: 'STOCK_UNAVAILABLE', label: 'Stock unavailable' },
  { value: 'CLINICAL_CLARIFICATION', label: 'Needs clinical clarification' },
  { value: 'SAFETY_REVIEW', label: 'Safety review' },
  { value: 'PATIENT_VERIFICATION', label: 'Patient verification' },
  { value: 'OTHER', label: 'Other' },
];

export const ORDER_CANCEL_REASONS: readonly CodedOption[] = [
  { value: 'PATIENT_DECLINED', label: 'Patient declined' },
  { value: 'PRESCRIPTION_CANCELLED', label: 'Prescription cancelled' },
  { value: 'STOCK_UNAVAILABLE', label: 'Stock unavailable' },
  { value: 'CLINICAL_INSTRUCTION', label: 'Clinical instruction' },
  { value: 'DUPLICATE', label: 'Duplicate order' },
  { value: 'OTHER', label: 'Other' },
];

export type OrderReasonAction = 'reject' | 'hold' | 'cancel';

export const ORDER_REASON_OPTIONS: Record<OrderReasonAction, readonly CodedOption[]> = {
  reject: ORDER_REJECT_REASONS,
  hold: ORDER_HOLD_REASONS,
  cancel: ORDER_CANCEL_REASONS,
};

export const ORDER_REASON_ACTION_LABEL: Record<OrderReasonAction, string> = {
  reject: 'Reject order',
  hold: 'Hold order',
  cancel: 'Cancel order',
};

export const DISPENSING_REVERSAL_REASONS: readonly CodedOption[] = [
  { value: 'DISPENSING_ERROR', label: 'Dispensing error' },
  { value: 'WRONG_ITEM', label: 'Wrong item' },
  { value: 'WRONG_DOSE', label: 'Wrong dose' },
  { value: 'WRONG_PATIENT', label: 'Wrong patient' },
  { value: 'NOT_COLLECTED', label: 'Not collected' },
  { value: 'OTHER', label: 'Other' },
];

export const PICKUP_RECIPIENT_TYPES: readonly CodedOption[] = [
  { value: 'PATIENT', label: 'Patient' },
  { value: 'GUARDIAN', label: 'Guardian' },
  { value: 'AUTHORIZED_REP', label: 'Authorized representative' },
  { value: 'WARD', label: 'Ward' },
  { value: 'CLINICAL_UNIT', label: 'Clinical unit' },
];

export const PICKUP_OUTCOME_REASONS: readonly CodedOption[] = [
  { value: 'PATIENT_NO_SHOW', label: 'Patient no-show' },
  { value: 'PATIENT_DECLINED', label: 'Patient declined' },
  { value: 'PRESCRIPTION_CANCELLED', label: 'Prescription cancelled' },
  { value: 'DUPLICATE', label: 'Duplicate' },
  { value: 'DISPENSING_REVERSED', label: 'Dispensing reversed' },
  { value: 'OTHER', label: 'Other' },
];

export type PickupOutcomeAction = 'not-collected' | 'cancel-pickup';

export const PICKUP_OUTCOME_ACTION_LABEL: Record<PickupOutcomeAction, string> = {
  'not-collected': 'Mark not collected',
  'cancel-pickup': 'Cancel pickup',
};

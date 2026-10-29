interface HRWorkflowFeedbackProps {
  errors?: Record<string, string>;
}

/**
 * Inline validation errors for HR forms. Flash status/info/error itself is
 * rendered once as a toast by AppLayout — never duplicated inline here.
 */
export function HRWorkflowFeedback({ errors = {} }: HRWorkflowFeedbackProps): React.JSX.Element | null {
  const messages = Object.values(errors).filter(Boolean);
  if (messages.length === 0) return null;

  return <p role="alert">{messages.join(' ')}</p>;
}

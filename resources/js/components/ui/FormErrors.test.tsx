import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { FormErrors } from './FormErrors';

describe('FormErrors', () => {
  it('renders non-field errors and hides known fields', () => {
    render(
      <FormErrors
        errors={{
          email: 'Required.',
          handoff_error: 'The consultation transfer failed.',
        }}
        fields={['email']}
      />,
    );
    expect(screen.getByRole('alert')).toHaveTextContent('The consultation transfer failed.');
    expect(screen.queryByText('Required.')).not.toBeInTheDocument();
  });

  it('renders nothing when only field errors exist', () => {
    const { container } = render(
      <FormErrors errors={{ email: 'Required.' }} fields={['email']} />,
    );
    expect(container).toBeEmptyDOMElement();
  });
});

import { useForm } from '@inertiajs/react';
import { Button, Form, PasswordInput, TextInput } from '@carbon/react';
import type { FormEvent, ReactNode } from 'react';
import { AuthLayout } from '@/layouts/AuthLayout';
import { FormErrors } from '@/components/ui';
import { routes } from '@/app/routes';
import { Checkmark } from '@/icons';

interface ResetPasswordProps {
  email: string;
  token: string;
}

export default function ResetPassword({ email, token }: ResetPasswordProps): ReactNode {
  const { data, setData, post, processing, errors } = useForm({
    token,
    email,
    password: '',
    password_confirmation: '',
  });

  function submit(event: FormEvent): void {
    event.preventDefault();
    post(routes.password.update);
  }

  return (
    <AuthLayout>
      <h1>Set a new password</h1>
      <p>Choose a new password for your account.</p>
      <FormErrors errors={errors} fields={['email', 'password', 'password_confirmation', 'token']} />
      <Form noValidate onSubmit={submit} aria-label="Reset password">
        <TextInput
          id="email"
          labelText="Email"
          size="lg"
          type="email"
          autoComplete="email"
          value={data.email}
          onChange={(e) => setData('email', e.target.value)}
          invalid={Boolean(errors.email)}
          invalidText={errors.email}
          required
        />
        <PasswordInput
          id="password"
          labelText="New password"
          helperText="Use at least 8 characters."
          size="lg"
          autoComplete="new-password"
          value={data.password}
          onChange={(e) => setData('password', e.target.value)}
          invalid={Boolean(errors.password)}
          invalidText={errors.password}
          required
        />
        <PasswordInput
          id="password_confirmation"
          labelText="Confirm new password"
          size="lg"
          autoComplete="new-password"
          value={data.password_confirmation}
          onChange={(e) => setData('password_confirmation', e.target.value)}
          invalid={Boolean(errors.password_confirmation)}
          invalidText={errors.password_confirmation}
          required
        />
        <Button size="lg" renderIcon={Checkmark} type="submit" disabled={processing}>
          {processing ? 'Saving…' : 'Save new password'}
        </Button>
      </Form>
    </AuthLayout>
  );
}
import { useEffect, useState, type FormEvent, type ReactNode } from 'react';
import { useForm } from '@inertiajs/react';
import { Button, Form, InlineNotification, PasswordInput } from '@carbon/react';
import { routes } from '@/app/routes';
import { AuthLayout } from '@/layouts/AuthLayout';
import { FormErrors } from '@/components/ui';
import { Checkmark } from '@/icons';

export default function AdminBootstrap(): ReactNode {
  const [token] = useState(() => window.location.hash.slice(1));
  const { data, setData, post, processing, errors } = useForm({
    token,
    password: '',
    password_confirmation: '',
  });

  useEffect(() => {
    if (token !== '') {
      window.history.replaceState(null, '', `${window.location.pathname}${window.location.search}`);
    }
  }, [token]);

  function submit(event: FormEvent): void {
    event.preventDefault();
    post(routes.adminBootstrap.complete);
  }

  return (
    <AuthLayout>
      <h1>Set up your account</h1>
      <p>This one-time link verifies your email address and lets you choose a password.</p>
      {!token ? (
        <InlineNotification
          kind="error"
          lowContrast
          hideCloseButton
          role="alert"
          title="Setup link unavailable"
          subtitle="The setup link is missing or has already been removed. Ask the administrator who invited you to send a new invitation."
        />
      ) : (
        <>
          <FormErrors errors={errors} fields={['password', 'password_confirmation']} />
          <Form noValidate onSubmit={submit} aria-label="Set account password">
            <PasswordInput
              id="password"
              labelText="New password"
              helperText="Use at least 8 characters."
              size="lg"
              autoComplete="new-password"
              value={data.password}
              onChange={(event) => setData('password', event.target.value)}
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
              onChange={(event) => setData('password_confirmation', event.target.value)}
              invalid={Boolean(errors.password_confirmation)}
              invalidText={errors.password_confirmation}
              required
            />
            <Button size="lg" renderIcon={Checkmark} type="submit" disabled={processing}>
              {processing ? 'Setting up account…' : 'Verify email and set password'}
            </Button>
          </Form>
        </>
      )}
    </AuthLayout>
  );
}
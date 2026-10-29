import { useForm } from '@inertiajs/react';
import { Button, Form, PasswordInput } from '@carbon/react';
import type { FormEvent, ReactNode } from 'react';
import { AuthLayout } from '@/layouts/AuthLayout';
import { FormErrors } from '@/components/ui';
import { routes } from '@/app/routes';
import { Checkmark } from '@/icons';

export default function ConfirmPassword(): ReactNode {
  const { data, setData, post, processing, errors } = useForm({ password: '' });

  function submit(event: FormEvent): void {
    event.preventDefault();
    post(routes.password.confirm);
  }

  return (
    <AuthLayout>
      <h1>Confirm password</h1>
      <p>This area requires recent authentication. Enter your password to continue.</p>
      <FormErrors errors={errors} fields={['password']} />
      <Form noValidate onSubmit={submit} aria-label="Confirm password">
        <PasswordInput
          id="password"
          labelText="Password"
          size="lg"
          autoComplete="current-password"
          value={data.password}
          onChange={(e) => setData('password', e.target.value)}
          invalid={Boolean(errors.password)}
          invalidText={errors.password}
          required
        />
        <Button size="lg" renderIcon={Checkmark} type="submit" disabled={processing}>
          {processing ? 'Confirming…' : 'Confirm'}
        </Button>
      </Form>
    </AuthLayout>
  );
}
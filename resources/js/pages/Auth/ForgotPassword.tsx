import { Link, useForm } from '@inertiajs/react';
import { Button, Form, TextInput } from '@carbon/react';
import type { FormEvent, ReactNode } from 'react';
import { AuthLayout } from '@/layouts/AuthLayout';
import { FormErrors } from '@/components/ui';
import { routes } from '@/app/routes';
import { Send } from '@/icons';

export default function ForgotPassword(): ReactNode {
  const { data, setData, post, processing, errors } = useForm({ email: '' });

  function submit(event: FormEvent): void {
    event.preventDefault();
    post(routes.password.email);
  }

  return (
    <AuthLayout>
      <h1>Reset password</h1>
      <p>Enter your account email and we will send a reset link.</p>
      <FormErrors errors={errors} fields={['email']} />
      <Form noValidate onSubmit={submit} aria-label="Request password reset">
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
        <Button size="lg" renderIcon={Send} type="submit" disabled={processing}>
          {processing ? 'Sending…' : 'Send reset link'}
        </Button>
      </Form>
      <p className="afy-auth__nav">
        <Link href={routes.login} className="cds--link">
          Back to log in
        </Link>
      </p>
    </AuthLayout>
  );
}

import { Link, useForm } from '@inertiajs/react';
import { Button, Checkbox, Form, PasswordInput, TextInput } from '@carbon/react';
import type { FormEvent, ReactNode } from 'react';
import { AuthLayout } from '@/layouts/AuthLayout';
import { FormErrors } from '@/components/ui';
import { routes } from '@/app/routes';
import { Login as LoginIcon } from '@/icons';

interface LoginProps {
  canResetPassword: boolean;
}

export default function Login({ canResetPassword }: LoginProps): ReactNode {
  const { data, setData, post, processing, errors } = useForm({
    email: '',
    password: '',
    remember: false,
  });

  function submit(event: FormEvent): void {
    event.preventDefault();
    post(routes.login);
  }

  return (
    <AuthLayout>
      <h1>Log in</h1>
      <FormErrors errors={errors} fields={['email', 'password', 'remember']} />
      <Form noValidate onSubmit={submit} aria-label="Log in">
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
          labelText="Password"
          size="lg"
          autoComplete="current-password"
          value={data.password}
          onChange={(e) => setData('password', e.target.value)}
          invalid={Boolean(errors.password)}
          invalidText={errors.password}
          required
        />
        {canResetPassword ? (
          <div className="afy-auth__field-actions">
            <Link href={routes.password.request} className="cds--link">
              Forgot your password?
            </Link>
          </div>
        ) : null}
        <Checkbox
          id="remember"
          labelText="Keep me signed in"
          checked={data.remember}
          onChange={(_e, { checked }: { checked: boolean }) => setData('remember', checked)}
        />
        <Button size="lg" renderIcon={LoginIcon} type="submit" disabled={processing}>
          {processing ? 'Logging in…' : 'Log in'}
        </Button>
      </Form>
    </AuthLayout>
  );
}
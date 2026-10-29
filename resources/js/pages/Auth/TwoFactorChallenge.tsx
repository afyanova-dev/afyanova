import { useForm } from '@inertiajs/react';
import {
  Button,
  ContentSwitcher,
  Form,
  Switch,
  TextInput,
} from '@carbon/react';
import { useState, type FormEvent, type ReactNode } from 'react';
import { AuthLayout } from '@/layouts/AuthLayout';
import { FormErrors } from '@/components/ui';
import { routes } from '@/app/routes';
import { Checkmark } from '@/icons';

export default function TwoFactorChallenge(): ReactNode {
  const [useRecovery, setUseRecovery] = useState(false);
  const { data, setData, post, processing, errors } = useForm({
    code: '',
    recovery_code: '',
  });

  function chooseMethod(index: number): void {
    setData('code', '');
    setData('recovery_code', '');
    setUseRecovery(index === 1);
  }

  function submit(event: FormEvent): void {
    event.preventDefault();
    post(routes.twoFactorChallenge);
  }

  return (
    <AuthLayout>
      <div className="afyanova-auth-step">
        <h1>Verify it’s you</h1>
        <p>Confirm your identity to continue to your workspace.</p>
      </div>
      <FormErrors errors={errors} fields={['code', 'recovery_code']} />
      <Form noValidate onSubmit={submit} aria-label="Two-factor challenge">
        <ContentSwitcher
          size="lg"
          selectedIndex={useRecovery ? 1 : 0}
          onChange={({ index }) => chooseMethod(index ?? 0)}
          aria-label="Verification method"
        >
          <Switch name="authenticator" text="Authenticator app" />
          <Switch name="recovery" text="Recovery code" />
        </ContentSwitcher>
        {useRecovery ? (
          <TextInput
            id="recovery_code"
            labelText="One-time recovery code"
            helperText="Each recovery code can only be used once."
            size="lg"
            autoComplete="one-time-code"
            value={data.recovery_code}
            onChange={(e) => setData('recovery_code', e.target.value)}
            invalid={Boolean(errors.recovery_code)}
            invalidText={errors.recovery_code}
            required
          />
        ) : (
          <TextInput
            id="code"
            labelText="6-digit authentication code"
            helperText="Codes refresh every 30 seconds."
            size="lg"
            inputMode="numeric"
            autoComplete="one-time-code"
            maxLength={6}
            value={data.code}
            onChange={(e) => setData('code', e.target.value.replace(/\D/g, '').slice(0, 6))}
            invalid={Boolean(errors.code)}
            invalidText={errors.code}
            required
          />
        )}
        {/* The primary action is always the form's last element. */}
        <Button size="lg" renderIcon={Checkmark} type="submit" disabled={processing}>
          {processing ? 'Verifying…' : 'Verify and continue'}
        </Button>
      </Form>
    </AuthLayout>
  );
}

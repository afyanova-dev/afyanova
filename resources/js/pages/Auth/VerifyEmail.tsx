import { Link, router } from '@inertiajs/react';
import { Button } from '@carbon/react';
import { useState, type ReactNode } from 'react';
import { AuthLayout } from '@/layouts/AuthLayout';
import { routes } from '@/app/routes';
import { Send } from '@/icons';

export default function VerifyEmail(): ReactNode {
  const [sending, setSending] = useState(false);

  function resend(): void {
    if (sending) return;
    setSending(true);
    router.post(routes.verification.send, {}, {
      onFinish: () => setSending(false),
    });
  }

  return (
    <AuthLayout>
      <h1>Verify your email</h1>
      <p>Check your inbox for a verification link, or request a new one below.</p>
      <Button size="lg" renderIcon={Send}
        onClick={resend}
        disabled={sending}
      >
        {sending ? 'Sending…' : 'Resend verification email'}
      </Button>
      <p className="afy-auth__nav">
        <Link href={routes.dashboard} className="cds--link">
          Continue
        </Link>
      </p>
    </AuthLayout>
  );
}

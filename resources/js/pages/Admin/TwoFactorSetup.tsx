import { useForm, router } from '@inertiajs/react';
import {
  Button,
  Checkbox,
  CodeSnippet,
  Form,
  InlineNotification,
  InlineLoading,
  SkeletonText,
  TextInput,
} from '@carbon/react';
import {
  useCallback,
  useEffect,
  useRef,
  useState,
  type FormEvent,
  type ReactNode,
} from 'react';
import { AuthLayout } from '@/layouts/AuthLayout';
import { FormErrors } from '@/components/ui';
import { routes } from '@/app/routes';
import { getJson } from '@/lib/http/json';
import {
  Checkmark,
  Copy,
  Document,
  Download,
  SettingsAdjust,
  Timer,
  TwoFactorAuthentication,
} from '@/icons';

interface TwoFactorSetupProps {
  setupPending: boolean;
  continueTo?: string;
}

interface TwoFactorQrResponse {
  svg: string;
}

interface TwoFactorSecretResponse {
  secretKey: string;
}

const SETUP_STEPS = [
  'Connect authenticator',
  'Save backup codes',
  'Verify code',
] as const;

const REQUIREMENTS = [
  {
    icon: TwoFactorAuthentication,
    text: 'An authenticator app such as Microsoft Authenticator, Google Authenticator, 1Password, or any TOTP-compatible app.',
  },
  {
    icon: Timer,
    text: 'About five minutes — this page stays open while you set things up.',
  },
  {
    icon: Document,
    text: 'A private, offline place to store your recovery codes, such as a password manager or a printed copy.',
  },
] as const;

export default function TwoFactorSetup({ setupPending, continueTo }: TwoFactorSetupProps): ReactNode {
  const isPlatformAdmin = continueTo === routes.platformAdmin.index;
  const [setupStarted, setSetupStarted] = useState(setupPending);
  const [startingSetup, setStartingSetup] = useState(false);
  const [qrCodeUrl, setQrCodeUrl] = useState<string | null>(null);
  const [secretKey, setSecretKey] = useState<string | null>(null);
  const [recoveryCodes, setRecoveryCodes] = useState<string[]>([]);
  const [recoveryCodesSaved, setRecoveryCodesSaved] = useState(false);
  const [copiedCodes, setCopiedCodes] = useState(false);
  const [loadError, setLoadError] = useState<string | null>(null);
  const copiedTimerRef = useRef<number | null>(null);
  const { data, setData, post, processing, errors } = useForm({ code: '' });

  useEffect(() => {
    if (!setupStarted) return undefined;

    let active = true;
    let objectUrl: string | null = null;

    void Promise.all([
      getJson<TwoFactorQrResponse>(routes.twoFactor.qrCode),
      getJson<TwoFactorSecretResponse>(routes.twoFactor.secretKey),
      getJson<string[]>(routes.twoFactor.recoveryCodes),
    ]).then(([qr, secret, codes]) => {
      if (!active) return;
      objectUrl = URL.createObjectURL(new Blob([qr.svg], { type: 'image/svg+xml' }));
      setQrCodeUrl(objectUrl);
      setSecretKey(secret.secretKey);
      setRecoveryCodes(codes);
      setLoadError(null);
    }).catch((error: unknown) => {
      if (active) {
        setLoadError(error instanceof Error ? error.message : 'Unable to load the two-factor setup details.');
      }
    });

    return () => {
      active = false;
      if (objectUrl) URL.revokeObjectURL(objectUrl);
    };
  }, [setupStarted]);

  useEffect(() => () => {
    if (copiedTimerRef.current !== null) window.clearTimeout(copiedTimerRef.current);
  }, []);

  const copyRecoveryCodes = useCallback(async (): Promise<void> => {
    try {
      await navigator.clipboard.writeText(recoveryCodes.join('\n'));
    } catch {
      const scratch = document.createElement('textarea');
      scratch.value = recoveryCodes.join('\n');
      scratch.setAttribute('readonly', '');
      scratch.style.position = 'fixed';
      scratch.style.opacity = '0';
      document.body.appendChild(scratch);
      scratch.select();
      document.execCommand('copy');
      document.body.removeChild(scratch);
    }
    setCopiedCodes(true);
    if (copiedTimerRef.current !== null) window.clearTimeout(copiedTimerRef.current);
    copiedTimerRef.current = window.setTimeout(() => setCopiedCodes(false), 2000);
  }, [recoveryCodes]);

  const downloadRecoveryCodes = useCallback((): void => {
    const file = new Blob(
      [
        'AfyaNova two-factor authentication recovery codes\n',
        'Each code works once. Store this file somewhere private.\n\n',
        ...recoveryCodes.map((code) => `${code}\n`),
      ],
      { type: 'text/plain' },
    );
    const link = document.createElement('a');
    link.href = URL.createObjectURL(file);
    link.download = 'recovery-codes.txt';
    link.click();
    URL.revokeObjectURL(link.href);
  }, [recoveryCodes]);

  /* Stepper state: exactly one step is current; completed steps are behind it. */
  const stepsComplete = [
    Boolean(qrCodeUrl && secretKey),
    recoveryCodesSaved,
    false,
  ];
  const firstIncomplete = stepsComplete.findIndex((complete) => !complete);

  function beginSetup(): void {
    setStartingSetup(true);
    router.post(routes.twoFactor.enable, {}, {
      preserveScroll: true,
      onSuccess: () => setSetupStarted(true),
      onError: () => setStartingSetup(false),
      onFinish: () => setStartingSetup(false),
    });
  }

  function confirmSetup(event: FormEvent): void {
    event.preventDefault();
    post(routes.twoFactor.confirm, {
      onSuccess: () => router.visit(continueTo ?? routes.workspaces.admin),
    });
  }

  function renderStepBadge(step: number): ReactNode {
    const isComplete = step - 1 < firstIncomplete;
    if (isComplete) {
      return (
        <span className="afyanova-auth-setup__step-badge" data-state="complete">
          <Checkmark size={12} />
        </span>
      );
    }
    return (
      <span
        className="afyanova-auth-setup__step-badge"
        data-state={step - 1 === firstIncomplete ? 'current' : 'upcoming'}
      >
        {step}
      </span>
    );
  }

  return (
    <AuthLayout>
      <div className="afyanova-auth-setup">
        {!setupStarted ? (
          <>
            <div className="afyanova-auth-step">
              <p className="afyanova-auth-step__label">Account security</p>
              <h1>
                Secure your {isPlatformAdmin ? 'platform administrator' : 'administrator'} account
              </h1>
              <p>
                Two-factor authentication is required for {isPlatformAdmin ? 'platform administration' : 'the Admin workspace'}.
                Connect an authenticator app, save your backup codes, then verify your setup.
              </p>
            </div>

            <ul className="afyanova-auth-setup__requirements" aria-label="What you will need">
              {REQUIREMENTS.map(({ icon: Icon, text }) => (
                <li key={text}>
                  <span className="afyanova-auth-setup__requirement-icon" aria-hidden="true">
                    <Icon size={20} />
                  </span>
                  <span>{text}</span>
                </li>
              ))}
            </ul>

            <Button
              size="lg"
              renderIcon={SettingsAdjust}
              type="button"
              onClick={beginSetup}
              disabled={startingSetup}
            >
              {startingSetup ? 'Starting…' : 'Start two-factor setup'}
            </Button>
          </>
        ) : (
          <>
            <ol className="afyanova-auth-setup__stepper" aria-label="Setup steps">
              {SETUP_STEPS.map((label, index) => (
                <li
                  key={label}
                  data-state={index === firstIncomplete ? 'current' : index < firstIncomplete ? 'complete' : 'upcoming'}
                  aria-current={index === firstIncomplete ? 'step' : undefined}
                >
                  {renderStepBadge(index + 1)}
                  <span className="afyanova-auth-setup__step-label">{label}</span>
                </li>
              ))}
            </ol>

            {loadError ? (
              <InlineNotification
                kind="error"
                title="Two-factor setup could not be loaded"
                subtitle={loadError}
                lowContrast
              />
            ) : null}

            <section className="afyanova-auth-setup__qr" aria-labelledby="authenticator-step-title">
              <div className="afyanova-auth-setup__qr-figure">
                {qrCodeUrl ? (
                  <img src={qrCodeUrl} alt="Authenticator app setup QR code" width={220} height={220} />
                ) : (
                  <InlineLoading
                    status="active"
                    description="Preparing secure setup"
                    className="afyanova-auth-setup__qr-loading"
                  />
                )}
              </div>
              <div>
                <h2 id="authenticator-step-title">1. Connect your authenticator</h2>
                <p>
                  Scan this code in your authenticator app. If you can’t scan it,
                  enter this setup key manually.
                </p>
                {secretKey ? (
                  <CodeSnippet type="single" wrapText aria-label="Authenticator setup key">
                    {secretKey}
                  </CodeSnippet>
                ) : (
                  <SkeletonText heading={false} lineCount={1} width="60%" />
                )}
              </div>
            </section>

            <section className="afyanova-auth-setup__codes" aria-labelledby="recovery-codes-title">
              <div className="afyanova-auth-setup__codes-header">
                <h2 id="recovery-codes-title">2. Save your backup codes</h2>
                {recoveryCodes.length > 0 ? (
                  <div className="afyanova-auth-setup__codes-actions">
                    <Button
                      kind="tertiary"
                      size="sm"
                      renderIcon={copiedCodes ? Checkmark : Copy}
                      type="button"
                      onClick={() => void copyRecoveryCodes()}
                    >
                      {copiedCodes ? 'Copied' : 'Copy all'}
                    </Button>
                    <Button
                      kind="tertiary"
                      size="sm"
                      renderIcon={Download}
                      type="button"
                      onClick={downloadRecoveryCodes}
                    >
                      Download
                    </Button>
                  </div>
                ) : null}
              </div>
              <InlineNotification
                kind="warning"
                title="Keep your recovery codes safe"
                subtitle="Each code works once. Store them somewhere private and offline so you can recover access if you lose your device."
                lowContrast
              />
              {recoveryCodes.length > 0 ? (
                <ul aria-label="Two-factor recovery codes">
                  {recoveryCodes.map((code) => <li key={code}>{code}</li>)}
                </ul>
              ) : (
                <p role="status">Loading your recovery codes…</p>
              )}
            </section>

            <div className="afyanova-auth-setup__acknowledgement">
              <Checkbox
                id="admin-recovery-codes-saved"
                labelText="I have saved my recovery codes somewhere private"
                checked={recoveryCodesSaved}
                onChange={(_, { checked }) => setRecoveryCodesSaved(checked)}
                disabled={recoveryCodes.length === 0}
              />
            </div>

            <div className="afyanova-auth-step">
              <p className="afyanova-auth-step__label">Final step</p>
              <h2>3. Verify your authenticator</h2>
              <p>Enter the current code shown in your authenticator app to finish setup.</p>
            </div>

            <FormErrors errors={errors} fields={['code']} />
            <Form noValidate onSubmit={confirmSetup} aria-label="Confirm two-factor setup">
              <TextInput
                id="admin-two-factor-code"
                labelText="6-digit authentication code"
                helperText="Codes refresh every 30 seconds."
                inputMode="numeric"
                autoComplete="one-time-code"
                maxLength={6}
                value={data.code}
                onChange={(event) => setData('code', event.target.value.replace(/\D/g, '').slice(0, 6))}
                invalid={Boolean(errors.code)}
                invalidText={errors.code}
                required
              />
              <Button
                size="lg"
                renderIcon={Checkmark}
                type="submit"
                disabled={processing || !recoveryCodesSaved || !secretKey || !qrCodeUrl}
              >
                {processing ? 'Confirming…' : 'Confirm and continue'}
              </Button>
            </Form>
          </>
        )}
      </div>
    </AuthLayout>
  );
}

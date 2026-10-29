import { fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { ReactNode } from 'react';
import ConfirmPassword from '../pages/Auth/ConfirmPassword';
import AdminBootstrap from '../pages/Auth/AdminBootstrap';
import ForgotPassword from '../pages/Auth/ForgotPassword';
import Login from '../pages/Auth/Login';
import ResetPassword from '../pages/Auth/ResetPassword';
import TwoFactorChallenge from '../pages/Auth/TwoFactorChallenge';
import VerifyEmail from '../pages/Auth/VerifyEmail';
import Forbidden from '../pages/Errors/403';

const calls = vi.hoisted(() => ({
  formPost: vi.fn(),
  routerPost: vi.fn(),
  setData: vi.fn(),
}));

vi.mock('@inertiajs/react', () => ({
  Link: ({ children }: { children: ReactNode }) => <>{children}</>,
  router: { post: calls.routerPost },
  useForm: (initial: Record<string, unknown>) => ({
    data: initial,
    setData: calls.setData,
    post: calls.formPost,
    processing: false,
    errors: {},
  }),
  usePage: () => ({
    props: {
      app: { name: 'AfyaNova' },
      auth: {
        user: null,
        roles: [],
        can: {},
        facilities: [],
        activeFacility: null,
        contextMode: 'unresolved',
        canUseGeneral: false,
      },
      flash: {},
      correlationId: null,
    },
    url: '/login',
  }),
}));

function typeInto(label: string, value: string): void {
  fireEvent.change(screen.getByLabelText(label), { target: { value } });
}

function submit(): void {
  fireEvent.submit(document.querySelector('form') as HTMLFormElement);
}

beforeEach(() => {
  vi.clearAllMocks();
  document.body.innerHTML = '';
});

describe('auth forms defer to backend validation', () => {
  const cases: Array<[string, React.ReactNode]> = [
    ['Log in', <Login canResetPassword />],
    ['Two-factor challenge', <TwoFactorChallenge />],
    ['Request password reset', <ForgotPassword />],
    ['Reset password', <ResetPassword email="" token="" />],
    ['Confirm password', <ConfirmPassword />],
  ];

  it.each(cases)('%s form disables native browser validation', (_name, page) => {
    render(<>{page}</>);

    expect(screen.getByRole('form')).toHaveAttribute('novalidate');
  });
});

describe('auth contracts (backend field names + endpoints)', () => {
  it('login submits email/password/remember to POST /login', () => {
    render(<Login canResetPassword />);
    typeInto('Email', 'aisha@example.com');
    typeInto('Password', 'correct-horse');
    submit();

    expect(calls.setData).toHaveBeenCalledWith('email', 'aisha@example.com');
    expect(calls.setData).toHaveBeenCalledWith('password', 'correct-horse');
    expect(calls.formPost).toHaveBeenCalledWith('/login');
  });

  it('2FA challenge submits code to POST /two-factor-challenge', () => {
    render(<TwoFactorChallenge />);
    typeInto('6-digit authentication code', '123456');
    submit();

    expect(calls.setData).toHaveBeenCalledWith('code', '123456');
    expect(calls.formPost).toHaveBeenCalledWith('/two-factor-challenge');
  });

  it('2FA challenge offers recovery-code verification', () => {
    render(<TwoFactorChallenge />);
    fireEvent.click(screen.getByRole('tab', { name: 'Recovery code' }));
    typeInto('One-time recovery code', 'backup-code');
    submit();

    expect(calls.setData).toHaveBeenCalledWith('code', '');
    expect(calls.setData).toHaveBeenCalledWith('recovery_code', 'backup-code');
    expect(calls.formPost).toHaveBeenCalledWith('/two-factor-challenge');
  });

  it('forgot-password submits email to POST /forgot-password', () => {
    render(<ForgotPassword />);
    typeInto('Email', 'aisha@example.com');
    submit();

    expect(calls.setData).toHaveBeenCalledWith('email', 'aisha@example.com');
    expect(calls.formPost).toHaveBeenCalledWith('/forgot-password');
  });

  it('reset-password submits to POST /reset-password', () => {
    render(<ResetPassword email="aisha@example.com" token="tok" />);
    typeInto('New password', 'new-secret-1');
    submit();

    expect(calls.setData).toHaveBeenCalledWith('password', 'new-secret-1');
    expect(calls.formPost).toHaveBeenCalledWith('/reset-password');
  });

  it('confirm-password submits to POST /user/confirm-password', () => {
    render(<ConfirmPassword />);
    typeInto('Password', 'correct-horse');
    submit();

    expect(calls.formPost).toHaveBeenCalledWith('/user/confirm-password');
  });

  it('admin bootstrap consumes the token from the URL fragment and submits to POST /admin/first-access', () => {
    window.history.replaceState({}, '', '/admin/first-access#one-time-token');
    render(<AdminBootstrap />);
    submit();

    expect(calls.formPost).toHaveBeenCalledWith('/admin/first-access');
    expect(window.location.hash).toBe('');
  });

  it('offers guests a login link from the access-denied page', () => {
    render(<Forbidden />);

    expect(screen.getByRole('link', { name: 'Go to log in' })).toHaveAttribute('href', '/login');
  });

  it('verify-email resends via POST /email/verification-notification', () => {
    render(<VerifyEmail />);
    fireEvent.click(screen.getByRole('button', { name: 'Resend verification email' }));

    expect(calls.routerPost).toHaveBeenCalledWith(
      '/email/verification-notification',
      {},
      expect.objectContaining({ onFinish: expect.any(Function) }),
    );
  });
});

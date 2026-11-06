import { useState } from 'react';
import { Eye, EyeOff } from 'lucide-react';
import { markInternalViewer } from '../../helpers/authHelper';

/** Use the existing SYSTEM login/cookie inside the standalone SAS app. */
export default function SASSignIn({ onSignedIn }) {
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [showPassword, setShowPassword] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');

  const signIn = async event => {
    event.preventDefault();
    if (busy) return;
    setBusy(true);
    setError('');
    try {
      const response = await fetch('/api/login.php', {
        method: 'POST', credentials: 'include',
        body: new URLSearchParams({ email, password, session_mode: 'sas' }),
      });
      const data = await response.json().catch(() => null);
      if (!response.ok || !data?.success || data?.user?.is_admin !== true) {
        setError(data?.message || 'Could not sign in. Please try again.');
        return;
      }
      markInternalViewer();
      setPassword('');
      onSignedIn();
    } catch {
      setError('Unable to reach ColorFix. Please try again.');
    } finally {
      setBusy(false);
    }
  };

  return <form className="sas-sign-in" aria-label="Sign in to SAS" onSubmit={signIn} aria-busy={busy}>
    <h1>Sign in to SAS</h1>
    <p>Sign in with your ColorFix admin account to search photos. Your search will resume after sign-in.</p>
    {error && <p className="sas-photo-error" role="alert">{error}</p>}
    <label htmlFor="sas-login-email">Email</label>
    <input id="sas-login-email" type="email" autoComplete="username" required disabled={busy}
      value={email} onChange={event => setEmail(event.target.value)} />
    <label htmlFor="sas-login-password">Password</label>
    <div className="sas-sign-in-password">
      <input id="sas-login-password" type={showPassword ? 'text' : 'password'} autoComplete="current-password" required disabled={busy}
        value={password} onChange={event => setPassword(event.target.value)} />
      <button type="button" aria-label={showPassword ? 'Hide password' : 'Show password'} aria-pressed={showPassword}
        onClick={() => setShowPassword(value => !value)}>
        {showPassword ? <EyeOff size={22} aria-hidden="true"/> : <Eye size={22} aria-hidden="true"/>}
      </button>
    </div>
    <p>This device stays signed in until you sign out or change your ColorFix password.</p>
    <button className="sas-continue" type="submit" disabled={busy}>{busy ? 'Signing in…' : 'Sign in'}</button>
  </form>;
}

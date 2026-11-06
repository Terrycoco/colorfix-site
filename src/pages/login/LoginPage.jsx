import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { Eye, EyeOff } from 'lucide-react';
import { useAppState } from '@/context/AppStateContext'; // ✅ global context
import './login.css';
import {API_FOLDER} from '@helpers/config';
import { clearLegacyAdminState, setAdmin } from '@helpers/authHelper';

function LoginPage() {
  const { user: currentUser, authReady, authError, setUser, setLoggedIn } = useAppState();
  const navigate = useNavigate();

  useEffect(() => {
    if (authReady && currentUser?.is_admin) navigate('/admin/', { replace: true });
  }, [authReady, currentUser, navigate]);

  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [showPassword, setShowPassword] = useState(false);
  const [error, setError] = useState('');
  const [remember, setRemember] = useState(false);
  const [loading, setLoading] = useState(false); // ✅ prevent double submits

const handleSubmit = async (e) => {
  e.preventDefault();
  if (loading) return;
  setError('');
  setLoading(true);

  try {
    const res = await fetch(`${API_FOLDER}/login.php`, {
      method: 'POST',
      credentials: 'include',
      body: new URLSearchParams({ email, password, remember: String(remember) }), // don't set Content-Type manually
    });
    

    const data = await res.json().catch(() => null);

    if (!res.ok || !data?.success) {
      setError(data?.message || `Login failed (${res.status})`);
      setLoading(false);
      return;
    }

    clearLegacyAdminState();
    const user = data.user || {};
    setAdmin(Boolean(user.is_admin));
    setUser(user);
    setLoggedIn(true);

    navigate(user.is_admin ? '/admin/' : '/', { replace: true });
  } catch (err) {
    console.error('Login error:', err);
    setError('Something went wrong');
    setLoading(false);
  }
};

  if (!authReady || currentUser?.is_admin) {
    return <p className="login-form" role="status">{currentUser?.is_admin ? 'Opening admin…' : authError || 'Checking your saved login…'}</p>;
  }

  return (
    <form className="login-form" onSubmit={handleSubmit} aria-busy={loading}>
      <h2>Login</h2>
      {error && <p className="error" role="alert" aria-live="polite">{error}</p>}

      <label htmlFor="email">Email:</label>
      <input
        id="email"
        type="email"
        autoComplete="username"         // ✅ helps autofill
        inputMode="email"
        value={email}
        onChange={e => setEmail(e.target.value)}
        required
        disabled={loading}              // ✅ block edits while sending
      />

      <label htmlFor="password">Password:</label>
      <div className="login-password-field">
      <input
        id="password"
        type={showPassword ? 'text' : 'password'}
        autoComplete="current-password" // ✅ helps autofill
        value={password}
        onChange={e => setPassword(e.target.value)}
        required
        disabled={loading}
      />
      <button
        type="button"
        className="login-password-toggle"
        aria-label={showPassword ? 'Hide password' : 'Show password'}
        aria-pressed={showPassword}
        onClick={() => setShowPassword(value => !value)}
      >
        {showPassword ? <EyeOff size={20} aria-hidden="true" /> : <Eye size={20} aria-hidden="true" />}
      </button>
      </div>

      <label>
        <input type="checkbox" checked={remember} onChange={e => setRemember(e.target.checked)} disabled={loading} />
        Remember this personal device for 30 days
      </label>

      <button type="submit" disabled={loading}>
        {loading ? 'Logging in…' : 'Log In'}
      </button>
    </form>
  );
}

export default LoginPage;

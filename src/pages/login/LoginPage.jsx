import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useAppState } from '@/context/AppStateContext'; // ✅ global context
import './login.css';
import {API_FOLDER} from '@helpers/config';

function LoginPage() {
  const { setUser, setLoggedIn } = useAppState();
  const navigate = useNavigate();

  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(false); // ✅ prevent double submits

const handleSubmit = async (e) => {
  e.preventDefault();
  if (loading) return;
  setError('');
  setLoading(true);

  try {
    const res = await fetch(`${API_FOLDER}/login.php`, {
     method: 'POST',
  body: new URLSearchParams({ email, password }), // don't set Content-Type manually
});
    

    let data;
    try {
      data = await res.json();
    } catch {
      const text = await res.text();
      console.error('Non-JSON response:', res.status, text);
      setError(`Login failed (${res.status})`);
      setLoading(false);
      return;
    }

    if (!res.ok || !data?.success) {
      setError(data?.message || `Login failed (${res.status})`);
      setLoading(false);
      return;
    }

    const user = data.user || {};
    setUser(user);
    setLoggedIn(true);

    if (user.is_admin) {
      localStorage.setItem('isAdmin', 'true');
      localStorage.setItem('isTerry', 'true'); // if other code checks it
    } else {
      localStorage.removeItem('isAdmin');
      localStorage.removeItem('isTerry');
    }

    navigate('/');
  } catch (err) {
    console.error('Login error:', err);
    setError('Something went wrong');
    setLoading(false);
  }
};

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
      <input
        id="password"
        type="password"
        autoComplete="current-password" // ✅ helps autofill
        value={password}
        onChange={e => setPassword(e.target.value)}
        required
        disabled={loading}
      />

      <button type="submit" disabled={loading}>
        {loading ? 'Logging in…' : 'Log In'}
      </button>
    </form>
  );
}

export default LoginPage;

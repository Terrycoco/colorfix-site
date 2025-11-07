// RecalcCategoriesButton.jsx
import { useState, useRef, useEffect } from 'react';
import { API_FOLDER } from '@helpers/config';

export default function RecalcCatsButton({ batch = 2000, canonicalize = true, className = '' }) {
  const [running, setRunning] = useState(false);
  const [msg, setMsg] = useState('');
  const [elapsed, setElapsed] = useState(0);
  const t0Ref = useRef(0);
  const timerRef = useRef(null);

  useEffect(() => {
    if (running) {
      t0Ref.current = Date.now();
      timerRef.current = setInterval(() => {
        setElapsed(Math.floor((Date.now() - t0Ref.current) / 1000));
      }, 1000);
    } else {
      clearInterval(timerRef.current);
      timerRef.current = null;
    }
    return () => clearInterval(timerRef.current);
  }, [running]);

  function fmt(s) {
    const m = Math.floor(s / 60);
    const ss = String(s % 60).padStart(2, '0');
    return `${m}:${ss}`;
  }

  async function runRecalc() {
    if (running) return;
    setMsg('');
    setElapsed(0);
    setRunning(true);

    const url = `${API_FOLDER}/v2/recalc-categories.php?canonicalize=${canonicalize ? 1 : 0}&batch=${batch}&ts=${Date.now()}`;
    try {
      const res = await fetch(url);
      const json = await res.json().catch(() => ({}));
      if (json?.status === 'success') {
        const s = json.summary || {};
        setMsg(`Recalc complete: ${s.processed ?? 0}/${s.total_colors ?? 0} colors.`);
      } else {
        setMsg(`Recalc error: ${json?.message || 'Unknown error'}`);
      }
    } catch (e) {
      setMsg(`Recalc failed.`);
    } finally {
      setRunning(false);
    }
  }

  return (
    <div className={`recalc-wrap ${className}`}>
      <button
        type="button"
        onClick={runRecalc}
        disabled={running}
        aria-busy={running ? 'true' : 'false'}
        className="recalc-btn"
      >
        {running ? (
          <>
            <span className="spinner" aria-hidden="true" /> Recalculating… {fmt(elapsed)}
          </>
        ) : (
          'Recalc Categories'
        )}
      </button>

      <div className="recalc-msg" aria-live="polite">
        {running ? 'Working… please keep this tab open.' : msg}
      </div>
    </div>
  );
}

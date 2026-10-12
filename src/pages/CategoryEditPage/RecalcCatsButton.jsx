import { useState, useRef, useEffect } from 'react';
import { API_FOLDER } from '@helpers/config';

// canonicalize is retained for compatibility with existing callers. The new
// engine always uses hue_display through the validated view; no toggle exists.
export default function RecalcCatsButton({ batch = 500, canonicalize = true, className = '' }) {
  const [running, setRunning] = useState(false);
  const [msg, setMsg] = useState('');
  const [elapsed, setElapsed] = useState(0);
  const t0Ref = useRef(0);
  const timerRef = useRef(null);
  const inFlightRef = useRef(false);

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
    if (inFlightRef.current) return;
    if (!window.confirm('Recalculate ALL color categories and refresh the MV and clusters?')) return;
    inFlightRef.current = true;
    setMsg('');
    setElapsed(0);
    setRunning(true);
    try {
      const res = await fetch(`${API_FOLDER}/v2/admin/colors/recalculate.php`, {
        method: 'POST',
        credentials: 'include',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ confirm: 'rebuild_categories', batch }),
      });
      const json = await res.json().catch(() => null);
      if (res.ok && json?.status === 'success') {
        const s = json.summary || {};
        setMsg(`Recalc complete: ${s.processed ?? 0}/${s.total_colors ?? 0} colors; MV and clusters refreshed.`);
      } else {
        setMsg(`Recalc error: ${json?.message || `HTTP ${res.status}`}`);
      }
    } catch (e) {
      setMsg('Recalc failed: network error. Check server status before retrying.');
    } finally {
      inFlightRef.current = false;
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

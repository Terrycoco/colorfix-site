import { useEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { API_FOLDER } from '@helpers/config';
import './ErrStatusDialog.css';

const STATUS_URL = `${API_FOLDER}/v2/admin/errors/status.php`;
const SESSION_KEY = 'err-status-session';
const VALID_STATUSES = new Set(['attempting', 'accepted', 'waiting', 'success', 'failed']);

/**
 * One shell-mounted viewer for ERR's server-side status feed.
 * No client-side event bus or provider. ERR owns the events; this only displays them.
 * Requires an authenticated ERR status.php endpoint returning:
 * { ok: true, event: null | { id, operationId, title, status, message, detail } }
 */
export default function ErrStatusDialog() {
  const [current, setCurrent] = useState(null);
  const [minimized, setMinimized] = useState(false);
  const lastSeenId = useRef(null);
  const dismissedOperation = useRef(null);

  useEffect(() => {
    if (!window.sessionStorage.getItem(SESSION_KEY)) {
      window.sessionStorage.setItem(SESSION_KEY, Array.from(window.crypto.getRandomValues(new Uint8Array(16)), b => b.toString(16).padStart(2, '0')).join(''));
    }
    let stopped = false;
    let controller = null;

    async function refresh() {
      controller = new AbortController();
      try {
        const res = await fetch(STATUS_URL, {
          method: 'GET', credentials: 'include', cache: 'no-store',
          headers: { 'X-ERR-Status-Session': window.sessionStorage.getItem(SESSION_KEY) },
          signal: controller.signal,
        });
        if (!res.ok) return;
        const body = await res.json();
        if (stopped || body?.ok !== true) return;
        const event = body.event;
        if (!event || typeof event.message !== 'string' || !VALID_STATUSES.has(event.status)) return;
        const id = String(event.id ?? '');
        if (!id || id === lastSeenId.current) return;
        lastSeenId.current = id;
        const operationId = String(event.operationId ?? '');
        if (dismissedOperation.current === operationId) return;
        dismissedOperation.current = null;
        setCurrent({
          operationId,
          title: String(event.title || 'ERR Status'),
          status: event.status,
          message: event.message,
          detail: String(event.detail || ''),
        });
      } catch (error) {
        if (error?.name !== 'AbortError') {
          // A temporarily unavailable status feed must not interrupt admin work.
        }
      }
    }

    void refresh();
    const interval = window.setInterval(() => { void refresh(); }, 1500);
    return () => {
      stopped = true;
      window.clearInterval(interval);
      controller?.abort();
    };
  }, []);

  if (!current) return null;
  const label = current.status[0].toUpperCase() + current.status.slice(1);

  return createPortal(
    <section
      className={`err-status ${minimized ? 'err-status--minimized' : 'err-status--expanded'}`}
      role="status"
      aria-live="polite"
      aria-atomic="true"
      aria-label="ERR operation status"
    >
      <div className="err-status__heading">
        <div className="err-status__title">{minimized ? 'Status' : current.title}</div>
        <div className="err-status__controls">
          <button type="button" className="err-status__control"
            aria-label={minimized ? 'Expand status' : 'Minimize status'}
            title={minimized ? 'Expand' : 'Minimize'}
            onClick={() => setMinimized((value) => !value)}>
            {minimized ? '□' : '−'}
          </button>
          <button type="button" className="err-status__control"
            aria-label="Dismiss status" title="Dismiss"
            onClick={() => { dismissedOperation.current = current.operationId; setCurrent(null); }}>×</button>
        </div>
      </div>
      <div className="err-status__body">
        <span className={`err-status__label err-status__label--${current.status}`}>{label}</span>
        <span className="err-status__message">{current.message}</span>
        {!minimized && current.detail && <div className="err-status__detail">{current.detail}</div>}
      </div>
    </section>,
    document.body
  );
}

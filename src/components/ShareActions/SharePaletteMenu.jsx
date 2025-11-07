import React from "react";
import { useLocation, useParams } from "react-router-dom";
import "./shareactions.css";

const isiOS = () =>
  typeof navigator !== "undefined" && /iPad|iPhone|iPod/.test(navigator.userAgent);
const isAndroid = () =>
  typeof navigator !== "undefined" && /Android/.test(navigator.userAgent);
const isMobile = () => isiOS() || isAndroid();
const hasWebShare = () =>
  typeof navigator !== "undefined" && typeof navigator.share === "function";

function buildPermalink(paletteId, search) {
  const u = new URL(window.location.href);
  u.pathname = `/palette/${paletteId}/brands`;
  u.search = search || "";
  return u.toString();
}

export default function SharePaletteMenu({ className = "" }) {
  const { id } = useParams();
  const { search } = useLocation();
  const paletteId = Number(id || 0);
  const link = React.useMemo(() => buildPermalink(paletteId, search), [paletteId, search]);

  const [open, setOpen] = React.useState(false);
  const popRef = React.useRef(null);
  const btnRef = React.useRef(null);

  // close on esc / outside
  React.useEffect(() => {
    function onKey(e) { if (e.key === "Escape") setOpen(false); }
    function onClick(e) {
      if (!open) return;
      const el = popRef.current;
      if (el && !el.contains(e.target) && !btnRef.current?.contains(e.target)) setOpen(false);
    }
    document.addEventListener("keydown", onKey);
    document.addEventListener("mousedown", onClick);
    return () => {
      document.removeEventListener("keydown", onKey);
      document.removeEventListener("mousedown", onClick);
    };
  }, [open]);

  const text = `Palette ${paletteId} — view the swatches:\n${link}`;

  async function doSystemShare() {
    try { await navigator.share({ title: `Palette ${paletteId}`, text, url: link }); }
    catch (_) {}
    setOpen(false);
  }
  function doSMS() {
    const sep = isiOS() ? "&" : "?";
    window.location.assign(`sms:${sep}body=${encodeURIComponent(text)}`);
    setOpen(false);
  }
  async function doCopy() {
    try { await navigator.clipboard.writeText(link); }
    catch { window.prompt("Copy this link:", link); }
    setOpen(false);
  }

  const showSystem = hasWebShare();
  const showSMS = isMobile();

  return (
    <div
      className={`share-menu ${className}`}
      data-open={open ? "true" : "false"}
    >
      <button
        ref={btnRef}
        type="button"
        className="btn share-menu__trigger"
        aria-haspopup="menu"
        aria-expanded={open ? "true" : "false"}
        onClick={() => setOpen(v => !v)}
      >
        Share This Page
      </button>

      {open && (
        <div
          ref={popRef}
          role="menu"
          aria-label="Share options"
          className="share-menu__sheet"
        >
          {showSystem && (
            <button role="menuitem" type="button" className="share-menu__item" onClick={doSystemShare}>
              Share This Page
            </button>
          )}
          {showSMS && (
            <button role="menuitem" type="button" className="share-menu__item" onClick={doSMS}>
              Text link (SMS)
            </button>
          )}
          <button role="menuitem" type="button" className="share-menu__item" onClick={doCopy}>
            Copy link
          </button>

          <div className="share-menu__divider" />
          <button role="menuitem" type="button" className="share-menu__item share-menu__item--danger" onClick={() => setOpen(false)}>
            Cancel
          </button>
        </div>
      )}
    </div>
  );
}

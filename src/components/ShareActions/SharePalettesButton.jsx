import React from "react";
import { useLocation, useParams } from "react-router-dom";
import './shareactions.css';

function buildPermalink(paletteId, search) {
  const u = new URL(window.location.href);
  u.pathname = `/palette/${paletteId}/brands`;
  u.search = search || "";
  return u.toString();
}

function isiOS() {
  if (typeof navigator === "undefined") return false;
  return /iPad|iPhone|iPod/.test(navigator.userAgent);
}

export default function SharePalettesButton({ className = "" }) {
  const { id } = useParams();
  const { search } = useLocation();
  const paletteId = Number(id || 0);
  const url = React.useMemo(() => buildPermalink(paletteId, search), [paletteId, search]);

  async function handleShare() {
    const text = `Palette ${paletteId} — view the swatches:\n${url}`;

    // 1) Native share sheet (best UX on mobile)
    if (navigator.share) {
      try {
        await navigator.share({ title: `Palette ${paletteId}`, text, url });
        return;
      } catch (e) {
        // fall through to SMS / clipboard
      }
    }

    // 2) SMS composer (no number; user enters it in their Messages app)
    const sep = isiOS() ? "&" : "?";
    const smsUrl = `sms:${sep}body=${encodeURIComponent(text)}`;
    // Attempt to open SMS handler (works on phones/tablets)
    window.location.href = smsUrl;

    // 3) Desktop fallback: copy link
    try {
      await navigator.clipboard.writeText(url);
      alert("Link copied to clipboard!");
    } catch {
      // last resort
      window.prompt("Copy this link:", url);
    }
  }

  return (
    <div className={`${className}`}>
    <button type="button" className={`btn`} onClick={handleShare}>
      Share This Page
    </button>
    </div>
  );
}

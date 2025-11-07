import React, { useRef, useState } from "react";
import './shareactions.css';

export default function ShareActionsSMS({ paletteId }) {
  const [phone, setPhone] = useState("");
  const inputRef = useRef(null);

  const pageUrl = new URL(window.location.href);
  pageUrl.pathname = `/palette/${paletteId}/brands`;
  // keep current query (eg: ?only=Dunn-Edwards) if you want:
  // pageUrl.search = window.location.search;

  function cleanPhone(p) {
    return String(p).replace(/[^\d+]/g, "");
  }

  function openSmsComposer() {
    const to = cleanPhone(phone);
    if (!to) { inputRef.current?.focus(); return; }
    const body = encodeURIComponent(
      `Palette ${paletteId} — view the swatches:\n${pageUrl.toString()}`
    );
    // iOS/Android accept these schemes; the `?` vs `&` separator varies by platform
    const sep = /iPad|iPhone|iPod/.test(navigator.userAgent) ? "&" : "?";
    const smsUrl = `sms:${to}${sep}body=${body}`;
    window.location.href = smsUrl;
  }

  return (
    <div className="share-sms">
      <input
        ref={inputRef}
        type="tel"
        placeholder="Enter phone number"
        value={phone}
        onChange={(e) => setPhone(e.target.value)}
        className="share-sms-input"
      />
      <button className="btn" type="button" onClick={openSmsComposer}>
        Text Link
      </button>
    </div>
  );
}

import React, { useRef, useState } from "react";
import { openTextShare } from "@helpers/shareUrls";
import './shareactions.css';

export default function ShareActionsSMS({ paletteId }) {
  const [phone, setPhone] = useState("");
  const inputRef = useRef(null);

  const pageUrl = new URL(window.location.href);
  pageUrl.pathname = `/palette/${paletteId}/brands`;
  // keep current query (eg: ?only=Dunn-Edwards) if you want:
  // pageUrl.search = window.location.search;

  function openSmsComposer() {
    if (!phone.trim()) { inputRef.current?.focus(); return; }
    openTextShare({
      text: `Palette ${paletteId} — view the swatches:`,
      url: pageUrl.toString(),
      phone,
    }).catch(() => {});
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

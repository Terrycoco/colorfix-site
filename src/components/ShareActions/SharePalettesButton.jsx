import React from "react";
import { useLocation, useParams } from "react-router-dom";
import { copyShareText, shareOrText } from "@helpers/shareUrls";
import './shareactions.css';

function buildPermalink(paletteId, search) {
  const u = new URL(window.location.href);
  u.pathname = `/palette/${paletteId}/brands`;
  u.search = search || "";
  return u.toString();
}

export default function SharePalettesButton({ className = "" }) {
  const { id } = useParams();
  const { search } = useLocation();
  const paletteId = Number(id || 0);
  const url = React.useMemo(() => buildPermalink(paletteId, search), [paletteId, search]);

  async function handleShare() {
    try {
      await shareOrText({
        title: `Palette ${paletteId}`,
        text: `Palette ${paletteId} — view the swatches:`,
        url,
      });
    } catch {
      if (await copyShareText(url)) {
        alert("Link copied to clipboard!");
        return;
      }
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

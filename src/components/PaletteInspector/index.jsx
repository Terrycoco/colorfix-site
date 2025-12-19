// PaletteInspector.jsx
import React, { useEffect, useMemo, useState } from "react";
import { useNavigate } from "react-router-dom";
import SwatchGallery from "@components/SwatchGallery";
import PaletteSwatch from "@components/Swatches/PaletteSwatch";
import { API_FOLDER as API } from "@helpers/config";
import "./paletteInspector.css";
import { isAdmin } from "@helpers/authHelper";
import PaletteMetaEditor from "./PaletteMetaEditor";

const ROLE_OPTIONS = ["body", "trim", "accent"];

export default function PaletteInspector({ palette, onClose, onPatched, topOffset = 56 }) {
  const [colors, setColors] = useState([]);
  const [loading, setLoading] = useState(false);
  const [roles, setRoles] = useState({});
  const [rolesLoading, setRolesLoading] = useState(false);
  const [rolesError, setRolesError] = useState("");
  const [rolesSaving, setRolesSaving] = useState(false);
  const navigate = useNavigate();
  const paletteId = Number(palette?.palette_id ?? palette?.id ?? 0);

  useEffect(() => {
    let alive = true;
    async function fetchDetails() {
      if (!paletteId) return;
      setLoading(true);
      try {
        const resp = await fetch(`${API}/get-palette-details.php`, {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({ palette_id: paletteId }),
        });
        const data = await resp.json();
        if (!alive) return;
        setColors(Array.isArray(data?.items) ? data.items : []);
      } catch {
        if (alive) setColors([]);
      } finally {
        if (alive) setLoading(false);
      }
    }
    fetchDetails();
    return () => { alive = false; };
  }, [paletteId]);

  useEffect(() => {
    let ignore = false;
    async function fetchRoles() {
      if (!paletteId) return;
      setRolesLoading(true);
      setRolesError("");
      try {
        const resp = await fetch(`${API}/v2/admin/palette-roles.php?palette_id=${paletteId}`, {
          credentials: "include",
        });
        const raw = await resp.text();
        let data;
        try {
          data = JSON.parse(raw);
        } catch {
          throw new Error(`Roles load failed (${resp.status}): ${raw.slice(0, 120)}`);
        }
        if (!ignore) {
          if (data.ok) setRoles(data.roles || {});
          else setRolesError(data.error || "Failed to load roles");
        }
      } catch (err) {
        if (!ignore) setRolesError(err?.message || "Failed to load roles");
      } finally {
        if (!ignore) setRolesLoading(false);
      }
    }
    fetchRoles();
    return () => { ignore = true; };
  }, [paletteId]);

  if (!palette) return null;

  function roleSlugForColor(colorId) {
    return ROLE_OPTIONS.find((slug) => Number(roles?.[slug]?.color_id) === Number(colorId)) || "";
  }

  function buildPayload(nextRoles) {
    const payload = {};
    ROLE_OPTIONS.forEach((slug) => {
      const colorId = Number(nextRoles?.[slug]?.color_id || 0);
      if (colorId > 0) payload[slug] = colorId;
    });
    return payload;
  }

  async function persistRoles(next) {
    if (!paletteId) return;
    setRolesSaving(true);
    setRolesError("");
    try {
      const payload = buildPayload(next);
      const resp = await fetch(`${API}/v2/admin/palette-roles.php`, {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ palette_id: paletteId, roles: payload }),
      });
      const raw = await resp.text();
      let data;
      try {
        data = JSON.parse(raw);
      } catch {
        throw new Error(`Roles save failed (${resp.status}): ${raw.slice(0, 120)}`);
      }
      if (!data.ok) throw new Error(data.error || "Failed to save roles");
    } catch (err) {
      setRolesError(err?.message || "Failed to save roles");
    } finally {
      setRolesSaving(false);
    }
  }

  function handleRoleChange(color, slug) {
    setRoles((prev) => {
      const next = { ...prev };
      ROLE_OPTIONS.forEach((roleSlug) => {
        if (next[roleSlug]?.color_id === color.id) next[roleSlug] = undefined;
      });
      if (slug) {
        next[slug] = {
          color_id: color.id,
          name: color.name,
          brand: color.brand,
          code: color.code,
          hex6: color.hex6,
        };
      }
      persistRoles(next);
      return next;
    });
  }

  return (
    <div
      className="pi-overlay"
      style={{ "--pi-top-offset": `${topOffset}px` }}
      onClick={onClose}
    >
      <div className="pi-modal" onClick={(e) => e.stopPropagation()}>
        <header className="pi-header">
          <h2>{palette?.name || `Palette #${paletteId}`}</h2>
          <button className="pi-close" onClick={onClose} aria-label="Close">✕</button>
        </header>
<SwatchGallery
  className="pi-swatches"
  items={colors}
  SwatchComponent={PaletteSwatch}
  swatchPropName="color"
  columns={null}      // auto-fit on wider screens
  minWidth={140}
  gap={12}
  aspectRatio="5 / 4"
  emptyMessage={loading ? "Loading swatches…" : "No colors in this palette."}
  swatchProps={{ widthPercent: 100 }}   // ← fill grid cell
/>

{isAdmin() && (
  <div className="pi-role-editor">
    <div className="pi-role-title">
      Assign Roles {rolesSaving && <span className="pi-role-status">Saving…</span>}
    </div>
    {rolesError && <div className="pi-role-error">{rolesError}</div>}
    {rolesLoading && <div className="pi-role-status">Loading role data…</div>}
    <div className="pi-role-grid">
      {colors.map((color) => (
        <div className="pi-role-row" key={color.id}>
          <div className="pi-role-info">
            <span className="pi-role-chip" style={{ backgroundColor: `#${color.hex6 || 'ccc'}` }} />
            <div>
              <div className="pi-role-name">{color.name}</div>
              <div className="pi-role-meta">{color.brand} · {color.code}</div>
            </div>
          </div>
          <select
            value={roleSlugForColor(color.id)}
            onChange={(e) => handleRoleChange(color, e.target.value)}
          >
            <option value="">(no role)</option>
            {ROLE_OPTIONS.map((slug) => (
              <option key={slug} value={slug}>{slug.toUpperCase()}</option>
            ))}
          </select>
        </div>
      ))}
    </div>
  </div>
)}

<footer className="pi-footer" style={{ display: "flex", justifyContent: "flex-end", padding: "12px 16px 16px" }}>
  <button
    className="pi-btn"
    type="button"
    onClick={() => navigate(`/palette/${paletteId}/brands`)}
  >
    See In Each Brand
  </button>
</footer>
{isAdmin() && <PaletteMetaEditor palette={palette} onPatched={onPatched} />}

      </div>
    </div>
  );
}

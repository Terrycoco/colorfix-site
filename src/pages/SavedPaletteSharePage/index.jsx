import { useEffect, useMemo, useState } from "react";
import { useParams } from "react-router-dom";
import { API_FOLDER } from "@helpers/config";
import "./saved-palette-share.css";

export default function SavedPaletteSharePage() {
  const { hash } = useParams();
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [palette, setPalette] = useState(null);
  const [members, setMembers] = useState([]);

  useEffect(() => {
    if (!hash) return;
    let cancelled = false;
    async function load() {
      setLoading(true);
      setError("");
      try {
        const res = await fetch(`${API_FOLDER}/v2/saved-palettes/get.php?hash=${encodeURIComponent(hash)}`, {
          credentials: "include",
        });
        const data = await res.json();
        if (!res.ok || !data?.ok) {
          throw new Error(data?.error || "Failed to load palette");
        }
        if (cancelled) return;
        setPalette(data.palette || null);
        setMembers(Array.isArray(data.members) ? data.members : []);
      } catch (err) {
        if (cancelled) return;
        setError(err?.message || "Failed to load palette");
      } finally {
        if (!cancelled) setLoading(false);
      }
    }
    load();
    return () => {
      cancelled = true;
    };
  }, [hash]);

  const title = useMemo(() => {
    if (!palette) return "Saved Palette";
    return palette.nickname || palette.display_title || "Saved Palette";
  }, [palette]);

  if (loading) {
    return (
      <div className="saved-palette-share">
        <div className="sps-card">Loading palette…</div>
      </div>
    );
  }

  if (error) {
    return (
      <div className="saved-palette-share">
        <div className="sps-card sps-error">{error}</div>
      </div>
    );
  }

  return (
    <div className="saved-palette-share">
      <div className="sps-card">
        <header className="sps-header">
          <div>
            <h1>{title}</h1>
            {palette?.client_name && <div className="sps-subtitle">{palette.client_name}</div>}
          </div>
          {palette?.brand && <span className="sps-pill">{palette.brand.toUpperCase()}</span>}
        </header>
        {palette?.notes && <p className="sps-notes">{palette.notes}</p>}
        <div className="sps-swatches">
          {members.map((member) => (
            <div className="sps-swatch" key={member.id || member.color_id}>
              <div
                className="sps-chip"
                style={{ backgroundColor: `#${member.color_hex6 || "ccc"}` }}
              />
              <div className="sps-meta">
                <div className="sps-name">{member.color_name || "—"}</div>
                <div className="sps-code">{member.color_code || member.color_id}</div>
              </div>
            </div>
          ))}
        </div>
      </div>
    </div>
  );
}

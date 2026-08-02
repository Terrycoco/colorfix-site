import { useCallback, useEffect, useMemo, useState } from "react";
import { API_FOLDER } from "@helpers/config";
import "./admin-player-experiences.css";

const EXPERIENCE_LIST_URL = `${API_FOLDER}/v2/admin/player-experiences/list.php`;
const EXPERIENCE_SAVE_URL = `${API_FOLDER}/v2/admin/player-experiences/save.php`;
const CTA_PAGES_LIST_URL = `${API_FOLDER}/v2/admin/cta-groups/list.php`;

const slideFlagOptions = ["site", "yt", "pin", "prospect", "client"];
const paletteViewerOptions = ["full_palette", "concept", "none"];

const emptyExperience = {
  player_experience_id: null,
  name: "",
  experience_key: "",
  slide_flag: "site",
  palette_viewer_key: "full_palette",
  cta_page_id: "",
  is_active: true,
  sort_order: 0,
};

function normalizeExperience(row) {
  return {
    player_experience_id: row?.player_experience_id ? Number(row.player_experience_id) : null,
    name: row?.name || "",
    experience_key: row?.experience_key || "",
    slide_flag: row?.slide_flag || "site",
    palette_viewer_key: row?.palette_viewer_key || "full_palette",
    cta_page_id: row?.cta_page_id ? Number(row.cta_page_id) : "",
    cta_page_label: row?.cta_page_label || "",
    cta_page_key: row?.cta_page_key || "",
    is_active: Number(row?.is_active) === 1 || row?.is_active === true,
    sort_order: Number(row?.sort_order) || 0,
  };
}

function slugify(value) {
  return String(value || "")
    .trim()
    .toLowerCase()
    .replace(/&/g, " and ")
    .replace(/[^a-z0-9_-]+/g, "-")
    .replace(/^-+|-+$/g, "");
}

export default function AdminPlayerExperiencesPage() {
  const [experiences, setExperiences] = useState([]);
  const [ctaPages, setCtaPages] = useState([]);
  const [form, setForm] = useState(emptyExperience);
  const [modalOpen, setModalOpen] = useState(false);
  const [status, setStatus] = useState("");
  const [error, setError] = useState("");
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);

  const fetchExperiences = useCallback(async () => {
    setLoading(true);
    try {
      const res = await fetch(`${EXPERIENCE_LIST_URL}?_=${Date.now()}`, {
        credentials: "include",
      });
      const data = await res.json();
      if (!res.ok || !data?.ok) throw new Error(data?.error || "Failed to load player experiences");
      setExperiences((Array.isArray(data.items) ? data.items : []).map(normalizeExperience));
    } catch (err) {
      setError(err?.message || "Failed to load player experiences");
    } finally {
      setLoading(false);
    }
  }, []);

  const fetchCtaPages = useCallback(async () => {
    try {
      const res = await fetch(`${CTA_PAGES_LIST_URL}?_=${Date.now()}`, {
        credentials: "include",
      });
      const data = await res.json();
      if (!res.ok || !data?.ok) throw new Error(data?.error || "Failed to load CTA pages");
      setCtaPages(Array.isArray(data.items) ? data.items : []);
    } catch (err) {
      setError(err?.message || "Failed to load CTA pages");
    }
  }, []);

  useEffect(() => {
    fetchExperiences();
    fetchCtaPages();
  }, [fetchCtaPages, fetchExperiences]);

  const ctaPageById = useMemo(() => {
    const map = new Map();
    ctaPages.forEach((page) => map.set(Number(page.id), page));
    return map;
  }, [ctaPages]);

  function openNew() {
    setForm(emptyExperience);
    setStatus("");
    setError("");
    setModalOpen(true);
  }

  function openEdit(row) {
    setForm(normalizeExperience(row));
    setStatus("");
    setError("");
    setModalOpen(true);
  }

  function updateForm(field, value) {
    setForm((prev) => {
      const next = { ...prev, [field]: value };
      if (field === "name" && !prev.player_experience_id && (!prev.experience_key || prev.experience_key === slugify(prev.name))) {
        next.experience_key = slugify(value);
      }
      return next;
    });
    setStatus("");
    setError("");
  }

  async function saveExperience(event) {
    event.preventDefault();
    if (saving) return;
    setSaving(true);
    setStatus("");
    setError("");
    try {
      const payload = {
        ...form,
        player_experience_id: form.player_experience_id ? Number(form.player_experience_id) : null,
        name: form.name.trim(),
        experience_key: form.experience_key.trim(),
        cta_page_id: Number(form.cta_page_id) || 0,
        sort_order: Number(form.sort_order) || 0,
        is_active: Boolean(form.is_active),
      };
      if (!payload.name) throw new Error("Name is required.");
      if (!payload.experience_key) throw new Error("Experience key is required.");
      if (!payload.cta_page_id) throw new Error("CTA Page is required.");

      const res = await fetch(EXPERIENCE_SAVE_URL, {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(payload),
      });
      const data = await res.json();
      if (!res.ok || !data?.ok) throw new Error(data?.error || "Failed to save player experience");

      setStatus("Player experience saved.");
      setModalOpen(false);
      await fetchExperiences();
    } catch (err) {
      setError(err?.message || "Failed to save player experience");
    } finally {
      setSaving(false);
    }
  }

  return (
    <main className="admin-player-experiences">
      <header className="pe-header">
        <div>
          <h1>Player Experiences</h1>
          <p>Configure the backend experience key, slide flag, palette viewer mode, and CTA page.</p>
        </div>
        <button type="button" className="pe-btn pe-btn--primary" onClick={openNew}>
          New Experience
        </button>
      </header>

      {error && <div className="pe-status pe-status--error">{error}</div>}
      {status && <div className="pe-status pe-status--success">{status}</div>}

      <section className="pe-grid-panel">
        {loading ? (
          <div className="pe-empty">Loading player experiences...</div>
        ) : (
          <table className="pe-grid">
            <thead>
              <tr>
                <th>Name</th>
                <th>Experience key</th>
                <th>Slide flag</th>
                <th>Palette viewer</th>
                <th>CTA Page</th>
                <th>Active</th>
                <th>Sort order</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              {experiences.map((row) => {
                const page = ctaPageById.get(Number(row.cta_page_id));
                const ctaLabel = row.cta_page_label || page?.label || `#${row.cta_page_id}`;
                return (
                  <tr key={row.player_experience_id}>
                    <td>{row.name}</td>
                    <td><code>{row.experience_key}</code></td>
                    <td><code>{row.slide_flag}</code></td>
                    <td><code>{row.palette_viewer_key}</code></td>
                    <td>{ctaLabel}</td>
                    <td>{row.is_active ? "Yes" : "No"}</td>
                    <td>{row.sort_order}</td>
                    <td>
                      <button type="button" className="pe-btn" onClick={() => openEdit(row)}>
                        Edit
                      </button>
                    </td>
                  </tr>
                );
              })}
              {!experiences.length && (
                <tr>
                  <td colSpan={8} className="pe-empty">No player experiences found.</td>
                </tr>
              )}
            </tbody>
          </table>
        )}
      </section>

      {modalOpen && (
        <div className="pe-modal-backdrop" role="presentation">
          <section className="pe-modal" role="dialog" aria-modal="true" aria-labelledby="pe-modal-title">
            <header className="pe-modal-header">
              <h2 id="pe-modal-title">{form.player_experience_id ? "Edit Experience" : "New Experience"}</h2>
              <button type="button" className="pe-modal-close" onClick={() => setModalOpen(false)} aria-label="Close">
                x
              </button>
            </header>

            <form className="pe-form" onSubmit={saveExperience}>
              <label>
                Name
                <input
                  value={form.name}
                  onChange={(event) => updateForm("name", event.target.value)}
                  autoFocus
                />
              </label>

              <label>
                Experience key
                <input
                  value={form.experience_key}
                  onChange={(event) => updateForm("experience_key", slugify(event.target.value))}
                />
              </label>

              <label>
                Slide flag
                <select value={form.slide_flag} onChange={(event) => updateForm("slide_flag", event.target.value)}>
                  {slideFlagOptions.map((value) => (
                    <option key={value} value={value}>{value}</option>
                  ))}
                </select>
              </label>

              <label>
                Palette viewer
                <select value={form.palette_viewer_key} onChange={(event) => updateForm("palette_viewer_key", event.target.value)}>
                  {paletteViewerOptions.map((value) => (
                    <option key={value} value={value}>{value}</option>
                  ))}
                </select>
              </label>

              <label>
                CTA Page
                <select value={form.cta_page_id} onChange={(event) => updateForm("cta_page_id", event.target.value)}>
                  <option value="">Choose CTA Page...</option>
                  {ctaPages.map((page) => (
                    <option key={page.id} value={page.id}>
                      {page.label || page.key} #{page.id}
                    </option>
                  ))}
                </select>
              </label>

              <label>
                Sort order
                <input
                  type="number"
                  min="0"
                  value={form.sort_order}
                  onChange={(event) => updateForm("sort_order", event.target.value)}
                />
              </label>

              <label className="pe-check">
                <input
                  type="checkbox"
                  checked={form.is_active}
                  onChange={(event) => updateForm("is_active", event.target.checked)}
                />
                Active
              </label>

              <footer className="pe-modal-actions">
                <button type="button" className="pe-btn" onClick={() => setModalOpen(false)}>
                  Cancel
                </button>
                <button type="submit" className="pe-btn pe-btn--primary" disabled={saving}>
                  {saving ? "Saving..." : "Save"}
                </button>
              </footer>
            </form>
          </section>
        </div>
      )}
    </main>
  );
}

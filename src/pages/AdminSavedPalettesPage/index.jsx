import { useEffect, useMemo, useState } from "react";
import { useNavigate } from "react-router-dom";
import { API_FOLDER } from "@helpers/config";
import { useAppState } from "@context/AppStateContext";
import { isAdmin } from "@helpers/authHelper";
import SavedPaletteEditorModal from "@components/SavedPaletteEditorModal";
import "./admin-saved-palettes.css";

const BRAND_CHOICES = [
  { code: "", label: "All Brands" },
  { code: "de", label: "Dunn Edwards" },
  { code: "sw", label: "Sherwin-Williams" },
  { code: "behr", label: "Behr" },
  { code: "bm", label: "Benjamin Moore" },
  { code: "ppg", label: "PPG" },
  { code: "vs", label: "Valspar" },
  { code: "vist", label: "Vista Paint" },
  { code: "fb", label: "Farrow & Ball" },
];

const defaultForm = {
  q: "",
  brand: "",
  terryFav: "all",
  limit: 40,
};

const emptyEditForm = {
  palette_id: null,
  nickname: "",
  display_title: "",
  notes: "",
  private_notes: "",
  terry_fav: false,
  kicker_id: "",
  palette_type: "exterior",
};

const emptySendForm = {
  palette_id: null,
  nickname: "",
  to_email: "",
  subject: "",
  message: "",
  preview_colors: [],
};

function formatDate(value) {
  if (!value) return "—";
  const dt = new Date(value.replace(" ", "T"));
  if (Number.isNaN(dt.valueOf())) return value;
  return dt.toLocaleString(undefined, {
    month: "short",
    day: "numeric",
    hour: "numeric",
    minute: "2-digit",
  });
}

function memberToSwatch(member) {
  const hex = member?.color_hex6 ? `#${member.color_hex6}` : "";
  return {
    id: member?.color_id,
    name: member?.color_name ?? "",
    brand: member?.color_brand ?? "",
    code: member?.color_code ?? "",
    hex,
    hcl_h: member?.color_hcl_h ?? 0,
    hcl_c: member?.color_hcl_c ?? 0,
    hcl_l: member?.color_hcl_l ?? 0,
    chip_num: member?.color_chip_num ?? "",
    cluster_id: member?.color_cluster_id ?? 0,
  };
}

function buildFullUrl(relPath) {
  if (!relPath) return "";
  if (/^https?:\/\//i.test(relPath)) return relPath;
  if (typeof window === "undefined") return relPath;
  return `${window.location.origin}${relPath.startsWith("/") ? "" : "/"}${relPath}`;
}

function openViewerSetup(palette) {
  const paletteId = Number(palette?.id || 0);
  if (!paletteId) return;
  const params = new URLSearchParams();
  params.set("type", "saved");
  params.set("id", String(paletteId));
  const preferred = (palette?.photos || []).find((photo) => photo?.photo_type === "full") || palette?.photos?.[0];
  const setId = Number(preferred?.saved_palette_set_id || preferred?.set_id || 0);
  if (setId > 0) {
    params.set("set_id", String(setId));
  }
  window.location.href = `/admin/palette-photos?${params.toString()}`;
}

function collapseMembersByColor(members = []) {
  const groups = new Map();
  const order = [];

  members.forEach((member, index) => {
    const colorId = Number(member?.color_id || member?.color?.id || member?.color?.color_id || 0);
    const fallbackKey = String(member?.color_code || member?.color_name || member?.id || index);
    const key = colorId > 0 ? `id:${colorId}` : `fallback:${fallbackKey}`;

    if (!groups.has(key)) {
      groups.set(key, {
        key,
        color_id: colorId || null,
        color_name: member?.color_name || member?.color?.name || "",
        color_code: member?.color_code || member?.color?.code || "",
        color_hex6: member?.color_hex6 || (member?.color?.hex || "").replace(/^#/, ""),
        color_brand: member?.color_brand || member?.color?.brand || "",
        color_hcl_h: member?.color_hcl_h ?? member?.color?.hcl_h ?? 0,
        color_hcl_c: member?.color_hcl_c ?? member?.color?.hcl_c ?? 0,
        color_hcl_l: member?.color_hcl_l ?? member?.color?.hcl_l ?? 0,
        color_chip_num: member?.color_chip_num ?? member?.color?.chip_num ?? "",
        color_cluster_id: member?.color_cluster_id ?? member?.color?.cluster_id ?? 0,
        roles: [],
      });
      order.push(key);
    }

    const group = groups.get(key);
    const rawRoles = String(member?.role || "")
      .split(",")
      .map((role) => role.trim())
      .filter(Boolean);

    rawRoles.forEach((role) => {
      if (!group.roles.includes(role)) {
        group.roles.push(role);
      }
    });
  });

  return order.map((key, index) => {
    const group = groups.get(key);
    return {
      id: group.color_id || `${key}-${index}`,
      color_id: group.color_id,
      color_name: group.color_name,
      color_code: group.color_code,
      color_hex6: group.color_hex6,
      color_brand: group.color_brand,
      color_hcl_h: group.color_hcl_h,
      color_hcl_c: group.color_hcl_c,
      color_hcl_l: group.color_hcl_l,
      color_chip_num: group.color_chip_num,
      color_cluster_id: group.color_cluster_id,
      role: group.roles.join(", "),
    };
  });
}

export default function AdminSavedPalettesPage() {
  const admin = isAdmin();
  const navigate = useNavigate();
  const { clearPalette, addManyToPalette } = useAppState();

  const [form, setForm] = useState(defaultForm);
  const [filters, setFilters] = useState(() => ({
    limit: defaultForm.limit,
    with_members: 1,
  }));
  const [refreshTick, setRefreshTick] = useState(0);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState("");
  const [items, setItems] = useState([]);
  const [editModalOpen, setEditModalOpen] = useState(false);
  const [editForm, setEditForm] = useState(emptyEditForm);
  const [editStatus, setEditStatus] = useState({ loading: false, error: "" });
  const [sendModalOpen, setSendModalOpen] = useState(false);
  const [sendForm, setSendForm] = useState(emptySendForm);
  const [sendStatus, setSendStatus] = useState({ loading: false, error: "", success: "" });

  useEffect(() => {
    if (!admin) return;
    let cancelled = false;
    async function fetchPalettes() {
      setLoading(true);
      setError("");
      try {
        const qs = new URLSearchParams();
        Object.entries(filters).forEach(([key, value]) => {
          if (value === undefined || value === null || value === "") return;
          qs.set(key, String(value));
        });
        qs.set("with_members", "1");
        qs.set("with_photos", "1");
        qs.set("_", Date.now().toString());
        const res = await fetch(`${API_FOLDER}/v2/admin/saved-palettes.php?${qs.toString()}`, {
          credentials: "include",
        });
        const text = await res.text();
        if (!res.ok) {
          throw new Error(`HTTP ${res.status}: ${text.slice(0, 200)}`);
        }
        let json;
        try {
          json = JSON.parse(text);
        } catch {
          throw new Error("Invalid JSON response");
        }
        if (!json.ok) throw new Error(json.error || "Unknown error");
        if (cancelled) return;
        setItems(Array.isArray(json.items) ? json.items : []);
      } catch (err) {
        if (cancelled) return;
        setItems([]);
        setError(err?.message || "Failed to load palettes");
      } finally {
        if (!cancelled) setLoading(false);
      }
    }
    fetchPalettes();
    return () => {
      cancelled = true;
    };
  }, [admin, filters, refreshTick]);

  const handleField = (name, value) => {
    setForm((prev) => ({ ...prev, [name]: value }));
  };

  const handleSubmit = (event) => {
    event.preventDefault();
    const next = {
      limit: Math.max(1, Math.min(200, Number(form.limit) || defaultForm.limit)),
      with_members: 1,
    };
    if (form.q.trim() !== "") next.q = form.q.trim();
    if (form.brand.trim() !== "") next.brand = form.brand.trim();
    if (form.terryFav === "fav") next.terry_fav = 1;
    if (form.terryFav === "not") next.terry_fav = 0;
    setFilters(next);
    setRefreshTick((tick) => tick + 1);
  };

  const handleClear = () => {
    setForm(defaultForm);
    setFilters({ limit: defaultForm.limit, with_members: 1 });
    setRefreshTick((tick) => tick + 1);
  };

  const handleLoadPalette = async (palette) => {
    if (!palette?.members?.length) return;
    clearPalette();
    await addManyToPalette(palette.members.map(memberToSwatch));
    navigate("/my-palette");
  };

  const openEditModal = (palette) => {
    setEditForm({
      palette_id: Number(palette.id) || palette.id,
      nickname: "",
      display_title: "",
      notes: "",
      private_notes: "",
      terry_fav: false,
      kicker_id: "",
      palette_type: "exterior",
    });
    setEditStatus({ loading: false, error: "" });
    setEditModalOpen(true);
  };

  const closeEditModal = () => {
    if (editStatus.loading) return;
    setEditModalOpen(false);
    setEditForm(emptyEditForm);
    setEditStatus({ loading: false, error: "" });
  };

  const closeSendModal = () => {
    if (sendStatus.loading) return;
    setSendModalOpen(false);
    setSendForm(emptySendForm);
    setSendStatus({ loading: false, error: "", success: "" });
  };

  const handleSendField = (name, value) => {
    setSendForm((prev) => ({ ...prev, [name]: value }));
  };

  const handleSendSubmit = async (event) => {
    event.preventDefault();
    if (!sendForm.palette_id) return;
    setSendStatus({ loading: true, error: "", success: "" });
    try {
      const res = await fetch(`${API_FOLDER}/v2/admin/saved-palette-send.php`, {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          palette_id: sendForm.palette_id,
          to_email: sendForm.to_email,
          subject: sendForm.subject,
          message: sendForm.message,
        }),
      });
      const json = await res.json().catch(() => ({}));
      if (!res.ok || !json.ok) {
        throw new Error(json.error || `HTTP ${res.status}`);
      }
      setSendStatus({ loading: false, error: "", success: "Email sent!" });
      setTimeout(() => {
        closeSendModal();
        setRefreshTick((tick) => tick + 1);
      }, 1200);
    } catch (err) {
      setSendStatus({ loading: false, error: err?.message || "Failed to send email", success: "" });
    }
  };

  const summary = useMemo(() => {
    if (!items.length) return "No saved palettes yet.";
    const totalFavs = items.filter((p) => Number(p.terry_fav) === 1).length;
    const byBrand = items.reduce((acc, row) => {
      const b = row.brand?.toLowerCase() || "unknown";
      acc[b] = (acc[b] || 0) + 1;
      return acc;
    }, {});
    const topBrands = Object.entries(byBrand)
      .sort((a, b) => b[1] - a[1])
      .slice(0, 3)
      .map(([code, count]) => `${code.toUpperCase()}: ${count}`);
    return `${items.length} palette${items.length === 1 ? "" : "s"} • ${totalFavs} fav • ${topBrands.join(" • ")}`;
  }, [items]);

  if (!admin) {
    return (
      <section className="admin-saved-palettes">
        <div className="asp-card">
          <h1>Saved Palettes</h1>
          <p>You need admin access to view this page.</p>
        </div>
      </section>
    );
  }

  return (
    <section className="admin-saved-palettes">
      <header className="asp-headline">
        <div>
          <h1>Saved Palettes</h1>
          <p className="asp-summary">{summary}</p>
        </div>
        <div className="asp-actions">
          <button type="button" onClick={() => setRefreshTick((tick) => tick + 1)} disabled={loading}>
            Refresh
          </button>
        </div>
      </header>

      <form className="asp-filter" onSubmit={handleSubmit}>
        <label>
          Search
          <input
            type="text"
            value={form.q}
            placeholder="Nickname, notes, tags, roles…"
            onChange={(e) => handleField("q", e.target.value)}
          />
        </label>

        <label>
          Brand
          <select value={form.brand} onChange={(e) => handleField("brand", e.target.value)}>
            {BRAND_CHOICES.map((b) => (
              <option key={b.code || "all"} value={b.code}>
                {b.label}
              </option>
            ))}
          </select>
        </label>

        <label>
          Favorites
          <select value={form.terryFav} onChange={(e) => handleField("terryFav", e.target.value)}>
            <option value="all">All</option>
            <option value="fav">Only favs</option>
            <option value="not">Hide favs</option>
          </select>
        </label>

        <label>
          Limit
          <input
            type="number"
            min={1}
            max={200}
            value={form.limit}
            onChange={(e) => handleField("limit", e.target.value)}
          />
        </label>

        <div className="asp-filter-buttons">
          <button type="submit" disabled={loading}>
            Apply
          </button>
          <button type="button" onClick={handleClear} disabled={loading}>
            Clear
          </button>
        </div>
      </form>

      {error && <div className="asp-error">{error}</div>}
      {loading && <div className="asp-loading">Loading saved palettes…</div>}

      <div className="asp-grid">
        {items.map((item) => (
          <article key={item.id} className="asp-card">
            <header className="asp-card-header">
              <div>
                {item.kicker_text && <div className="asp-card-kicker">{item.kicker_text}</div>}
                <div className="asp-card-title">
                  <strong>{item.nickname || "(untitled palette)"}</strong>
                  {item.display_title && <span className="asp-pill neutral">Viewer: {item.display_title}</span>}
                  <span className="asp-pill neutral">#{item.id}</span>
                  {Number(item.terry_fav) === 1 && <span className="asp-pill">Fav</span>}
                  <span className="asp-pill neutral">{(item.brand || "").toUpperCase() || "?"}</span>
                </div>
              </div>
              <div className="asp-card-times">
                <span>Created {formatDate(item.created_at)}</span>
              </div>
            </header>

            {item.notes && <p className="asp-notes">{item.notes}</p>}

            {item.photos?.length > 0 && (
              <div className="asp-photo-strip">
                {item.photos.slice(0, 4).map((photo) => (
                  <div key={photo.id} className="asp-photo-thumb">
                    <img src={photo.rel_path} alt={item.display_title || "Saved palette photo"} loading="lazy" />
                  </div>
                ))}
              </div>
            )}
            {item.photos?.length > 0 && (
              <label className="asp-photo-url">
                Front photo URL
                <input
                  type="text"
                  readOnly
                  value={buildFullUrl(
                    (item.photos || []).find((photo) => photo.photo_type === "full")?.rel_path
                    || item.photos?.[0]?.rel_path
                    || ""
                  )}
                  onFocus={(e) => e.target.select()}
                />
              </label>
            )}

            <div className="asp-swatches">
              {collapseMembersByColor(item.members || []).map((member) => (
                <div key={member.id} className="asp-swatch" title={`${member.color_name} (${member.color_code})`}>
                  <div className="asp-swatch-chip" style={{ backgroundColor: `#${member.color_hex6 || "ccc"}` }} />
                  <div className="asp-swatch-meta">
                    <span className="asp-swatch-name">{member.color_name || "—"}</span>
                    <span className="asp-swatch-code">{member.color_code || member.color_id}</span>
                    {member.role && <span className="asp-swatch-role">{member.role}</span>}
                  </div>
                </div>
              ))}
            </div>

            <footer className="asp-card-footer">
              <button type="button" className="ghost" onClick={() => openEditModal(item)}>
                Edit
              </button>
              <button
                type="button"
                className="ghost"
                onClick={() => openViewerSetup(item)}
              >
                Viewer Setup
              </button>
              <button
                type="button"
                className="ghost"
                onClick={() => {
                  if (!item?.palette_hash) return;
                  window.location.href = `/palette/${item.palette_hash}/share`;
                }}
                disabled={!item.members?.length}
              >
                Viewer
              </button>
              <button
                type="button"
                onClick={() => handleLoadPalette(item)}
                disabled={!item.members?.length}
              >
                My Palette
              </button>
            </footer>
          </article>
        ))}
      </div>

      <SavedPaletteEditorModal
        open={editModalOpen}
        paletteId={editForm.palette_id || null}
        showPhotoSection={false}
        onClose={closeEditModal}
        onSaved={(result) => {
          if (result?.palette?.id) {
            setItems((prev) => {
              const next = prev.map((row) => (row.id === result.palette.id ? result.palette : row));
              if (next.some((row) => row.id === result.palette.id)) {
                return next;
              }
              return [result.palette, ...next];
            });
          }
          setRefreshTick((tick) => tick + 1);
          setEditModalOpen(false);
        }}
      />

      {sendModalOpen && (
        <div className="asp-modal-backdrop" role="dialog" aria-modal="true">
          <div className="asp-modal">
            <header className="asp-modal-head">
              <h2>Send Palette Email</h2>
              <button type="button" className="asp-close" onClick={closeSendModal} aria-label="Close dialog">
                ✕
              </button>
            </header>
            <form className="asp-modal-form" onSubmit={handleSendSubmit}>
              <label>
                Email Subject
                <input
                  type="text"
                  value={sendForm.subject}
                  onChange={(e) => handleSendField("subject", e.target.value)}
                  placeholder="Subject line"
                />
              </label>

              <label>
                Send to Email
                <input
                  type="email"
                  value={sendForm.to_email}
                  onChange={(e) => handleSendField("to_email", e.target.value)}
                  required
                />
              </label>

              <label>
                Message
                <textarea
                  rows={4}
                  value={sendForm.message}
                  onChange={(e) => handleSendField("message", e.target.value)}
                />
              </label>

              {sendForm.preview_colors.length > 0 && (
                <>
                  <h3>Preview</h3>
                  <div className="asp-preview-swatches">
                    {sendForm.preview_colors.map((swatch) => (
                      <div key={swatch.id} className="asp-preview-swatch">
                        <div className="asp-preview-chip" style={{ backgroundColor: `#${swatch.hex}` }} />
                        <div className="asp-preview-meta">
                          <div>{swatch.name}</div>
                          <span>{swatch.code}</span>
                        </div>
                      </div>
                    ))}
                  </div>
                </>
              )}

              {sendStatus.error && <div className="asp-error">{sendStatus.error}</div>}
              {sendStatus.success && <div className="asp-success">{sendStatus.success}</div>}

              <div className="asp-modal-actions">
                <button type="button" className="ghost" onClick={closeSendModal} disabled={sendStatus.loading}>
                  Cancel
                </button>
                <button type="submit" disabled={sendStatus.loading}>
                  {sendStatus.loading ? "Sending…" : "Send Email"}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}
    </section>
  );
}

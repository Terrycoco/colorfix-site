import { useEffect, useMemo, useState } from "react";
import { useNavigate } from "react-router-dom";
import { API_FOLDER } from "@helpers/config";
import { useAppState } from "@context/AppStateContext";
import { isAdmin } from "@helpers/authHelper";
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
  notes: "",
  terry_fav: false,
  sent_to_email: "",
  client_id: null,
  client_name: "",
  client_email: "",
  client_phone: "",
  client_notes: "",
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

export default function AdminSavedPalettesPage() {
  const admin = isAdmin();
  const navigate = useNavigate();
  const { clearPalette, addManyToPalette, setShowPalette } = useAppState();

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
    setShowPalette?.(true);
    navigate("/my-palette");
  };

  const openEditModal = (palette) => {
    const parsedClientId =
      palette.client_id !== undefined && palette.client_id !== null
        ? Number(palette.client_id)
        : null;
    setEditForm({
      palette_id: Number(palette.id) || palette.id,
      nickname: palette.nickname || "",
      notes: palette.notes || "",
      terry_fav: Number(palette.terry_fav) === 1,
      sent_to_email: palette.sent_to_email || "",
      client_id: Number.isFinite(parsedClientId) ? parsedClientId : null,
      client_name: palette.client_name || "",
      client_email: palette.client_email || "",
      client_phone: palette.client_phone || "",
      client_notes: palette.client_notes || "",
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

  const handleEditField = (name, value) => {
    setEditForm((prev) => ({ ...prev, [name]: value }));
  };

  const handleEditSubmit = async (event) => {
    event.preventDefault();
    if (!editForm.palette_id) return;
    setEditStatus({ loading: true, error: "" });
    try {
      const payload = {
        ...editForm,
        terry_fav: editForm.terry_fav ? 1 : 0,
      };
      const res = await fetch(`${API_FOLDER}/v2/admin/saved-palette-update.php`, {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(payload),
      });
      const json = await res.json().catch(() => ({}));
      if (!res.ok || !json.ok) {
        throw new Error(json.error || `HTTP ${res.status}`);
      }
      setEditStatus({ loading: false, error: "" });
      setEditModalOpen(false);
      setEditForm(emptyEditForm);
      setRefreshTick((tick) => tick + 1);
    } catch (err) {
      setEditStatus({ loading: false, error: err?.message || "Failed to update palette" });
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
            placeholder="Nickname, notes, client, email…"
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
                <div className="asp-card-title">
                  <strong>{item.nickname || "(untitled palette)"}</strong>
                  {Number(item.terry_fav) === 1 && <span className="asp-pill">Fav</span>}
                  <span className="asp-pill neutral">{(item.brand || "").toUpperCase() || "?"}</span>
                </div>
                <div className="asp-meta-line">
                  {item.client_name && <span>{item.client_name}</span>}
                  {item.client_email && <span>{item.client_email}</span>}
                  {item.client_phone && <span>{item.client_phone}</span>}
                </div>
              </div>
              <div className="asp-card-times">
                <span>Created {formatDate(item.created_at)}</span>
                {item.sent_at && <span>Sent {formatDate(item.sent_at)}</span>}
              </div>
            </header>

            {item.notes && <p className="asp-notes">{item.notes}</p>}

            {item.sent_to_email && (
              <div className="asp-meta-line">
                Sent to <strong>{item.sent_to_email}</strong>
              </div>
            )}

            <div className="asp-swatches">
              {(item.members || []).map((member) => (
                <div key={member.id} className="asp-swatch" title={`${member.color_name} (${member.color_code})`}>
                  <div className="asp-swatch-chip" style={{ backgroundColor: `#${member.color_hex6 || "ccc"}` }} />
                  <div className="asp-swatch-meta">
                    <span className="asp-swatch-name">{member.color_name || "—"}</span>
                    <span className="asp-swatch-code">{member.color_code || member.color_id}</span>
                  </div>
                </div>
              ))}
            </div>

            <footer className="asp-card-footer">
              <button type="button" className="ghost" onClick={() => openEditModal(item)}>
                Edit
              </button>
              <button type="button" onClick={() => handleLoadPalette(item)} disabled={!item.members?.length}>
                Load into My Palette
              </button>
            </footer>
          </article>
        ))}
      </div>

      {editModalOpen && (
        <div className="asp-modal-backdrop" role="dialog" aria-modal="true">
          <div className="asp-modal">
            <header className="asp-modal-head">
              <h2>Edit Saved Palette</h2>
              <button type="button" className="asp-close" onClick={closeEditModal} aria-label="Close dialog">
                ✕
              </button>
            </header>

            <form className="asp-modal-form" onSubmit={handleEditSubmit}>
              <label>
                Nickname
                <input
                  type="text"
                  value={editForm.nickname}
                  onChange={(e) => handleEditField("nickname", e.target.value)}
                />
              </label>

              <label>
                Notes
                <textarea
                  rows={3}
                  value={editForm.notes}
                  onChange={(e) => handleEditField("notes", e.target.value)}
                />
              </label>

              <label className="asp-modal-checkbox">
                <input
                  type="checkbox"
                  checked={!!editForm.terry_fav}
                  onChange={(e) => handleEditField("terry_fav", e.target.checked)}
                />
                Mark as Terry favorite
              </label>

              <label>
                Send to Email
                <input
                  type="email"
                  value={editForm.sent_to_email}
                  onChange={(e) => handleEditField("sent_to_email", e.target.value)}
                  placeholder="client@example.com"
                />
              </label>

              <h3>Client</h3>
              <label>
                Client Name
                <input
                  type="text"
                  value={editForm.client_name}
                  onChange={(e) => handleEditField("client_name", e.target.value)}
                />
              </label>

              <label>
                Client Email
                <input
                  type="email"
                  value={editForm.client_email}
                  onChange={(e) => handleEditField("client_email", e.target.value)}
                />
              </label>

              <label>
                Client Phone
                <input
                  type="text"
                  value={editForm.client_phone}
                  onChange={(e) => handleEditField("client_phone", e.target.value)}
                />
              </label>

              <label>
                Client Notes
                <textarea
                  rows={2}
                  value={editForm.client_notes}
                  onChange={(e) => handleEditField("client_notes", e.target.value)}
                />
              </label>

              {editStatus.error && <div className="asp-error">{editStatus.error}</div>}

              <div className="asp-modal-actions">
                <button type="button" className="ghost" onClick={closeEditModal} disabled={editStatus.loading}>
                  Cancel
                </button>
                <button type="submit" disabled={editStatus.loading}>
                  {editStatus.loading ? "Saving…" : "Save Changes"}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}
    </section>
  );
}

import { useEffect, useMemo, useState } from "react";
import { useNavigate } from "react-router-dom";
import { API_FOLDER } from "@helpers/config";
import { useAppState } from "@context/AppStateContext";
import { isAdmin } from "@helpers/authHelper";
import EditableSwatch from "@components/EditableSwatch";
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
};

const emptySendForm = {
  palette_id: null,
  nickname: "",
  to_email: "",
  subject: "",
  message: "",
  preview_colors: [],
};

const DELETE_URL = `${API_FOLDER}/v2/admin/saved-palette-delete.php`;
const PHOTO_UPLOAD_URL = `${API_FOLDER}/v2/admin/saved-palette-photos/upload.php`;
const PHOTO_DELETE_URL = `${API_FOLDER}/v2/admin/saved-palette-photos/delete.php`;

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
  const [editMembers, setEditMembers] = useState([]);
  const [editPhotos, setEditPhotos] = useState([]);
  const [photoStatus, setPhotoStatus] = useState({ loading: false, error: "" });
  const [sendModalOpen, setSendModalOpen] = useState(false);
  const [sendForm, setSendForm] = useState(emptySendForm);
  const [sendStatus, setSendStatus] = useState({ loading: false, error: "", success: "" });
  const [deletingId, setDeletingId] = useState(null);

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
      nickname: palette.nickname || "",
      notes: palette.notes || "",
      terry_fav: Number(palette.terry_fav) === 1,
    });
    const members = (palette.members || []).map((member, index) => ({
      key: member.id ?? `${member.color_id}-${index}`,
      color: memberToSwatch(member),
      role: member.role || "",
    }));
    const photos = (palette.photos || []).map((photo, index) => ({
      id: photo.id,
      rel_path: photo.rel_path,
      photo_type: photo.photo_type || "full",
      trigger_color_id: photo.trigger_color_id ?? null,
      caption: photo.caption || "",
      order_index: photo.order_index ?? index,
    }));
    setEditMembers(members);
    setEditPhotos(photos);
    setEditStatus({ loading: false, error: "" });
    setPhotoStatus({ loading: false, error: "" });
    setEditModalOpen(true);
  };

  const closeEditModal = () => {
    if (editStatus.loading) return;
    setEditModalOpen(false);
    setEditForm(emptyEditForm);
    setEditMembers([]);
    setEditPhotos([]);
    setEditStatus({ loading: false, error: "" });
    setPhotoStatus({ loading: false, error: "" });
  };

  const handleEditField = (name, value) => {
    setEditForm((prev) => ({ ...prev, [name]: value }));
  };

  const handleEditMemberColor = (index, color) => {
    setEditMembers((prev) =>
      prev.map((row, idx) => (idx === index ? { ...row, color } : row))
    );
  };

  const handleEditMemberRole = (index, value) => {
    setEditMembers((prev) =>
      prev.map((row, idx) => (idx === index ? { ...row, role: value } : row))
    );
  };

  const handleAddMember = () => {
    setEditMembers((prev) => [
      ...prev,
      {
        key: `new-${Date.now()}`,
        color: null,
        role: "",
      },
    ]);
  };

  const handleRemoveMember = (index) => {
    setEditMembers((prev) => prev.filter((_, idx) => idx !== index));
  };

  const handlePhotoUpload = async (files) => {
    if (!editForm.palette_id || !files?.length) return;
    setPhotoStatus({ loading: true, error: "" });
    try {
      const formData = new FormData();
      formData.append("palette_id", String(editForm.palette_id));
      Array.from(files).forEach((file) => formData.append("photos[]", file));
      const res = await fetch(PHOTO_UPLOAD_URL, {
        method: "POST",
        credentials: "include",
        body: formData,
      });
      const json = await res.json().catch(() => ({}));
      if (!res.ok || !json.ok) {
        throw new Error(json.error || `HTTP ${res.status}`);
      }
      const added = Array.isArray(json.photos) ? json.photos : [];
      setEditPhotos((prev) => [
        ...prev,
        ...added.map((photo, index) => ({
          id: photo.id,
          rel_path: photo.rel_path,
          caption: photo.caption || "",
          order_index: photo.order_index ?? prev.length + index,
        })),
      ]);
      setPhotoStatus({ loading: false, error: "" });
    } catch (err) {
      setPhotoStatus({ loading: false, error: err?.message || "Failed to upload photos" });
    }
  };

  const handleDeletePhoto = async (photoId) => {
    if (!photoId) return;
    setPhotoStatus({ loading: true, error: "" });
    try {
      const res = await fetch(PHOTO_DELETE_URL, {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ photo_id: photoId }),
      });
      const json = await res.json().catch(() => ({}));
      if (!res.ok || !json.ok) {
        throw new Error(json.error || `HTTP ${res.status}`);
      }
      setEditPhotos((prev) => prev.filter((photo) => photo.id !== photoId));
      setPhotoStatus({ loading: false, error: "" });
    } catch (err) {
      setPhotoStatus({ loading: false, error: err?.message || "Failed to delete photo" });
    }
  };

  const handlePhotoField = (photoId, field, value) => {
    setEditPhotos((prev) =>
      prev.map((photo) =>
        photo.id === photoId ? { ...photo, [field]: value } : photo
      )
    );
  };

  const handleEditSubmit = async (event) => {
    event.preventDefault();
    if (!editForm.palette_id) return;
    setEditStatus({ loading: true, error: "" });
    try {
    const members = editMembers
        .map((row, index) => {
          const colorId = Number(row?.color?.id || row?.color?.color_id || 0);
          if (!colorId) return null;
          const role = row?.role?.trim() || null;
          return { color_id: colorId, order_index: index, role };
        })
        .filter(Boolean);
      if (!members.length) {
        throw new Error("Add at least one color before saving.");
      }
      const payload = {
        ...editForm,
        terry_fav: editForm.terry_fav ? 1 : 0,
        members,
        photos: editPhotos.map((photo) => ({
          id: photo.id,
          photo_type: photo.photo_type || "full",
          trigger_color_id: photo.trigger_color_id || null,
        })),
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

  const openSendModal = (palette) => {
    const colors = (palette.members || []).slice(0, 5).map((member) => ({
      id: member.id,
      name: member.color_name || "",
      code: member.color_code || "",
      hex: member.color_hex6 || "cccccc",
    }));
    const defaultEmail = "";
    const defaultMessage = palette.message_template || `Here’s the palette we discussed for ${palette.nickname || "your project"}.\n\nLet me know what you think!`;
    const defaultSubject = palette.subject_template || `ColorFix palette ideas`;
    setSendForm({
      palette_id: palette.id,
      nickname: palette.nickname || "Saved Palette",
      to_email: defaultEmail,
      subject: defaultSubject,
      message: defaultMessage,
      preview_colors: colors,
    });
    setSendStatus({ loading: false, error: "", success: "" });
    setSendModalOpen(true);
  };

  const handleDeletePalette = async (palette) => {
    if (!palette?.id) return;
    const label = palette.nickname || palette.brand || `Palette #${palette.id}`;
    if (!window.confirm(`Delete "${label}"? This cannot be undone.`)) return;
    setDeletingId(palette.id);
    setError("");
    try {
      const res = await fetch(DELETE_URL, {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ palette_id: palette.id }),
      });
      const json = await res.json().catch(() => ({}));
      if (!res.ok || !json.ok) {
        throw new Error(json.error || `HTTP ${res.status}`);
      }
      setItems((prev) => prev.filter((row) => row.id !== palette.id));
    } catch (err) {
      setError(err?.message || "Failed to delete saved palette");
    } finally {
      setDeletingId(null);
    }
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
                <div className="asp-card-title">
                  <strong>{item.nickname || "(untitled palette)"}</strong>
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
                    <img src={photo.rel_path} alt={item.nickname || "Saved palette photo"} loading="lazy" />
                  </div>
                ))}
              </div>
            )}

            <div className="asp-swatches">
              {(item.members || []).map((member) => (
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
              <button
                type="button"
                className="danger"
                onClick={() => handleDeletePalette(item)}
                disabled={deletingId === item.id}
              >
                {deletingId === item.id ? "Deleting…" : "Delete"}
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
              <div className="asp-photo-editor">
                <div className="asp-member-list-head">
                  <h3>Photos</h3>
                  <label className="asp-upload-btn">
                    Upload
                    <input
                      type="file"
                      accept="image/*"
                      multiple
                      onChange={(e) => handlePhotoUpload(e.target.files)}
                      disabled={photoStatus.loading}
                    />
                  </label>
                </div>
                {photoStatus.error && <div className="asp-error">{photoStatus.error}</div>}
                {editPhotos.length > 0 ? (
                  <div className="asp-photo-grid">
                    {editPhotos.map((photo) => (
                      <div key={photo.id} className="asp-photo-card">
                        <img src={photo.rel_path} alt="Palette upload" />
                        <select
                          value={photo.photo_type || "full"}
                          onChange={(e) => handlePhotoField(photo.id, "photo_type", e.target.value)}
                        >
                          <option value="full">Full</option>
                          <option value="zoom">Zoom</option>
                        </select>
                        <select
                          value={photo.trigger_color_id || ""}
                          onChange={(e) =>
                            handlePhotoField(
                              photo.id,
                              "trigger_color_id",
                              e.target.value ? Number(e.target.value) : null
                            )
                          }
                        >
                          <option value="">Trigger: any color</option>
                          {editMembers.map((row) => (
                            <option
                              key={`trigger-${photo.id}-${row.color?.id || row.color?.color_id}`}
                              value={row.color?.id || row.color?.color_id || ""}
                            >
                              {row.color?.name || row.color?.label || row.color?.code || row.color?.id}
                            </option>
                          ))}
                        </select>
                        <button
                          type="button"
                          className="ghost"
                          onClick={() => handleDeletePhoto(photo.id)}
                          disabled={photoStatus.loading}
                        >
                          Remove
                        </button>
                      </div>
                    ))}
                  </div>
                ) : (
                  <div className="asp-member-empty">No photos yet.</div>
                )}
              </div>

              <div className="asp-member-list">
                <div className="asp-member-list-head">
                  <h3>Palette Colors</h3>
                  <button type="button" className="ghost" onClick={handleAddMember}>
                    Add Color
                  </button>
                </div>
                <div className="asp-member-rows">
                  {editMembers.map((row, index) => (
                    <div key={row.key || index} className="asp-member-row">
                      <EditableSwatch
                        value={row.color}
                        onChange={(color) => handleEditMemberColor(index, color)}
                        showName
                        size="sm"
                        placement="top"
                      />
                      <input
                        type="text"
                        placeholder="Role (e.g. trim, body, door)"
                        value={row.role}
                        onChange={(e) => handleEditMemberRole(index, e.target.value)}
                      />
                      <button
                        type="button"
                        className="ghost"
                        onClick={() => handleRemoveMember(index)}
                        aria-label="Remove color"
                      >
                        ✕
                      </button>
                    </div>
                  ))}
                  {!editMembers.length && <div className="asp-member-empty">No colors yet.</div>}
                </div>
              </div>

              <label>
                Nickname
                <input
                  type="text"
                  value={editForm.nickname}
                  onChange={(e) => handleEditField("nickname", e.target.value)}
                />
              </label>

              <label>
                Internal Notes (for me)
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

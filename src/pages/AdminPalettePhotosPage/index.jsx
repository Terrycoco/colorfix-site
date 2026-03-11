import { useEffect, useMemo, useState } from "react";
import { API_FOLDER } from "@helpers/config";
import EditableSwatch from "@components/EditableSwatch";
import KickerDropdown from "@components/KickerDropdown";
import FuzzySearchColorSelect from "@components/FuzzySearchColorSelect";
import PhotoPickerModal from "@components/PhotoPickerModal";
import "../AdminSavedPalettesPage/admin-saved-palettes.css";
import "./admin-palette-photos.css";

const SAVED_LIST_URL = `${API_FOLDER}/v2/admin/saved-palettes.php`;
const APPLIED_LIST_URL = `${API_FOLDER}/v2/admin/applied-palettes/list.php`;
const APPLIED_GET_URL = `${API_FOLDER}/v2/admin/applied-palettes/get.php`;
const SAVED_UPDATE_URL = `${API_FOLDER}/v2/admin/saved-palette-update.php`;
const APPLIED_UPDATE_URL = `${API_FOLDER}/v2/admin/applied-palettes/update.php`;

const SAVED_PHOTO_UPLOAD_URL = `${API_FOLDER}/v2/admin/saved-palette-photos/upload.php`;
const SAVED_PHOTO_DELETE_URL = `${API_FOLDER}/v2/admin/saved-palette-photos/delete.php`;
const SAVED_PHOTO_LIBRARY_ADD_URL = `${API_FOLDER}/v2/admin/saved-palette-photos/add-from-library.php`;

const APPLIED_PHOTO_UPLOAD_URL = `${API_FOLDER}/v2/admin/applied-palette-photos/upload.php`;
const APPLIED_PHOTO_DELETE_URL = `${API_FOLDER}/v2/admin/applied-palette-photos/delete.php`;
const APPLIED_PHOTO_LIBRARY_ADD_URL = `${API_FOLDER}/v2/admin/applied-palette-photos/add-from-library.php`;
const APPLIED_PHOTO_UPDATE_URL = `${API_FOLDER}/v2/admin/applied-palette-photos/update.php`;

const emptySavedForm = {
  palette_id: null,
  nickname: "",
  notes: "",
  private_notes: "",
  terry_fav: false,
  kicker_id: "",
  palette_type: "exterior",
};

const emptyAppliedForm = {
  palette_id: null,
  title: "",
  display_title: "",
  notes: "",
  tags: "",
  kicker_id: "",
  alt_text: "",
};

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

function entryToSwatch(entry) {
  const hex = entry?.color_hex6 ? `#${entry.color_hex6}` : "";
  return {
    id: entry?.color_id,
    name: entry?.color_name ?? "",
    brand: entry?.color_brand ?? "",
    code: entry?.color_code ?? "",
    hex,
    hcl_h: entry?.color_hcl_h ?? 0,
    hcl_c: entry?.color_hcl_c ?? 0,
    hcl_l: entry?.color_hcl_l ?? 0,
    chip_num: entry?.color_chip_num ?? "",
    cluster_id: entry?.color_cluster_id ?? 0,
  };
}

export default function AdminPalettePhotosPage() {
  const [paletteType, setPaletteType] = useState("saved");
  const [savedPalettes, setSavedPalettes] = useState([]);
  const [appliedPalettes, setAppliedPalettes] = useState([]);
  const [selectedId, setSelectedId] = useState("");
  const [loadingPalette, setLoadingPalette] = useState(false);
  const [loadError, setLoadError] = useState("");

  const [editForm, setEditForm] = useState(emptySavedForm);
  const [editMembers, setEditMembers] = useState([]);
  const [editPhotos, setEditPhotos] = useState([]);
  const [photoPickerOpen, setPhotoPickerOpen] = useState(false);
  const [photoStatus, setPhotoStatus] = useState({ loading: false, error: "" });
  const [editStatus, setEditStatus] = useState({ loading: false, error: "", success: "" });

  const isApplied = paletteType === "applied";

  useEffect(() => {
    let active = true;
    async function loadSaved() {
      try {
        const params = new URLSearchParams();
        params.set("limit", "200");
        params.set("with_members", "1");
        params.set("with_photos", "1");
        params.set("_", Date.now().toString());
        const res = await fetch(`${SAVED_LIST_URL}?${params.toString()}`, { credentials: "include" });
        const data = await res.json();
        if (!res.ok || !data?.ok) throw new Error(data?.error || "Failed to load saved palettes");
        if (!active) return;
        setSavedPalettes(Array.isArray(data.items) ? data.items : []);
      } catch {
        if (!active) return;
        setSavedPalettes([]);
      }
    }
    loadSaved();
    return () => { active = false; };
  }, []);

  useEffect(() => {
    let active = true;
    async function loadApplied() {
      try {
        const params = new URLSearchParams();
        params.set("limit", "200");
        params.set("_", Date.now().toString());
        const res = await fetch(`${APPLIED_LIST_URL}?${params.toString()}`, { credentials: "include" });
        const data = await res.json();
        if (!res.ok || !data?.ok) throw new Error(data?.error || "Failed to load applied palettes");
        if (!active) return;
        setAppliedPalettes(Array.isArray(data.items) ? data.items : []);
      } catch {
        if (!active) return;
        setAppliedPalettes([]);
      }
    }
    loadApplied();
    return () => { active = false; };
  }, []);

  useEffect(() => {
    setSelectedId("");
    setEditMembers([]);
    setEditPhotos([]);
    setPhotoStatus({ loading: false, error: "" });
    setEditStatus({ loading: false, error: "", success: "" });
    setLoadError("");
    setEditForm(isApplied ? emptyAppliedForm : emptySavedForm);
  }, [paletteType]);

  useEffect(() => {
    if (!selectedId) {
      setEditMembers([]);
      setEditPhotos([]);
      setEditForm(isApplied ? emptyAppliedForm : emptySavedForm);
      return;
    }

    if (!isApplied) {
      const palette = savedPalettes.find((row) => String(row.id) === String(selectedId));
      if (!palette) return;
      setEditForm({
        palette_id: Number(palette.id) || palette.id,
        nickname: palette.nickname || "",
        notes: palette.notes || "",
        private_notes: palette.private_notes || "",
        terry_fav: Number(palette.terry_fav) === 1,
        kicker_id: palette.kicker_id || "",
        palette_type: palette.palette_type || "exterior",
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
        trigger_mode: photo.trigger_mode || "any",
        trigger_color_id: photo.trigger_color_id ?? null,
        caption: photo.caption || "",
        alt_text: photo.alt_text || "",
        order_index: photo.order_index ?? index,
      }));
      setEditMembers(members);
      setEditPhotos(photos);
      setLoadError("");
      return;
    }

    let active = true;
    async function loadAppliedPalette() {
      setLoadingPalette(true);
      setLoadError("");
      try {
        const res = await fetch(`${APPLIED_GET_URL}?id=${encodeURIComponent(selectedId)}`, { credentials: "include" });
        const data = await res.json();
        if (!res.ok || !data?.ok) throw new Error(data?.error || "Failed to load applied palette");
        if (!active) return;
        const palette = data.palette || {};
        setEditForm({
          palette_id: Number(palette.id) || palette.id,
          title: palette.title || "",
          display_title: palette.display_title || "",
          notes: palette.notes || "",
          tags: palette.tags || "",
          kicker_id: palette.kicker_id || "",
          alt_text: palette.alt_text || "",
        });
        const members = (data.entries || []).map((entry, index) => ({
          key: `${entry.mask_role || "mask"}-${entry.color_id || index}`,
          color: entryToSwatch(entry),
          role: entry.mask_role || "",
        }));
        const photos = (data.photos || []).map((photo, index) => ({
          id: photo.id,
          rel_path: photo.rel_path,
          photo_type: photo.photo_type || "full",
          trigger_mode: photo.trigger_mode || "any",
          trigger_color_id: photo.trigger_color_id ?? null,
          caption: photo.caption || "",
          alt_text: photo.alt_text || "",
          order_index: photo.order_index ?? index,
        }));
        setEditMembers(members);
        setEditPhotos(photos);
      } catch (err) {
        if (!active) return;
        setLoadError(err?.message || "Failed to load applied palette");
        setEditMembers([]);
        setEditPhotos([]);
      } finally {
        if (active) setLoadingPalette(false);
      }
    }

    loadAppliedPalette();
    return () => { active = false; };
  }, [selectedId, savedPalettes, isApplied]);

  const paletteOptions = useMemo(() => {
    if (isApplied) {
      return appliedPalettes.map((palette) => ({
        id: palette.id,
        label: palette.display_title || palette.title || `Applied #${palette.id}`,
      }));
    }
    return savedPalettes.map((palette) => ({
      id: palette.id,
      label: palette.nickname || palette.palette_hash || `Saved #${palette.id}`,
    }));
  }, [appliedPalettes, savedPalettes, isApplied]);

  const handleEditField = (name, value) => {
    setEditForm((prev) => ({ ...prev, [name]: value }));
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

  const handleAddMemberWithColor = (color) => {
    if (!color?.id) return;
    setEditMembers((prev) => [
      ...prev,
      {
        key: `new-${Date.now()}`,
        color,
        role: "",
      },
    ]);
  };

  const handleEditMemberColor = (index, color) => {
    setEditMembers((prev) =>
      prev.map((row, idx) => (idx === index ? { ...row, color } : row))
    );
  };

  const handleEditMemberRole = (index, role) => {
    setEditMembers((prev) =>
      prev.map((row, idx) => (idx === index ? { ...row, role } : row))
    );
  };

  const handleRemoveMember = (index) => {
    setEditMembers((prev) => prev.filter((_, idx) => idx !== index));
  };

  const applyPhotoFieldUpdate = (photo, field, value) => {
    const next = { ...photo, [field]: value };
    if (field === "trigger_mode" && value !== "color") {
      next.trigger_color_id = null;
    }
    if (next.photo_type === "before") {
      next.trigger_mode = "none";
      next.trigger_color_id = null;
      next.caption = "Before";
    }
    return next;
  };

  const handlePhotoField = (photoId, field, value) => {
    setEditPhotos((prev) =>
      prev.map((photo) =>
        photo.id === photoId
          ? applyPhotoFieldUpdate(photo, field, value)
          : photo
      )
    );
  };

  const handlePhotoUpload = async (files) => {
    if (!editForm.palette_id || !files?.length) return;
    setPhotoStatus({ loading: true, error: "" });
    const uploadUrl = isApplied ? APPLIED_PHOTO_UPLOAD_URL : SAVED_PHOTO_UPLOAD_URL;
    try {
      const formData = new FormData();
      formData.append("palette_id", String(editForm.palette_id));
      Array.from(files).forEach((file) => formData.append("photos[]", file));
      const res = await fetch(uploadUrl, {
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
          photo_type: photo.photo_type || "full",
          trigger_mode: photo.trigger_mode || "any",
          trigger_color_id: photo.trigger_color_id ?? null,
          caption: photo.caption || "",
          alt_text: photo.alt_text || "",
          order_index: photo.order_index ?? prev.length + index,
        })),
      ]);
      setPhotoStatus({ loading: false, error: "" });
    } catch (err) {
      setPhotoStatus({ loading: false, error: err?.message || "Failed to upload photos" });
    }
  };

  const handlePhotoPickFromLibrary = async (item) => {
    if (!editForm.palette_id || !item?.image_url) return;
    setPhotoStatus({ loading: true, error: "" });
    const addUrl = isApplied ? APPLIED_PHOTO_LIBRARY_ADD_URL : SAVED_PHOTO_LIBRARY_ADD_URL;
    try {
      const payload = {
        palette_id: editForm.palette_id,
        rel_path: item.image_url,
        photo_type: "full",
        trigger_mode: "any",
      };
      const res = await fetch(addUrl, {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(payload),
      });
      const json = await res.json().catch(() => ({}));
      if (!res.ok || !json.ok) {
        throw new Error(json.error || `HTTP ${res.status}`);
      }
      const photo = json.photo || {};
      setEditPhotos((prev) => [
        ...prev,
        {
          id: photo.id,
          rel_path: photo.rel_path,
          photo_type: photo.photo_type || "full",
          trigger_mode: photo.trigger_mode || "any",
          trigger_color_id: photo.trigger_color_id ?? null,
          caption: photo.caption || "",
          alt_text: photo.alt_text || "",
          order_index: photo.order_index ?? prev.length,
        },
      ]);
      setPhotoStatus({ loading: false, error: "" });
      setPhotoPickerOpen(false);
    } catch (err) {
      setPhotoStatus({ loading: false, error: err?.message || "Failed to attach photo" });
    }
  };

  const handleDeletePhoto = async (photoId) => {
    if (!photoId) return;
    setPhotoStatus({ loading: true, error: "" });
    const deleteUrl = isApplied ? APPLIED_PHOTO_DELETE_URL : SAVED_PHOTO_DELETE_URL;
    try {
      const res = await fetch(deleteUrl, {
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

  const handleSave = async (event) => {
    event.preventDefault();
    if (!editForm.palette_id) return;
    setEditStatus({ loading: true, error: "", success: "" });
    try {
      if (!isApplied) {
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
          kicker_id: editForm.kicker_id ? Number(editForm.kicker_id) : null,
          palette_type: editForm.palette_type || "exterior",
          members,
          photos: editPhotos.map((photo) => ({
            id: photo.id,
            photo_type: photo.photo_type || "full",
            trigger_mode: photo.trigger_mode || "any",
            trigger_color_id: photo.trigger_color_id || null,
            alt_text: photo.alt_text || "",
            caption: photo.caption || "",
          })),
        };
        const res = await fetch(SAVED_UPDATE_URL, {
          method: "POST",
          credentials: "include",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify(payload),
        });
        const json = await res.json().catch(() => ({}));
        if (!res.ok || !json.ok) {
          throw new Error(json.error || `HTTP ${res.status}`);
        }
      } else {
        const metaPayload = {
          palette_id: editForm.palette_id,
          title: editForm.title,
          display_title: editForm.display_title,
          notes: editForm.notes,
          tags: editForm.tags,
          kicker_id: editForm.kicker_id || null,
          alt_text: editForm.alt_text,
        };
        const resMeta = await fetch(APPLIED_UPDATE_URL, {
          method: "POST",
          credentials: "include",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify(metaPayload),
        });
        const jsonMeta = await resMeta.json().catch(() => ({}));
        if (!resMeta.ok || !jsonMeta.ok) {
          throw new Error(jsonMeta.error || `HTTP ${resMeta.status}`);
        }

        const photoPayload = {
          palette_id: editForm.palette_id,
          photos: editPhotos.map((photo) => ({
            id: photo.id,
            photo_type: photo.photo_type || "full",
            trigger_mode: photo.trigger_mode || "any",
            trigger_color_id: photo.trigger_color_id || null,
            alt_text: photo.alt_text || "",
            caption: photo.caption || "",
          })),
        };
        const resPhotos = await fetch(APPLIED_PHOTO_UPDATE_URL, {
          method: "POST",
          credentials: "include",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify(photoPayload),
        });
        const jsonPhotos = await resPhotos.json().catch(() => ({}));
        if (!resPhotos.ok || !jsonPhotos.ok) {
          throw new Error(jsonPhotos.error || `HTTP ${resPhotos.status}`);
        }
      }

      setEditStatus({ loading: false, error: "", success: "Saved." });
      setTimeout(() => setEditStatus((prev) => ({ ...prev, success: "" })), 2000);
    } catch (err) {
      setEditStatus({ loading: false, error: err?.message || "Failed to update palette", success: "" });
    }
  };

  const membersReadOnly = isApplied;

  return (
    <section className="app-palette-photos">
      <header className="app-palette-photos__head">
        <h1>Palette Photos</h1>
        <div className="app-palette-photos__selectors">
          <label>
            Palette Type
            <select value={paletteType} onChange={(e) => setPaletteType(e.target.value)}>
              <option value="saved">Saved Palette</option>
              <option value="applied">Applied Palette</option>
            </select>
          </label>
          <label>
            Palette
            <select value={selectedId} onChange={(e) => setSelectedId(e.target.value)}>
              <option value="">Select a palette</option>
              {paletteOptions.map((palette) => (
                <option key={palette.id} value={palette.id}>
                  {palette.label}
                </option>
              ))}
            </select>
          </label>
        </div>
      </header>

      {loadError && <div className="asp-error">{loadError}</div>}
      {loadingPalette && <div className="asp-member-empty">Loading palette…</div>}

      {selectedId && !loadingPalette && (
        <div className="app-palette-photos__panel asp-modal">
          <header className="asp-modal-head">
            <h2>{isApplied ? "Edit Applied Palette" : "Edit Saved Palette"}</h2>
          </header>

          <form className="asp-modal-form" onSubmit={handleSave}>
            <div className="asp-photo-editor">
              <div className="asp-member-list-head">
                <h3>Photos</h3>
                <div className="asp-photo-actions">
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
                  <button
                    type="button"
                    className="ghost"
                    onClick={() => setPhotoPickerOpen(true)}
                    disabled={photoStatus.loading}
                  >
                    Library
                  </button>
                  <button
                    type="submit"
                    className="asp-save-top"
                    disabled={editStatus.loading}
                  >
                    {editStatus.loading ? "Saving…" : "Save"}
                  </button>
                </div>
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
                        <option value="before">Before</option>
                      </select>
                      <select
                        value={photo.trigger_mode || "any"}
                        onChange={(e) => handlePhotoField(photo.id, "trigger_mode", e.target.value)}
                      >
                        <option value="any">Trigger: any color</option>
                        <option value="none">Trigger: none</option>
                        <option value="color">Trigger: specific color</option>
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
                        disabled={(photo.photo_type || "full") === "before" || (photo.trigger_mode || "any") !== "color"}
                      >
                        <option value="">Pick color</option>
                        {editMembers.map((row) => (
                          <option
                            key={`trigger-${photo.id}-${row.color?.id || row.color?.color_id}`}
                            value={row.color?.id || row.color?.color_id || ""}
                          >
                            {row.color?.name || row.color?.label || row.color?.code || row.color?.id}
                          </option>
                        ))}
                      </select>
                      <input
                        type="text"
                        placeholder="Alt text (SEO)"
                        value={photo.alt_text || ""}
                        onChange={(e) => handlePhotoField(photo.id, "alt_text", e.target.value)}
                      />
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
                {!membersReadOnly && (
                  <div className="asp-member-actions">
                    <FuzzySearchColorSelect
                      className="asp-member-fuzzy"
                      onSelect={handleAddMemberWithColor}
                      showLabel={false}
                      autoFocus={false}
                      preventAutoFocus
                      compact
                    />
                    <button type="button" className="ghost" onClick={handleAddMember}>
                      Add Color
                    </button>
                  </div>
                )}
              </div>
              {!membersReadOnly && (
                <label className="asp-kicker-field">
                  <span>Kicker (optional)</span>
                  <KickerDropdown
                    value={editForm.kicker_id}
                    onChange={(next) => setEditForm((prev) => ({ ...prev, kicker_id: next || "" }))}
                  />
                </label>
              )}
              {membersReadOnly && (
                <div className="app-palette-photos__hint">
                  Applied palette colors are read-only here. Use Mask Tester to change colors.
                </div>
              )}
              {!membersReadOnly && (
                <label>
                  Palette Type
                  <select
                    value={editForm.palette_type}
                    onChange={(e) => handleEditField("palette_type", e.target.value)}
                  >
                    <option value="exterior">Exterior</option>
                    <option value="interior">Interior</option>
                    <option value="hoa">HOA</option>
                  </select>
                </label>
              )}
              <div className="asp-member-rows">
                {editMembers.map((row, index) => (
                  <div key={row.key || index} className="asp-member-row">
                    <EditableSwatch
                      value={row.color}
                      onChange={(color) => handleEditMemberColor(index, color)}
                      showName
                      size="sm"
                      placement="top"
                      readOnly={membersReadOnly}
                    />
                    <input
                      type="text"
                      placeholder={membersReadOnly ? "Mask role" : "Role (e.g. trim, body, door)"}
                      value={row.role}
                      onChange={(e) => handleEditMemberRole(index, e.target.value)}
                      readOnly={membersReadOnly}
                    />
                    {!membersReadOnly && (
                      <button
                        type="button"
                        className="ghost"
                        onClick={() => handleRemoveMember(index)}
                        aria-label="Remove color"
                      >
                        ✕
                      </button>
                    )}
                  </div>
                ))}
                {!editMembers.length && <div className="asp-member-empty">No colors yet.</div>}
              </div>
            </div>

            {!isApplied && (
              <label>
                Nickname
                <input
                  type="text"
                  value={editForm.nickname}
                  onChange={(e) => handleEditField("nickname", e.target.value)}
                />
              </label>
            )}

            {isApplied && (
              <>
                <label>
                  Title
                  <input
                    type="text"
                    value={editForm.title}
                    onChange={(e) => handleEditField("title", e.target.value)}
                  />
                </label>
                <label>
                  Display Title
                  <input
                    type="text"
                    value={editForm.display_title}
                    onChange={(e) => handleEditField("display_title", e.target.value)}
                  />
                </label>
                <label>
                  Tags (comma separated)
                  <input
                    type="text"
                    value={editForm.tags}
                    onChange={(e) => handleEditField("tags", e.target.value)}
                  />
                </label>
                <label>
                  Alt text (SEO)
                  <input
                    type="text"
                    value={editForm.alt_text}
                    onChange={(e) => handleEditField("alt_text", e.target.value)}
                  />
                </label>
                <label className="asp-kicker-field">
                  <span>Kicker (optional)</span>
                  <KickerDropdown
                    value={editForm.kicker_id}
                    onChange={(next) => setEditForm((prev) => ({ ...prev, kicker_id: next || "" }))}
                  />
                </label>
              </>
            )}

            <label>
              Public Notes (shown in viewer)
              <textarea
                rows={3}
                value={editForm.notes}
                onChange={(e) => handleEditField("notes", e.target.value)}
              />
            </label>

            {!isApplied && (
              <label>
                Private Notes (for me)
                <textarea
                  rows={3}
                  value={editForm.private_notes}
                  onChange={(e) => handleEditField("private_notes", e.target.value)}
                />
              </label>
            )}

            {!isApplied && (
              <label className="asp-modal-checkbox">
                <input
                  type="checkbox"
                  checked={!!editForm.terry_fav}
                  onChange={(e) => handleEditField("terry_fav", e.target.checked)}
                />
                Mark as Terry favorite
              </label>
            )}

            {editStatus.error && <div className="asp-error">{editStatus.error}</div>}
            {editStatus.success && <div className="asp-success">{editStatus.success}</div>}

            <div className="asp-modal-actions">
              <button type="submit" disabled={editStatus.loading}>
                {editStatus.loading ? "Saving…" : "Save Changes"}
              </button>
            </div>
          </form>
        </div>
      )}

      <PhotoPickerModal
        open={photoPickerOpen}
        title="Pick Photo"
        onClose={() => setPhotoPickerOpen(false)}
        onPick={handlePhotoPickFromLibrary}
      />
    </section>
  );
}

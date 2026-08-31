import { useCallback, useEffect, useMemo, useState } from "react";
import {
  AdminDetailPane,
  AdminEmptyState,
  AdminListPane,
  AdminMasterDetail,
  AdminObjectList,
  AdminObjectListItem,
} from "@components/AdminLayout";
import PhotoPickerModal from "@components/PhotoPickerModal";
import RexManagementDialog from "@components/REX/RexManagementDialog";
import KickerDropdown from "@components/KickerDropdown";
import { buildImageUrl } from "@helpers/assetImage";
import { API_FOLDER } from "@helpers/config";
import "./AdminPaletteViewersPage.css";

const API_URL = `${API_FOLDER}/v2/admin/palette-viewers.php`;

const EMPTY_VIEWER = {
  palette_viewer_id: null,
  saved_palette_id: "",
  format: "public",
  template_key: "full_palette",
  kicker_text: "",
  title: "",
  intro: "",
  notes: "",
  cta_label: "",
  is_active: 1,
};

const PHOTO_TYPES = ["full", "before", "inset", "zoom"];
const TRIGGER_MODES = ["any", "color"];

async function readJson(response, fallbackMessage) {
  const text = await response.text();
  let data = null;
  try {
    data = text.trim() ? JSON.parse(text) : {};
  } catch {
    throw new Error(`${fallbackMessage}: invalid JSON response`);
  }

  if (!response.ok || !data?.ok) {
    throw new Error(data?.error || `HTTP ${response.status}`);
  }

  return data;
}

function viewerTitle(row) {
  const viewer = row?.viewer || row || {};
  const palette = row?.palette || {};
  return (
    String(viewer.title || "").trim() ||
    String(palette.display_title || "").trim() ||
    String(palette.nickname || "").trim() ||
    `Palette Viewer #${viewer.palette_viewer_id || ""}`
  );
}

function paletteLabel(palette) {
  return (
    String(palette?.label || "").trim() ||
    String(palette?.display_title || "").trim() ||
    String(palette?.nickname || "").trim() ||
    (palette?.id ? `Saved Palette #${palette.id}` : "")
  );
}

function normalizeDetail(payload) {
  return {
    viewer: {
      ...EMPTY_VIEWER,
      ...(payload?.viewer || {}),
      saved_palette_id: payload?.viewer?.saved_palette_id
        ? String(payload.viewer.saved_palette_id)
        : "",
      is_active: Number(payload?.viewer?.is_active ?? 1) ? 1 : 0,
    },
    palette: payload?.palette || null,
    members: Array.isArray(payload?.members) ? payload.members : [],
    photos: Array.isArray(payload?.photos) ? payload.photos : [],
    rex: payload?.rex || null,
  };
}

function renumberPhotos(photos) {
  return photos.map((photo, index) => ({
    ...photo,
    order_index: index,
  }));
}

function photoLabel(photo) {
  return (
    String(photo.caption || "").trim() ||
    String(photo.alt_text || "").trim() ||
    String(photo.rel_path || "").split("/").pop() ||
    `Photo #${photo.photo_library_id || ""}`
  );
}

export default function AdminPaletteViewersPage() {
  const [items, setItems] = useState([]);
  const [palettes, setPalettes] = useState([]);
  const [selectedId, setSelectedId] = useState(null);
  const [detail, setDetail] = useState(null);
  const [isNew, setIsNew] = useState(false);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState("");
  const [status, setStatus] = useState("");
  const [photoPickerOpen, setPhotoPickerOpen] = useState(false);
  const [rexDialog, setRexDialog] = useState({
    open: false,
    reservationIds: [],
    title: "",
  });

  const paletteMap = useMemo(() => {
    const map = new Map();
    palettes.forEach((palette) => {
      if (palette?.id) map.set(String(palette.id), palette);
    });
    return map;
  }, [palettes]);

  const selectedViewerId = detail?.viewer?.palette_viewer_id
    ? Number(detail.viewer.palette_viewer_id)
    : null;
  const canSave = Boolean(detail?.viewer?.saved_palette_id) && !saving;
  const previewUrl = !isNew && selectedViewerId > 0 ? detail?.rex?.public_url || "" : "";
  const previewDisabled = isNew || !selectedViewerId;

  const loadPalettes = useCallback(async () => {
    const data = await readJson(
      await fetch(`${API_URL}?mode=palettes&limit=2000&_=${Date.now()}`, {
        credentials: "include",
      }),
      "Failed to load Saved Palettes"
    );
    setPalettes(Array.isArray(data.items) ? data.items : []);
    return Array.isArray(data.items) ? data.items : [];
  }, []);

  const loadList = useCallback(async () => {
    const data = await readJson(
      await fetch(`${API_URL}?_=${Date.now()}`, { credentials: "include" }),
      "Failed to load Palette Viewers"
    );
    const rows = (Array.isArray(data.items) ? data.items : []).sort((a, b) =>
      viewerTitle(a).localeCompare(viewerTitle(b), undefined, { sensitivity: "base", numeric: true })
    );
    setItems(rows);
    return rows;
  }, []);

  const loadDetail = useCallback(async (id) => {
    if (!id) return;
    setError("");
    setStatus("");
    const data = await readJson(
      await fetch(`${API_URL}?id=${encodeURIComponent(id)}&_=${Date.now()}`, {
        credentials: "include",
      }),
      "Failed to load Palette Viewer"
    );
    setDetail(normalizeDetail(data.item));
    setSelectedId(Number(id));
    setIsNew(false);
  }, []);

const loadPage = useCallback(async () => {
  setLoading(true);
  setError("");

  try {
    await loadPalettes();

    const rows = await loadList();

    const params = new URLSearchParams(window.location.search);
    const requestedPvId = Number(params.get("pv_id") || 0);

    const requestedExists =
      requestedPvId > 0 &&
      rows.some(
        (row) =>
          Number(row?.viewer?.palette_viewer_id || 0) === requestedPvId
      );

    if (requestedExists && Number(selectedId) !== requestedPvId) {
      await loadDetail(requestedPvId);
    } else if (!selectedId && rows[0]?.viewer?.palette_viewer_id) {
      await loadDetail(rows[0].viewer.palette_viewer_id);
    }
  } catch (err) {
    setError(err?.message || "Failed to load Palette Viewers");
  } finally {
    setLoading(false);
  }
}, [loadDetail, loadList, loadPalettes, selectedId]);

useEffect(() => {
  loadPage();
}, [loadPage]);

  useEffect(() => {
    loadPage();
  }, [loadPage]);

  function startNewViewer() {
    setSelectedId(null);
    setIsNew(true);
    setStatus("");
    setError("");
    setDetail(normalizeDetail({
      viewer: EMPTY_VIEWER,
      palette: null,
      members: [],
      photos: [],
    }));
  }

  async function loadPaletteDetail(savedPaletteId) {
    if (!savedPaletteId) return;
    const data = await readJson(
      await fetch(`${API_URL}?mode=palette&saved_palette_id=${encodeURIComponent(savedPaletteId)}&_=${Date.now()}`, {
        credentials: "include",
      }),
      "Failed to load Saved Palette"
    );
    setDetail((current) => {
      if (!current || String(current.viewer.saved_palette_id) !== String(savedPaletteId)) return current;
      return {
        ...current,
        palette: data.item?.palette || current.palette,
        members: Array.isArray(data.item?.members) ? data.item.members : [],
      };
    });
  }

  function updateViewer(key, value) {
    setDetail((current) => {
      if (!current) return current;
      const nextViewer = { ...current.viewer, [key]: value };
      const nextPalette =
        key === "saved_palette_id"
          ? paletteMap.get(String(value)) || null
          : current.palette;
      return {
        ...current,
        viewer: nextViewer,
        palette: nextPalette,
        members: key === "saved_palette_id" ? [] : current.members,
      };
    });
    if (key === "saved_palette_id" && value) {
      loadPaletteDetail(value).catch((err) => setError(err?.message || "Failed to load Saved Palette"));
    }
  }

  function updatePhoto(index, key, value) {
    setDetail((current) => {
      if (!current) return current;
      const photos = current.photos.map((photo, photoIndex) =>
        photoIndex === index ? { ...photo, [key]: value } : photo
      );
      return { ...current, photos };
    });
  }

  function movePhoto(index, direction) {
    setDetail((current) => {
      if (!current) return current;
      const nextIndex = index + direction;
      if (nextIndex < 0 || nextIndex >= current.photos.length) return current;
      const photos = [...current.photos];
      const [photo] = photos.splice(index, 1);
      photos.splice(nextIndex, 0, photo);
      return { ...current, photos: renumberPhotos(photos) };
    });
  }

  function removePhoto(index) {
    setDetail((current) => {
      if (!current) return current;
      return {
        ...current,
        photos: renumberPhotos(current.photos.filter((_, photoIndex) => photoIndex !== index)),
      };
    });
  }

  function handlePhotoPick(photo) {
    setPhotoPickerOpen(false);
    setDetail((current) => {
      if (!current) return current;
      const hasPhotos = current.photos.length > 0;
      const relPath = photo.raw_rel_path || photo.image_url || "";
      return {
        ...current,
        photos: [
          ...current.photos,
          {
            palette_viewer_photo_id: null,
            palette_viewer_id: current.viewer.palette_viewer_id || null,
            photo_library_id: photo.photo_library_id || null,
            rel_path: relPath,
            photo_type: hasPhotos ? "inset" : "full",
            trigger_mode: "any",
            trigger_color_id: "",
            caption: "",
            alt_text: photo.title || "",
            order_index: current.photos.length,
          },
        ],
      };
    });
  }

  async function saveViewer() {
    if (!detail || !canSave) return;
    setSaving(true);
    setError("");
    setStatus("");
    try {
      const payload = {
        viewer: {
          ...detail.viewer,
          saved_palette_id: Number(detail.viewer.saved_palette_id),
          is_active: Number(detail.viewer.is_active) ? 1 : 0,
        },
        photos: renumberPhotos(detail.photos).map((photo) => ({
          ...photo,
          photo_library_id: photo.photo_library_id ? Number(photo.photo_library_id) : null,
          rel_path: photo.photo_library_id ? "" : photo.rel_path,
          trigger_color_id: photo.trigger_color_id ? Number(photo.trigger_color_id) : null,
          order_index: Number(photo.order_index || 0),
        })),
      };
      const data = await readJson(
        await fetch(API_URL, {
          method: "POST",
          credentials: "include",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify(payload),
        }),
        "Failed to save Palette Viewer"
      );
      const saved = normalizeDetail(data.item);
      setDetail(saved);
      setSelectedId(Number(saved.viewer.palette_viewer_id));
      setIsNew(false);
      const warning = data.item?.rex_warning || saved.rex?.warning || "";
      setStatus(`Palette Viewer saved.${warning ? ` REX warning: ${warning}` : ""}`);
      await loadList();
    } catch (err) {
      setError(err?.message || "Failed to save Palette Viewer");
    } finally {
      setSaving(false);
    }
  }

  async function selectViewer(id) {
    try {
      await loadDetail(id);
    } catch (err) {
      setError(err?.message || "Failed to load Palette Viewer");
    }
  }

  function openPreview() {
    if (!selectedViewerId) return;
    if (!previewUrl) {
      setError(detail?.rex?.warning || "Save Viewer first to create the Palette Viewer REX URL.");
      return;
    }
    window.open(previewUrl, "_blank", "noopener,noreferrer");
  }

  return (
    <AdminMasterDetail
      className="admin-palette-viewers"
      storageKey="admin-palette-viewers-list-width"
      defaultListWidth={360}
      list={
        <AdminListPane
          title="Palette Viewers"
          actions={
            <button type="button" className="admin-palette-viewers__new" onClick={startNewViewer}>
              + New Viewer
            </button>
          }
          toolbar={
            <div className="admin-palette-viewers__list-meta">
              {loading ? "Loading..." : `${items.length} canonical viewers`}
            </div>
          }
        >
          {items.length ? (
            <AdminObjectList ariaLabel="Palette Viewers">
              {items.map((row) => {
                const viewer = row.viewer || {};
                const palette = row.palette || {};
                const rexIds = Array.isArray(row.rex) ? row.rex : [];
                return (
                  <AdminObjectListItem
                    key={viewer.palette_viewer_id}
                    id={viewer.palette_viewer_id}
                    title={viewerTitle(row)}
                    meta={[
                      paletteLabel(palette),
                      `${row.photo_count || 0} photo${Number(row.photo_count || 0) === 1 ? "" : "s"}`,
                      viewer.is_active ? "Active" : "Inactive",
                    ]}
                    selected={!isNew && Number(selectedId) === Number(viewer.palette_viewer_id)}
                    status={{
                      active: rexIds.length > 0,
                      count: rexIds.length,
                      label: `${rexIds.length} active REX reservation${rexIds.length === 1 ? "" : "s"}`,
                    }}
                    onSelect={() => selectViewer(viewer.palette_viewer_id)}
                    onStatusClick={() => {
                      if (!rexIds.length) return;
                      setRexDialog({
                        open: true,
                        reservationIds: rexIds,
                        title: viewerTitle(row),
                      });
                    }}
                  />
                );
              })}
            </AdminObjectList>
          ) : (
            <AdminEmptyState title="No Palette Viewers" message="Create a viewer after selecting a Saved Palette." />
          )}
        </AdminListPane>
      }
      detail={
        <AdminDetailPane className="admin-palette-viewers__detail" ariaLabel="Palette Viewer detail">
          {error ? <div className="admin-palette-viewers__message admin-palette-viewers__message--error">{error}</div> : null}
          {status ? <div className="admin-palette-viewers__message admin-palette-viewers__message--status">{status}</div> : null}
          {!detail ? (
            <AdminEmptyState title="Select a Palette Viewer" message="Choose an existing viewer or start a new one." />
          ) : (
            <div className="admin-palette-viewers__form">
              <header className="admin-palette-viewers__header">
                <div>
                  <h1>Palette Viewer</h1>
                  <p>{isNew ? "Unsaved new viewer" : `#${detail.viewer.palette_viewer_id}`}</p>
                </div>
                <div className="admin-palette-viewers__actions">
                  <button type="button" onClick={saveViewer} disabled={!canSave}>
                    {saving ? "Saving..." : "Save Viewer"}
                  </button>
                  <button type="button" className="secondary" onClick={openPreview} disabled={previewDisabled}>
                    Preview Viewer
                  </button>
                </div>
              </header>

              <section className="admin-palette-viewers__section">
                <h2>Viewer</h2>
                <div className="admin-palette-viewers__fields">
                  <label className="admin-palette-viewers__field is-wide">
                    <span>Saved Palette</span>
                    <select
                      value={detail.viewer.saved_palette_id}
                      onChange={(event) => updateViewer("saved_palette_id", event.target.value)}
                    >
                      <option value="">Choose Saved Palette</option>
                      {palettes.map((palette) => (
                        <option key={palette.id} value={palette.id}>
                          {paletteLabel(palette)} #{palette.id}
                        </option>
                      ))}
                    </select>
                  </label>

                  <label className="admin-palette-viewers__field">
                    <span>Format</span>
                    <select value={detail.viewer.format || "public"} onChange={(event) => updateViewer("format", event.target.value)}>
                      <option value="public">public</option>
                    </select>
                  </label>

                  <label className="admin-palette-viewers__field">
                    <span>Template Key</span>
                    <input value={detail.viewer.template_key || ""} onChange={(event) => updateViewer("template_key", event.target.value)} />
                  </label>

                  <div className="admin-palette-viewers__kicker-title">
                    <label className="admin-palette-viewers__field">
                      <span>Kicker</span>
                      <KickerDropdown
                        textValue={detail.viewer.kicker_text || ""}
                        blankLabel="Choose kicker"
                        onChange={(_, kicker) => updateViewer("kicker_text", kicker?.display_text || "")}
                      />
                    </label>
                    <label className="admin-palette-viewers__field">
                      <span>Kicker Text</span>
                      <input value={detail.viewer.kicker_text || ""} onChange={(event) => updateViewer("kicker_text", event.target.value)} />
                    </label>
                    <label className="admin-palette-viewers__field admin-palette-viewers__title-field">
                      <span>Title</span>
                      <input value={detail.viewer.title || ""} onChange={(event) => updateViewer("title", event.target.value)} />
                    </label>
                  </div>

                  <label className="admin-palette-viewers__field is-wide">
                    <span>Intro</span>
                    <textarea rows={3} value={detail.viewer.intro || ""} onChange={(event) => updateViewer("intro", event.target.value)} />
                  </label>

                  <label className="admin-palette-viewers__field is-wide">
                    <span>Notes</span>
                    <textarea rows={4} value={detail.viewer.notes || ""} onChange={(event) => updateViewer("notes", event.target.value)} />
                  </label>

                  <label className="admin-palette-viewers__field">
                    <span>CTA Label</span>
                    <input value={detail.viewer.cta_label || ""} onChange={(event) => updateViewer("cta_label", event.target.value)} />
                  </label>

                  <label className="admin-palette-viewers__check">
                    <input
                      type="checkbox"
                      checked={Number(detail.viewer.is_active) === 1}
                      onChange={(event) => updateViewer("is_active", event.target.checked ? 1 : 0)}
                    />
                    <span>Active</span>
                  </label>
                </div>
              </section>

              <section className="admin-palette-viewers__section">
                <h2>Attached Palette</h2>
                {detail.palette ? (
                  <div className="admin-palette-viewers__palette">
                    <div className="admin-palette-viewers__palette-title">
                      <strong>{paletteLabel(detail.palette)}</strong>
                      <span>#{detail.palette.id}</span>
                    </div>
                    {detail.members.length ? (
                      <div className="admin-palette-viewers__swatches">
                        {detail.members.map((member, index) => (
                          <div key={`${member.color_id}-${index}`} className="admin-palette-viewers__swatch-row">
                            <span
                              className="admin-palette-viewers__swatch"
                              style={{ backgroundColor: member.color_hex6 ? `#${String(member.color_hex6).replace(/^#/, "")}` : "#fff" }}
                            />
                            <span>{member.color_name || "Color"}</span>
                            <code>{member.color_code || ""}</code>
                            <em>{member.role || ""}</em>
                          </div>
                        ))}
                      </div>
                    ) : (
                      <p className="admin-palette-viewers__muted">Palette colors load after saving or selecting an existing viewer.</p>
                    )}
                  </div>
                ) : (
                  <p className="admin-palette-viewers__muted">Choose a Saved Palette before saving.</p>
                )}
              </section>

              <section className="admin-palette-viewers__section">
                <div className="admin-palette-viewers__section-head">
                  <h2>Photos</h2>
                  <button type="button" className="secondary" onClick={() => setPhotoPickerOpen(true)}>
                    Add from Photo Library
                  </button>
                </div>
                {detail.photos.length ? (
                  <div className="admin-palette-viewers__photo-list">
                    <div className="admin-palette-viewers__photo-head">
                      <span>Thumbnail</span>
                      <span>Type</span>
                      <span>Trigger</span>
                      <span>Caption / Alt</span>
                      <span>Order</span>
                    </div>
                    {detail.photos.map((photo, index) => (
                      <div key={`${photo.palette_viewer_photo_id || "new"}-${index}`} className="admin-palette-viewers__photo-row">
                        <div className="admin-palette-viewers__photo-thumb">
                          {photo.rel_path ? <img src={buildImageUrl(photo.rel_path)} alt="" /> : <span>No preview</span>}
                          <small>{photoLabel(photo)}</small>
                        </div>
                        <select value={photo.photo_type || "inset"} onChange={(event) => updatePhoto(index, "photo_type", event.target.value)}>
                          {PHOTO_TYPES.map((type) => <option key={type} value={type}>{type}</option>)}
                        </select>
                        <div className="admin-palette-viewers__photo-trigger">
                          <select value={photo.trigger_mode || "any"} onChange={(event) => updatePhoto(index, "trigger_mode", event.target.value)}>
                            {TRIGGER_MODES.map((mode) => <option key={mode} value={mode}>{mode}</option>)}
                          </select>
                          <input
                            type="number"
                            value={photo.trigger_color_id || ""}
                            onChange={(event) => updatePhoto(index, "trigger_color_id", event.target.value)}
                            placeholder="color id"
                          />
                        </div>
                        <div className="admin-palette-viewers__photo-copy">
                          <input value={photo.caption || ""} onChange={(event) => updatePhoto(index, "caption", event.target.value)} placeholder="caption" />
                          <input value={photo.alt_text || ""} onChange={(event) => updatePhoto(index, "alt_text", event.target.value)} placeholder="alt text" />
                        </div>
                        <div className="admin-palette-viewers__photo-actions">
                          <input
                            type="number"
                            value={photo.order_index ?? index}
                            onChange={(event) => updatePhoto(index, "order_index", event.target.value)}
                          />
                          <button type="button" onClick={() => movePhoto(index, -1)} disabled={index === 0}>Up</button>
                          <button type="button" onClick={() => movePhoto(index, 1)} disabled={index === detail.photos.length - 1}>Down</button>
                          <button type="button" className="danger" onClick={() => removePhoto(index)}>Remove</button>
                        </div>
                      </div>
                    ))}
                  </div>
                ) : (
                  <p className="admin-palette-viewers__muted">No viewer photos selected.</p>
                )}
              </section>
            </div>
          )}

          <PhotoPickerModal
            open={photoPickerOpen}
            title="Add Viewer Photo"
            onClose={() => setPhotoPickerOpen(false)}
            onPick={handlePhotoPick}
          />
          <RexManagementDialog
            open={rexDialog.open}
            reservationIds={rexDialog.reservationIds}
            title={rexDialog.title}
            onClose={() => {
              setRexDialog({
                open: false,
                reservationIds: [],
                title: "",
              });
            }}
          />
        </AdminDetailPane>
      }
    />
  );
}

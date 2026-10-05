import {
  useCallback,
  useEffect,
  useMemo,
  useRef,
  useState,
} from "react";
import { GripVertical, ImagePlus, RotateCcw, Save, Upload } from "lucide-react";
import {
  closestCenter,
  DndContext,
  PointerSensor,
  useDraggable,
  useDroppable,
  useSensor,
  useSensors,
} from "@dnd-kit/core";

import {
  AdminButton,
  AdminDialog,
  AdminDetailPane,
  AdminEmptyState,
  AdminFullScreenOverlay,
  AdminMetaText,
  AdminNotice,
  AdminPanel,
  AdminStack,
  AdminToolbar,
  AdminToolbarSpacer,
} from "@components/AdminLayout";

import PhotoPickerDialog from "@components/Dialogs/PhotoPickerDialog";
import ProjectAreaDropdown from "@components/Project/ProjectAreaDropdown";
import { API_FOLDER } from "@helpers/config";
import { buildImageUrl } from "@helpers/assetImage";
import { photoIdentity, samePhoto } from "./pvPhotos";

const PHOTOS_URL = `${API_FOLDER}/v2/admin/projects/photos.php`;

const ROLE_OPTIONS = [
  { value: "full", label: "Main" },
  { value: "zoom", label: "Zoom" },
  { value: "before", label: "Before" },
];

function cleanText(value) {
  return String(value ?? "").trim();
}

async function readJson(response, fallbackMessage) {
  const text = await response.text();
  let data = {};

  try {
    data = text.trim() ? JSON.parse(text) : {};
  } catch {
    throw new Error(`${fallbackMessage}: invalid JSON response`);
  }

  if (!response.ok || data?.ok === false) {
    const error = new Error(data?.error || `HTTP ${response.status}`);
    error.code = data?.code;
    throw error;
  }

  return data;
}

function paletteLabel(row) {
  const name = cleanText(row?.display_title)
    || cleanText(row?.nickname)
    || `Palette #${row?.saved_palette_id || row?.project_palette_id || ""}`;
  const area = cleanText(row?.area_label);
  return area ? `${area} / ${name}` : name;
}

function ensurePaletteMains(photos, paletteIds) {
  let next = photos;
  for (const paletteId of new Set(paletteIds.filter(Boolean))) {
    const eligible = next.filter((photo) => photo.palette_id === paletteId && photo.use && photo.role !== "before");
    const main = eligible.find((photo) => photo.role === "full") || eligible[0];
    if (!main) continue;
    next = next.map((photo) => eligible.includes(photo)
      ? { ...photo, role: photo._key === main._key ? "full" : "zoom" } : photo);
  }
  return next;
}

function PhotoGroup({ group, busy, children }) {
  const { setNodeRef, isOver } = useDroppable({
    id: group.roomId !== undefined ? `room:${group.roomId || "unassigned"}` : `palette:${group.paletteId || "unassigned"}`, disabled: busy,
    data: { paletteId: group.paletteId, roomId: group.roomId, group: true },
  });
  return (
    <section className="admin-project-photo-group" aria-label={group.label}>
      <header ref={setNodeRef} className={`admin-project-photo-group__header${isOver ? " is-drop-target" : ""}`}>
        <div>
          <h2>{group.area}</h2>
          {group.name ? <div className="admin-project-photo-group__name">{group.name}</div> : null}
        </div>
        <AdminMetaText>{group.photos.length} photo{group.photos.length === 1 ? "" : "s"}</AdminMetaText>
      </header>
      <div className="admin-project-photos">{children}</div>
    </section>
  );
}

function photoUrl(photo) {
  const raw = cleanText(photo?.image_url || photo?.rel_path || photo?.raw_rel_path || photo?.file_path);
  return raw ? buildImageUrl(raw, photo?.updated_at || null) : "";
}

function normalizePhoto(photo, index = 0) {
  const role = cleanText(photo?.role || photo?.photo_type).toLowerCase();

  return {
    ...photo,
    _key: photoIdentity(photo) || `new:${index}:${Date.now()}`,
    photo_library_id: Number(photo?.photo_library_id || 0),
    role: ROLE_OPTIONS.some((option) => option.value === role) ? role : "zoom",
    palette_id: Number(photo?.palette_id || 0) || null,
    room_id: photo?.room_id || null,
    use: Boolean(photo?.use),
  };
}

function DraggablePhotoRow({ item, index, busy, onMove, onPreview, children }) {
  const { attributes, listeners, setNodeRef: setDragRef, setActivatorNodeRef, transform, isDragging } = useDraggable({
    id: item._key, disabled: busy, data: { index, paletteId: item.palette_id },
  });
  const { setNodeRef: setDropRef, isOver, active } = useDroppable({
    id: item._key, disabled: busy, data: { paletteId: item.palette_id },
  });
  const setNodeRef = useCallback((node) => {
    setDragRef(node);
    setDropRef(node);
  }, [setDragRef, setDropRef]);
  const dropClass = isOver && !isDragging
    ? (active?.data.current?.index < index ? " is-drop-after" : " is-drop-before") : "";
  const src = photoUrl(item);

  return (
    <article ref={setNodeRef}
      className={`admin-project-photos__row${!item.use ? " is-unused" : ""}${isDragging ? " is-dragging" : ""}${dropClass}`}
      style={transform ? { transform: `translate3d(${transform.x}px, ${transform.y}px, 0)` } : undefined}>
      <button ref={setActivatorNodeRef} type="button" className="admin-project-photos__grip" disabled={busy}
        {...attributes} {...listeners}
        aria-label={`Reorder photo ${item.photo_library_id}`} title="Move row"
        onKeyDown={(event) => {
          if (event.key !== "ArrowUp" && event.key !== "ArrowDown") return;
          event.preventDefault();
          onMove(index, event.key === "ArrowUp" ? -1 : 1);
        }}>
        <GripVertical size={18} />
      </button>
      <button type="button" className="admin-project-photos__thumb" disabled={busy || !src}
        aria-label={`View photo ${item.photo_library_id}`} title="Click to view full screen"
        onClick={() => onPreview(item)}>
        {src ? <img src={src} alt="" loading="lazy" draggable={false} /> : null}
      </button>
      {children}
    </article>
  );
}

export default function ProjectPhotos({
  projectId,
  projectName = "",
  rooms = [],
}) {
  const [areaFilter, setAreaFilter] = useState("__all__");
  const [libraryOwnsPalette, setLibraryOwnsPalette] = useState(false);
  const [roomsAssignable, setRoomsAssignable] = useState(false);
  const [items, setItems] = useState([]);
  const [palettes, setPalettes] = useState([]);
  const [revision, setRevision] = useState("");
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [uploading, setUploading] = useState(false);
  const [dirty, setDirty] = useState(false);
  const [pickerOpen, setPickerOpen] = useState(false);
  const [previewPhoto, setPreviewPhoto] = useState(null);
  const previewButton = useRef(null);
  const [error, setError] = useState("");
  const [status, setStatus] = useState("");
  const [conflict, setConflict] = useState(false);
  const [confirmReplace, setConfirmReplace] = useState(false);
  const numericProjectId = Number(projectId || 0);
  const fileInput = useRef(null);
  const busy = saving || uploading;
  const draftKey = `project-photos-draft:${numericProjectId}`;
  const sensors = useSensors(useSensor(PointerSensor, { activationConstraint: { distance: 6 } }));

  const title = `${projectName || `Project #${numericProjectId}`} Photos`;
  const groups = useMemo(() => {
    if (roomsAssignable) {
      const byRoom = new Map((Array.isArray(rooms) ? rooms : []).map((room) => [room.id, {
        roomId: room.id, areaKey: room.id, area: room.name, label: room.name, photos: [],
      }]));
      const unassigned = { roomId: null, areaKey: "", area: "Unassigned", label: "Unassigned", photos: [] };
      items.forEach((item, index) => {
        (byRoom.get(item.room_id) || unassigned).photos.push({ item, index });
      });
      return [...byRoom.values(), unassigned];
    }
    const byPalette = new Map();
    for (const palette of palettes) {
      const id = Number(palette.saved_palette_id);
      if (byPalette.has(id)) continue;
      const area = cleanText(palette.area_label) || "Unspecified area";
      const name = cleanText(palette.display_title) || cleanText(palette.nickname) || `Palette #${id}`;
      byPalette.set(id, { paletteId: id, areaKey: cleanText(palette.area_label), area, name, label: `${area} / ${name}`, photos: [] });
    }
    const unassigned = { paletteId: null, areaKey: "", area: "Unassigned", name: "", label: "Unassigned", photos: [] };
    items.forEach((item, index) => {
      if (item.palette_id && !byPalette.has(item.palette_id)) {
        const name = `Palette #${item.palette_id}`;
        byPalette.set(item.palette_id, { paletteId: item.palette_id, areaKey: "", area: "Unspecified area", name, label: name, photos: [] });
      }
      (byPalette.get(item.palette_id) || unassigned).photos.push({ item, index });
    });
    return [...byPalette.values(), unassigned];
  }, [items, palettes, rooms, roomsAssignable]);
  const visibleGroups = groups.filter((group) => areaFilter === "__all__" || group.areaKey === areaFilter);
  const visibleCount = visibleGroups.reduce((count, group) => count + group.photos.length, 0);

  useEffect(() => {
    setAreaFilter("__all__");
  }, [numericProjectId]);

  const load = useCallback(async () => {
    if (numericProjectId <= 0) {
      setItems([]);
      setPalettes([]);
      setError("Project ID is required.");
      setLoading(false);
      return;
    }

    setLoading(true);
    setError("");
    setStatus("");
    setConflict(false);

    try {
      const params = new URLSearchParams({
        project_id: String(numericProjectId),
        _: String(Date.now()),
      });
      const data = await readJson(
        await fetch(`${PHOTOS_URL}?${params.toString()}`, {
          credentials: "include",
          cache: "no-store",
        }),
        "Failed to load project photos"
      );

      let draft = null;
      try { draft = JSON.parse(window.sessionStorage.getItem(`project-photos-draft:${numericProjectId}`) || "null"); } catch { /* Storage may be unavailable. */ }
      if (draft && typeof draft.revision !== "string") draft = null;
      setItems((draft?.photos || (Array.isArray(data?.photos) ? data.photos : [])).map(normalizePhoto));
      setPalettes(Array.isArray(data?.palettes) ? data.palettes : []);
      setLibraryOwnsPalette(Boolean(data?.library_owns_palette));
      setRoomsAssignable(Boolean(data?.rooms_assignable || data?.library_owns_palette));
      setRevision(draft?.revision ?? data?.revision ?? "");
      setDirty(Boolean(draft) || (!data?.revision && Boolean(data?.photos?.length)));
      if (draft && draft.revision !== data.revision) {
        setConflict(true);
        setError("Your selections have not been saved. Project photos were updated elsewhere; choose Save My Changes to keep this window's selections.");
      }
    } catch (err) {
      setError(err?.message || "Failed to load project photos.");
    } finally {
      setLoading(false);
    }
  }, [numericProjectId]);

  useEffect(() => {
    load();
  }, [load]);

  useEffect(() => {
    if (loading) return;
    try {
      if (dirty) window.sessionStorage.setItem(draftKey, JSON.stringify({ revision, photos: items }));
      else window.sessionStorage.removeItem(draftKey);
    } catch { /* Storage may be unavailable. */ }
  }, [dirty, draftKey, items, loading, revision]);

  useEffect(() => {
    const warn = (event) => { if (busy) { event.preventDefault(); event.returnValue = ""; } };
    window.addEventListener("beforeunload", warn);
    return () => window.removeEventListener("beforeunload", warn);
  }, [busy]);

  useEffect(() => {
    if (!previewPhoto) return;
    const previousFocus = document.activeElement;
    const previousOverflow = document.body.style.overflow;
    document.body.style.overflow = "hidden";
    previewButton.current?.focus();
    const closeOnEscape = (event) => {
      if (event.key === "Escape") setPreviewPhoto(null);
    };
    window.addEventListener("keydown", closeOnEscape);
    return () => {
      document.body.style.overflow = previousOverflow;
      window.removeEventListener("keydown", closeOnEscape);
      if (previousFocus?.isConnected) previousFocus.focus();
    };
  }, [previewPhoto]);

  function updateItem(index, patch) {
    if (roomsAssignable) {
      if (patch.role === "before") { patch = { ...patch, palette_id: null }; }
      if (patch.palette_id) {
        const palette = palettes.find((entry) => Number(entry.saved_palette_id) === patch.palette_id);
        const room = rooms.find((entry) => entry.name === cleanText(palette?.area_label));
        if (room) { patch = { ...patch, room_id: room.id }; }
      }
    }
    setItems((current) => {
      const nextItem = { ...current[index], ...patch };
      const next = current.map((item, itemIndex) =>
        itemIndex === index ? { ...item, ...patch }
          : nextItem.role === "full" && item.role === "full"
            && nextItem.use && item.use && nextItem.palette_id
            && item.palette_id === nextItem.palette_id
            ? { ...item, role: "zoom" } : item
      );
      return Object.hasOwn(patch, "palette_id")
        ? ensurePaletteMains(next, [current[index].palette_id, nextItem.palette_id]) : next;
    });
    setDirty(true);
    setStatus("");
  }

  function addPhoto(photo) {
    setPickerOpen(false);
    const normalized = normalizePhoto({
      ...photo,
      rel_path: photo?.image_url || photo?.rel_path || photo?.file_path || "",
      role: "zoom",
      palette_id: palettes.length === 1 ? Number(palettes[0].saved_palette_id) : null,
      ...(roomsAssignable ? { room_id: areaFilter !== "__all__" ? areaFilter || null : null } : {}),
      ...(libraryOwnsPalette ? {
        palette_id: Number(photo?.palette_id) || null,
      } : {}),
      use: true,
    }, items.length);

    if (normalized.photo_library_id <= 0) {
      setError("Pick a Photo Library item so the project can reuse it reliably.");
      return;
    }

    setItems((current) => {
      if (current.some((item) => samePhoto(item, normalized))) return current;
      const mainExists = current.some((item) => item.use && item.role === "full" && item.palette_id === normalized.palette_id);
      return [...current, { ...normalized, role: normalized.palette_id && !mainExists ? "full" : "zoom" }];
    });
    setDirty(true);
    setStatus("");
    setError("");
  }

  function moveItem(index, direction) {
    const group = groups.find((entry) => roomsAssignable
      ? entry.roomId === items[index]?.room_id : entry.paletteId === items[index]?.palette_id);
    const position = group?.photos.findIndex((photo) => photo.index === index) ?? -1;
    const target = group?.photos[position + direction];
    if (busy || position < 0 || !target) return;
    setItems((current) => {
      const next = [...current];
      [next[index], next[target.index]] = [next[target.index], next[index]];
      return next;
    });
    setDirty(true);
    setStatus("");
  }

  function handleDragEnd({ active, over }) {
    if (busy || !over || active.id === over.id) return;
    setItems((current) => {
      const from = current.findIndex((item) => item._key === active.id);
      const to = current.findIndex((item) => item._key === over.id);
      const isGroup = over.data.current?.group;
      if (from < 0 || (!isGroup && to < 0)) return current;
      const paletteId = isGroup ? over.data.current.paletteId : current[to].palette_id;
      const previousPaletteId = current[from].palette_id;
      const next = [...current];
      const [removed] = next.splice(from, 1);
      const moved = { ...removed };
      if (roomsAssignable) {
        moved.room_id = isGroup ? over.data.current.roomId : current[to].room_id;
        const lastInRoom = next.reduce((last, photo, index) => photo.room_id === moved.room_id ? index : last, -1);
        next.splice(isGroup ? lastInRoom + 1 : to, 0, moved);
        return next;
      }
      moved.palette_id = paletteId;
      if (paletteId !== previousPaletteId && moved.role === "full"
        && next.some((photo) => photo.palette_id === paletteId && photo.use && photo.role === "full")) {
        moved.role = "zoom";
      }
      const lastInGroup = next.reduce((last, photo, index) => photo.palette_id === paletteId ? index : last, -1);
      next.splice(isGroup ? lastInGroup + 1 : to, 0, moved);
      return paletteId !== previousPaletteId ? ensurePaletteMains(next, [previousPaletteId, paletteId]) : next;
    });
    setDirty(true);
    setStatus("");
  }

  async function upload(files) {
    if (!files.length || busy) return;
    setUploading(true);
    setError("");
    try {
      for (const file of files) {
        const form = new FormData();
        form.append("photo", file);
        form.append("tags", `project-${numericProjectId}`);
        form.append("title", file.name.replace(/\.[^.]+$/, ""));
        const data = await readJson(await fetch(`${API_FOLDER}/v2/admin/photos/upload.php`, {
          method: "POST", credentials: "include", body: form,
        }), "Upload failed");
        if (!data.photo) throw new Error("Upload returned no photo.");
        addPhoto(data.photo);
      }
    } catch (err) { setError(err.message); }
    finally { setUploading(false); if (fileInput.current) fileInput.current.value = ""; }
  }

  function discardDraft() {
    try { window.sessionStorage.removeItem(draftKey); } catch { /* Storage may be unavailable. */ }
    void load();
  }

  async function save(replace = false) {
    if (conflict && !replace) { setConfirmReplace(true); return; }
    setConfirmReplace(false);
    setSaving(true);
    setError("");
    setStatus("");

    try {
      let saveRevision = revision;
      let saveItems = items;
      if (replace) {
        const params = new URLSearchParams({ project_id: String(numericProjectId), _: String(Date.now()) });
        const latest = await readJson(await fetch(`${PHOTOS_URL}?${params}`, {
          credentials: "include", cache: "no-store",
        }), "Failed to load the latest project photos");
        saveRevision = latest.revision || "";
        const known = new Set(items.map((item) => item.photo_library_id));
        saveItems = [...items, ...(latest.photos || []).filter((photo) => !known.has(Number(photo.photo_library_id))).map(normalizePhoto)];
      }
      const data = await readJson(
        await fetch(PHOTOS_URL, {
          method: "POST",
          credentials: "include",
          headers: {
            "Content-Type": "application/json",
          },
          body: JSON.stringify({
            project_id: numericProjectId,
            revision: saveRevision,
            photos: saveItems.map((item) => ({
              photo_library_id: Number(item.photo_library_id || 0),
              use: item.use,
              palette_id: item.palette_id,
              ...(roomsAssignable ? { room_id: item.room_id } : {}),
              main: item.role === "full",
              zoom: item.role === "zoom",
              before: item.role === "before",
            })),
          }),
        }),
        "Failed to save project photos"
      );

      setItems((Array.isArray(data?.photos) ? data.photos : []).map(normalizePhoto));
      setPalettes(Array.isArray(data?.palettes) ? data.palettes : palettes);
      setLibraryOwnsPalette(Boolean(data?.library_owns_palette));
      setRoomsAssignable(Boolean(data?.rooms_assignable || data?.library_owns_palette));
      setRevision(data?.revision || "");
      setDirty(false);
      setConflict(false);
      setStatus("Project photos saved.");
    } catch (err) {
      if (err.code === "project_photos_conflict") {
        setConflict(true);
        setError("Your selections have not been saved. Project photos were updated elsewhere; choose Save My Changes to keep this window's selections.");
      } else {
        setError(err?.message || "Failed to save project photos.");
      }
    } finally {
      setSaving(false);
    }
  }

  return (
    <AdminDetailPane
      ariaLabel="Project photos"
      title={title}
      actions={(
        <>
          <AdminButton type="button" variant="secondary" disabled={busy || loading} onClick={() => fileInput.current?.click()}>
            <Upload size={16} /> {uploading ? "Uploading..." : "Upload Photos"}
          </AdminButton>
          <AdminButton
            type="button"
            variant="secondary"
            onClick={() => setPickerOpen(true)}
            disabled={busy || loading}
          >
            <ImagePlus size={16} /> Add from Library
          </AdminButton>
          <AdminButton
            type="button"
            variant={!dirty && Boolean(revision) ? "secondary" : "primary"}
            disabled={busy || loading || (!dirty && Boolean(revision)) || Boolean(error && !palettes.length)}
            onClick={() => void save()}
          >
            <Save size={16} /> {saving ? "Saving..." : conflict ? "Save My Changes" : !dirty && revision ? "Saved" : "Save"}
          </AdminButton>
          {dirty && <AdminButton type="button" variant="secondary" title="Discard changes" aria-label="Discard changes" disabled={busy || loading} onClick={discardDraft}><RotateCcw size={16} /></AdminButton>}
        </>
      )}
      subActions={error || status || dirty ? (
        <div className="admin-project-photos__feedback">
          {error ? <AdminNotice variant="danger">{error}</AdminNotice> : null}
          {status ? <AdminNotice variant="success">{status}</AdminNotice> : null}
          {dirty ? <AdminMetaText>Unsaved changes</AdminMetaText> : null}
        </div>
      ) : null}
    >
      <input ref={fileInput} type="file" multiple accept="image/jpeg,image/png,image/webp" hidden onChange={(event) => void upload([...event.target.files])} />
      <AdminStack gap="md">
        {loading ? (
          <AdminEmptyState title="Photos" message="Loading project photos..." />
        ) : (
          <AdminPanel
            className="admin-project-photos-panel"
            title="Photos"
            meta={areaFilter === "__all__"
              ? `${items.length} photo${items.length === 1 ? "" : "s"}`
              : `${visibleCount} of ${items.length} photos`}
            actions={(
              <>
              <ProjectAreaDropdown
                rooms={rooms}
                valueKey={roomsAssignable ? "id" : "name"}
                value={areaFilter}
                onChange={setAreaFilter}
                includeAll
                allLabel="All Rooms"
                aria-label="Filter photos by room"
                disabled={busy}
                className="admin-field__control admin-project-photos__area-filter"
              />
              <AdminButton
                type="button"
                variant="secondary"
                onClick={() => setPickerOpen(true)}
                disabled={busy}
              >
                Add
              </AdminButton>
              </>
            )}
          >
            {visibleCount ? (
              <DndContext sensors={sensors} collisionDetection={closestCenter} onDragEnd={handleDragEnd}
                accessibility={{ screenReaderInstructions: { draggable: "Use up and down arrow keys to reorder photos." } }}>
              <div className="admin-project-photo-groups">
                {visibleGroups.map((group) => (
                  <PhotoGroup key={roomsAssignable ? group.roomId || "unassigned" : group.paletteId || "unassigned"} group={group} busy={busy}>
                {group.photos.map(({ item, index }) => {
                  return (
                    <DraggablePhotoRow key={item._key} item={item} index={index} busy={busy} onMove={moveItem} onPreview={setPreviewPhoto}>

                      <div className="admin-project-photos__main">
                        <AdminToolbar compact>
                          <strong>
                            {cleanText(item.alt_text) || `Photo #${item.photo_library_id}`}
                          </strong>
                          <AdminToolbarSpacer />
                          <AdminMetaText>
                            #{item.photo_library_id}{item.from_playlist ? " / Playlist" : ""}
                          </AdminMetaText>
                        </AdminToolbar>

                        <div className="admin-project-photos__fields">
                        <div className="admin-project-photos__roles">
                          <label>
                            <input type="checkbox" checked={item.use} disabled={busy}
                              onChange={(event) => updateItem(index, { use: event.target.checked })} />
                            <span>Use</span>
                          </label>
                          {ROLE_OPTIONS.map((option) => (
                            <label key={option.value}>
                              <input
                                type="radio"
                                name={`project-photo-role-${index}`}
                                checked={item.role === option.value}
                                disabled={busy}
                                onChange={() => updateItem(index, { role: option.value })}
                              />
                              <span>{option.label}</span>
                            </label>
                          ))}
                        </div>

                        <div className="admin-project-photos__palettes">
                          {roomsAssignable && <label>
                            <span>Area</span>
                            <ProjectAreaDropdown rooms={rooms} value={item.room_id || ""} disabled={busy}
                              aria-label={`Area for photo ${item.photo_library_id}`}
                              onChange={(roomId) => updateItem(index, { room_id: roomId || null })} />
                          </label>}
                          <label>
                            <span>Palette</span>
                            <select aria-label={`Palette for photo ${item.photo_library_id}`}
                              value={item.palette_id || ""} disabled={busy || (roomsAssignable && item.role === "before")}
                              onChange={(event) => updateItem(index, { palette_id: Number(event.target.value) || null })}>
                              <option value="">{roomsAssignable && item.role === "before" ? "No palette (Before)" : "Unassigned"}</option>
                              {palettes.map((palette) => (
                                <option key={palette.project_palette_id} value={palette.saved_palette_id}>
                                  {paletteLabel(palette)}
                                </option>
                              ))}
                            </select>
                          </label>
                        </div>
                        </div>
                      </div>
                    </DraggablePhotoRow>
                  );
                })}
                  </PhotoGroup>
                ))}
              </div>
              </DndContext>
            ) : (
              <AdminEmptyState
                title={items.length ? "No photos in this area" : "No project photos"}
                message=""
              />
            )}
          </AdminPanel>
        )}
      </AdminStack>

      <AdminDialog
        open={confirmReplace}
        title="Save These Photo Selections?"
        message="This will replace the saved Use settings, photo roles, palette assignments, and ordering with the selections in this window. Other windows' changes to these photos may be overwritten. Newly added photos will be kept."
        confirmLabel="Save My Changes"
        onConfirm={() => void save(true)}
        onCancel={() => setConfirmReplace(false)}
      />
      <PhotoPickerDialog
        open={pickerOpen}
        title="Add Project Photo"
        onClose={() => setPickerOpen(false)}
        onPick={addPhoto}
      />
      <AdminFullScreenOverlay open={Boolean(previewPhoto)} className="admin-project-photo-preview" ariaLabel="Photo preview">
        {previewPhoto ? (
          <button ref={previewButton} type="button" className="admin-project-photo-preview__close"
            aria-label="Close photo preview" title="Click to close" onClick={() => setPreviewPhoto(null)}>
            <img src={photoUrl(previewPhoto)} alt={cleanText(previewPhoto.alt_text)} draggable={false} />
          </button>
        ) : null}
      </AdminFullScreenOverlay>
    </AdminDetailPane>
  );
}

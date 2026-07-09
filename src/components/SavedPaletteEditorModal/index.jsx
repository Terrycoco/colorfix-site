import { useEffect, useMemo, useState } from "react";
import { API_FOLDER } from "@helpers/config";
import EditableSwatch from "@components/EditableSwatch";
import FuzzySearchColorSelect from "@components/FuzzySearchColorSelect";
import KickerDropdown from "@components/KickerDropdown";
import PhotoPickerModal from "@components/PhotoPickerModal";
import "@pages/AdminSavedPalettesPage/admin-saved-palettes.css";

const LIST_URL = `${API_FOLDER}/v2/admin/saved-palettes.php`;
const SAVE_URL = `${API_FOLDER}/v2/admin/saved-palette-save.php`;
const UPDATE_URL = `${API_FOLDER}/v2/admin/saved-palette-update.php`;
const PHOTO_DELETE_URL = `${API_FOLDER}/v2/admin/saved-palette-photos/delete.php`;
const PHOTO_LIBRARY_ADD_URL = `${API_FOLDER}/v2/admin/saved-palette-photos/add-from-library.php`;
const PHOTO_LIBRARY_LINKS_URL = `${API_FOLDER}/v2/admin/saved-palette-photos/by-library.php`;
const PALETTE_GET_URL = `${API_FOLDER}/v2/admin/saved-palettes.php`;

const BRAND_CHOICES = [
  { code: "", label: "Pick Brand" },
  { code: "de", label: "Dunn Edwards" },
  { code: "sw", label: "Sherwin-Williams" },
  { code: "behr", label: "Behr" },
  { code: "bm", label: "Benjamin Moore" },
  { code: "multi", label: "Multi" },
  { code: "ppg", label: "PPG" },
  { code: "vs", label: "Valspar" },
  { code: "vist", label: "Vista Paint" },
  { code: "fb", label: "Farrow & Ball" },
];

const emptyEditForm = {
  palette_id: null,
  brand: "",
  nickname: "",
  display_title: "",
  notes: "",
  private_notes: "",
  terry_fav: false,
  kicker_id: "",
  palette_type: "exterior",
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

function normalizePalette(palette) {
  if (!palette) return null;
  const collapsedMembers = collapseMembersByColor(palette.members || []);
  return {
    id: Number(palette.id) || palette.id,
    form: {
      palette_id: Number(palette.id) || palette.id,
      brand: palette.brand || "",
      nickname: palette.nickname || "",
      display_title: palette.display_title || "",
      notes: palette.notes || "",
      private_notes: palette.private_notes || "",
      terry_fav: Number(palette.terry_fav) === 1,
      kicker_id: palette.kicker_id || "",
      palette_type: palette.palette_type || "exterior",
    },
    members: collapsedMembers.map((member, index) => ({
      key: member.id ?? `${member.color_id}-${index}`,
      color: memberToSwatch(member),
      role: member.role || "",
    })),
    photos: (palette.photos || []).map((photo, index) => ({
      id: photo.id,
      saved_palette_set_id: photo.saved_palette_set_id || null,
      rel_path: photo.rel_path,
      photo_type: photo.photo_type || "full",
      trigger_mode: photo.trigger_mode || "any",
      trigger_color_id: photo.trigger_color_id ?? null,
      caption: photo.caption || "",
      alt_text: photo.alt_text || "",
      order_index: photo.order_index ?? index,
    })),
    sets: Array.isArray(palette.sets) ? palette.sets : [],
  };
}

function rawRelPath(value = "") {
  const input = String(value || "").trim();
  if (!input) return "";
  try {
    const url = new URL(input, typeof window !== "undefined" ? window.location.origin : "http://localhost");
    return url.pathname || input;
  } catch {
    return input;
  }
}

function applyPhotoFieldUpdate(photo, field, value) {
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
}

function formatViewerLabel(set) {
  if (!set) return "";
  const base = set.title || set.slug || `Viewer`;
  const suffix = set.id ? `#${set.id}` : "";
  const defaultTag = Number(set.is_default) === 1 ? "Default" : "";
  return [base, suffix, defaultTag].filter(Boolean).join(" ");
}

export default function SavedPaletteEditorModal({
  open = false,
  paletteId = null,
  attachment = null,
  showPhotoSection = !!attachment,
  onClose,
  onChanged,
  onSaved,
}) {
  const attachmentPreview = attachment?.rel_path || attachment?.image_url || "";
  const linkOnlyMode = showPhotoSection && !!attachment;

  const [items, setItems] = useState([]);
  const [loading, setLoading] = useState(false);
  const [loadError, setLoadError] = useState("");
  const [mode, setMode] = useState("edit");
  const [selectedId, setSelectedId] = useState("");
  const [editForm, setEditForm] = useState(emptyEditForm);
  const [editMembers, setEditMembers] = useState([]);
  const [editPhotos, setEditPhotos] = useState([]);
  const [photoPickerOpen, setPhotoPickerOpen] = useState(false);
  const [beforePickerOpen, setBeforePickerOpen] = useState(false);
  const [photoStatus, setPhotoStatus] = useState({ loading: false, error: "", success: "" });
  const [editStatus, setEditStatus] = useState({ loading: false, error: "", success: "" });
  const [existingLinks, setExistingLinks] = useState([]);
  const [attachEnabled, setAttachEnabled] = useState(true);
  const [attachPhotoType, setAttachPhotoType] = useState("full");
  const [attachTriggerMode, setAttachTriggerMode] = useState("any");
  const [attachTriggerColorId, setAttachTriggerColorId] = useState(null);
  const [selectedBeforePhoto, setSelectedBeforePhoto] = useState(null);
  const [activeSetId, setActiveSetId] = useState("");
  const [createNewGroup, setCreateNewGroup] = useState(false);
  const [fullAttachMode, setFullAttachMode] = useState("replace");
  const [attachChoiceOpen, setAttachChoiceOpen] = useState(false);

  useEffect(() => {
    if (!open) return;
    let active = true;
    setLoading(true);
    setLoadError("");
    const params = new URLSearchParams();
    params.set("limit", "200");
    params.set("with_members", "1");
    params.set("with_photos", "1");
    params.set("_", Date.now().toString());
    fetch(`${LIST_URL}?${params.toString()}`, { credentials: "include" })
      .then((res) => res.json())
      .then((data) => {
        if (!active) return;
        if (!data?.ok) throw new Error(data?.error || "Failed to load saved palettes");
        setItems(Array.isArray(data.items) ? data.items : []);
      })
      .catch((err) => {
        if (!active) return;
        setItems([]);
        setLoadError(err?.message || "Failed to load saved palettes");
      })
      .finally(() => {
        if (active) setLoading(false);
      });
    return () => {
      active = false;
    };
  }, [open]);

  async function refreshExistingLinks() {
    if (!attachment?.photo_library_id) return [];
    const params = new URLSearchParams();
    params.set("photo_library_id", String(attachment.photo_library_id));
    params.set("_", Date.now().toString());
    const linksRes = await fetch(`${PHOTO_LIBRARY_LINKS_URL}?${params.toString()}`, { credentials: "include" });
    const linksData = await linksRes.json().catch(() => ({}));
    if (!linksRes.ok || !linksData?.ok) {
      throw new Error(linksData?.error || "Failed to refresh linked viewers");
    }
    const nextLinks = Array.isArray(linksData.items) ? linksData.items : [];
    setExistingLinks(nextLinks);
    return nextLinks;
  }

  useEffect(() => {
    if (!open) return;
    setAttachEnabled(!!attachment);
    setAttachPhotoType(attachment?.photo_type || "full");
    setAttachTriggerMode(
      (attachment?.photo_type || "full") === "before"
        ? "none"
        : (attachment?.trigger_mode || "any")
    );
    setAttachTriggerColorId(
      (attachment?.photo_type || "full") === "before"
        ? null
        : (attachment?.trigger_color_id ?? null)
    );
    setSelectedBeforePhoto(null);
    setCreateNewGroup(false);
    setFullAttachMode("replace");
    if (paletteId) {
      setMode("edit");
      setSelectedId(String(paletteId));
    } else {
      setMode("create");
      setSelectedId("");
      setEditForm(emptyEditForm);
      setEditMembers([]);
      setEditPhotos([]);
    }
  }, [open, paletteId, attachment]);

  useEffect(() => {
    if (attachPhotoType === "before") {
      setAttachTriggerMode("none");
      setAttachTriggerColorId(null);
      return;
    }
    if (attachTriggerMode === "none") {
      setAttachTriggerMode("any");
    }
  }, [attachPhotoType, attachTriggerMode]);

  useEffect(() => {
    if (attachTriggerMode !== "color") {
      setAttachTriggerColorId(null);
    }
  }, [attachTriggerMode]);

  useEffect(() => {
    if (!open) return;
    if (mode === "create") {
      setEditForm(emptyEditForm);
      setEditMembers([]);
      setEditPhotos([]);
      setEditStatus({ loading: false, error: "", success: "" });
      setPhotoStatus({ loading: false, error: "" });
      return;
    }
    const palette = items.find((row) => String(row.id) === String(selectedId));
    if (!palette) return;
    const normalized = normalizePalette(palette);
    if (!normalized) return;
    setEditForm(normalized.form);
    setEditMembers(normalized.members);
    setEditPhotos(normalized.photos);
    const defaultSet = (normalized.sets || []).find((set) => Number(set.is_default) === 1) || normalized.sets?.[0] || null;
    setActiveSetId(defaultSet?.id ? String(defaultSet.id) : "");
    setCreateNewGroup(linkOnlyMode && normalized.photos.some((photo) => (photo.photo_type || "full") === "full"));
    setFullAttachMode("replace");
    setEditStatus({ loading: false, error: "", success: "" });
    setPhotoStatus({ loading: false, error: "" });
  }, [open, items, mode, selectedId, linkOnlyMode]);

  useEffect(() => {
    if (!showPhotoSection || !open || !editForm.palette_id || !activeSetId) return;
    let active = true;
    async function loadPaletteSet() {
      try {
        const params = new URLSearchParams();
        params.set("id", String(editForm.palette_id));
        params.set("set_id", String(activeSetId));
        params.set("with_members", "1");
        params.set("with_photos", "1");
        params.set("limit", "1");
        params.set("_", Date.now().toString());
        const res = await fetch(`${PALETTE_GET_URL}?${params.toString()}`, { credentials: "include" });
        const data = await res.json();
        if (!res.ok || !data?.ok) throw new Error(data?.error || "Failed to load palette set");
        const palette = Array.isArray(data.items) ? data.items[0] : null;
        if (!active || !palette) return;
        const normalized = normalizePalette(palette);
        if (!normalized) return;
        setEditPhotos(normalized.photos);
      } catch (err) {
        if (!active) return;
        setPhotoStatus({ loading: false, error: err?.message || "Failed to load set photos" });
      }
    }
    loadPaletteSet();
    return () => {
      active = false;
    };
  }, [showPhotoSection, open, editForm.palette_id, activeSetId]);

  useEffect(() => {
    if (!showPhotoSection || !open || !attachment?.photo_library_id) {
      setExistingLinks([]);
      return;
    }
    let active = true;
    async function loadExistingLinks() {
      try {
        const params = new URLSearchParams();
        params.set("photo_library_id", String(attachment.photo_library_id));
        params.set("_", Date.now().toString());
        const res = await fetch(`${PHOTO_LIBRARY_LINKS_URL}?${params.toString()}`, { credentials: "include" });
        const data = await res.json();
        if (!res.ok || !data?.ok) throw new Error(data?.error || "Failed to load existing links");
        if (!active) return;
        setExistingLinks(Array.isArray(data.items) ? data.items : []);
      } catch (err) {
        if (!active) return;
        setExistingLinks([]);
        setPhotoStatus({ loading: false, error: err?.message || "Failed to load existing links" });
      }
    }
    loadExistingLinks();
    return () => {
      active = false;
    };
  }, [showPhotoSection, open, attachment?.photo_library_id]);

  const paletteOptions = useMemo(
    () =>
      items
        .map((palette) => ({
          id: palette.id,
          label: palette.nickname || palette.palette_hash || `Saved #${palette.id}`,
        }))
        .sort((a, b) => a.label.localeCompare(b.label, undefined, { sensitivity: "base" })),
    [items]
  );

  const currentFullPhoto = useMemo(
    () => (attachPhotoType === "full" && !createNewGroup
      ? (editPhotos.find((photo) => (photo.photo_type || "full") === "full") || null)
      : null),
    [attachPhotoType, createNewGroup, editPhotos]
  );
  const selectedSet = useMemo(
    () => (items.find((row) => String(row.id) === String(editForm.palette_id))?.sets || [])
      .find((set) => String(set.id) === String(activeSetId)) || null,
    [items, editForm.palette_id, activeSetId]
  );
  const attachSubmitLabel = useMemo(() => {
    if (linkOnlyMode && createNewGroup) {
      return "Attach Palette";
    }
    if (attachPhotoType === "full" && currentFullPhoto && fullAttachMode === "replace" && !createNewGroup) {
      return "Replace Full Photo";
    }
    if (attachEnabled && createNewGroup) {
      return "Create Viewer";
    }
    return "Save";
  }, [attachPhotoType, currentFullPhoto, fullAttachMode, createNewGroup, attachEnabled, linkOnlyMode]);

  function handleEditField(name, value) {
    setEditForm((prev) => ({ ...prev, [name]: value }));
  }

  function handleAddMember() {
    setEditMembers((prev) => [...prev, { key: `new-${Date.now()}`, color: null, role: "" }]);
  }

  function handleAddMemberWithColor(color) {
    if (!color?.id) return;
    setEditMembers((prev) => [...prev, { key: `new-${Date.now()}`, color, role: "" }]);
  }

  function handleEditMemberColor(index, color) {
    setEditMembers((prev) => prev.map((row, idx) => (idx === index ? { ...row, color } : row)));
  }

  function handleEditMemberRole(index, role) {
    setEditMembers((prev) => prev.map((row, idx) => (idx === index ? { ...row, role } : row)));
  }

  function handleRemoveMember(index) {
    setEditMembers((prev) => prev.filter((_, idx) => idx !== index));
  }

  function handlePhotoField(photoId, field, value) {
    setEditPhotos((prev) =>
      prev.map((photo) => (photo.id === photoId ? applyPhotoFieldUpdate(photo, field, value) : photo))
    );
  }

  async function handlePhotoPickFromLibrary(item) {
    if (!editForm.palette_id || !item?.image_url) return;
    setPhotoStatus({ loading: true, error: "" });
    try {
      const payload = {
        palette_id: editForm.palette_id,
        photo_library_id: item.photo_library_id || null,
        set_id: activeSetId ? Number(activeSetId) : null,
        create_new_set: !activeSetId && createNewGroup,
        raw_rel_path: item.raw_rel_path || "",
        rel_path: item.image_url,
        photo_type: "full",
        trigger_mode: "any",
      };
      const res = await fetch(PHOTO_LIBRARY_ADD_URL, {
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
      if (photo?.saved_palette_set_id) {
        setActiveSetId(String(photo.saved_palette_set_id));
        setCreateNewGroup(false);
      }
      await refreshExistingLinks();
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
      setPhotoStatus({ loading: false, error: "", success: "Photo attached." });
      setPhotoPickerOpen(false);
      onChanged?.();
    } catch (err) {
      setPhotoStatus({ loading: false, error: err?.message || "Failed to attach photo", success: "" });
    }
  }

  async function handleDeletePhoto(photoId, unlinkOnly = false) {
    if (!photoId) return;
    setPhotoStatus({ loading: true, error: "", success: "" });
    try {
      const res = await fetch(PHOTO_DELETE_URL, {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ photo_id: photoId, unlink_only: unlinkOnly ? 1 : 0 }),
      });
      const json = await res.json().catch(() => ({}));
      if (!res.ok || !json.ok) {
        throw new Error(json.error || `HTTP ${res.status}`);
      }
      await refreshExistingLinks();
      setEditPhotos((prev) => prev.filter((photo) => photo.id !== photoId));
      setPhotoStatus({
        loading: false,
        error: "",
        success: unlinkOnly ? "Photo unlinked." : "Photo removed.",
      });
      onChanged?.();
    } catch (err) {
      setPhotoStatus({ loading: false, error: err?.message || "Failed to delete photo", success: "" });
    }
  }

  async function attachSelectedPhoto(nextPaletteId, options = {}) {
    if (!attachEnabled || !attachment || !nextPaletteId) return null;
    const rel = rawRelPath(attachment.raw_rel_path || attachment.rel_path || attachment.image_url || "");
    if (!rel) return null;
    const effectivePhotoType = options.photoType || attachPhotoType;
    const shouldCreateNewGroup = options.forceCreateNewGroup === true
      ? true
      : createNewGroup;
    const effectiveSetId = options.setId !== undefined
      ? options.setId
      : (activeSetId ? Number(activeSetId) : null);
    const replaceExistingFull = options.forceReplace === true
      ? true
      : (effectivePhotoType === "full" && !shouldCreateNewGroup && fullAttachMode === "replace");
    const payload = {
      palette_id: nextPaletteId,
      photo_library_id: attachment.photo_library_id || null,
      set_id: effectiveSetId,
      create_new_set: shouldCreateNewGroup && !effectiveSetId,
      replace_existing_full: replaceExistingFull,
      raw_rel_path: rel,
      rel_path: rel,
      photo_type: effectivePhotoType,
      trigger_mode: effectivePhotoType === "before" ? "none" : attachTriggerMode,
      trigger_color_id: effectivePhotoType === "before" ? null : attachTriggerColorId,
      caption: effectivePhotoType === "before" ? "Before" : null,
      alt_text: attachment.alt_text || "",
    };
    const res = await fetch(PHOTO_LIBRARY_ADD_URL, {
      method: "POST",
      credentials: "include",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(payload),
    });
    const json = await res.json().catch(() => ({}));
    if (!res.ok || !json.ok) {
      throw new Error(json.error || `HTTP ${res.status}`);
    }
    const mainPhoto = json.photo || null;

    if (
      mainPhoto?.saved_palette_set_id
      && effectivePhotoType === "full"
      && selectedBeforePhoto?.photo_library_id
    ) {
      const beforeRes = await fetch(PHOTO_LIBRARY_ADD_URL, {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          palette_id: nextPaletteId,
          set_id: Number(mainPhoto.saved_palette_set_id),
          photo_library_id: selectedBeforePhoto.photo_library_id,
          raw_rel_path: rawRelPath(selectedBeforePhoto.raw_rel_path || selectedBeforePhoto.image_url || ""),
          rel_path: rawRelPath(selectedBeforePhoto.raw_rel_path || selectedBeforePhoto.image_url || ""),
          photo_type: "before",
          trigger_mode: "none",
          caption: "Before",
          alt_text: selectedBeforePhoto.alt_text || "",
        }),
      });
      const beforeJson = await beforeRes.json().catch(() => ({}));
      if (!beforeRes.ok || !beforeJson.ok) {
        throw new Error(beforeJson.error || `HTTP ${beforeRes.status}`);
      }
    }

    return mainPhoto;
  }

  const selectedPaletteFullPhoto = useMemo(() => {
    const palette = items.find((row) => String(row.id) === String(selectedId));
    const photos = Array.isArray(palette?.photos) ? palette.photos : [];
    return photos.find((photo) => (photo.photo_type || "full") === "full") || null;
  }, [items, selectedId]);
  const existingPaletteFullPhoto = selectedPaletteFullPhoto || currentFullPhoto || null;
  const existingPaletteFullSetId = existingPaletteFullPhoto?.saved_palette_set_id
    ? Number(existingPaletteFullPhoto.saved_palette_set_id)
    : null;

  async function finalizeAttach(nextPaletteId, attachedPhoto) {
    await refreshExistingLinks();
    const nextItems = await refreshItems(nextPaletteId);
    const current = nextItems.find((row) => String(row.id) === String(nextPaletteId)) || null;
    setEditStatus({ loading: false, error: "", success: "Saved." });
    if (onSaved) {
      onSaved({
        paletteId: nextPaletteId,
        palette: current,
        attachedPhoto,
      });
    }
    if (onClose) onClose();
  }

  async function handleQuickReplace() {
    if (!editForm.palette_id || !attachment) return;
    setEditStatus({ loading: true, error: "", success: "" });
    try {
      const attachedPhoto = await attachSelectedPhoto(editForm.palette_id, { forceReplace: true });
      await finalizeAttach(editForm.palette_id, attachedPhoto);
    } catch (err) {
      setEditStatus({ loading: false, error: err?.message || "Failed to replace full photo", success: "" });
    }
  }

  async function handleAttachOnlySubmit(event) {
    event.preventDefault();
    if (!attachment) return;
    const nextPaletteId = editForm.palette_id || (selectedId ? Number(selectedId) : null);
    if (!nextPaletteId) {
      setEditStatus({ loading: false, error: "Pick a palette first.", success: "" });
      return;
    }
    if (existingPaletteFullPhoto) {
      setAttachChoiceOpen(true);
      return;
    }
    setEditStatus({ loading: true, error: "", success: "" });
    try {
      const attachedPhoto = await attachSelectedPhoto(nextPaletteId, { photoType: "full" });
      await finalizeAttach(nextPaletteId, attachedPhoto);
    } catch (err) {
      setEditStatus({ loading: false, error: err?.message || "Failed to attach photo", success: "" });
    }
  }

  async function refreshItems(nextSelectedId = selectedId) {
    const params = new URLSearchParams();
    params.set("limit", "200");
    params.set("with_members", "1");
    params.set("with_photos", "1");
    params.set("_", Date.now().toString());
    const res = await fetch(`${LIST_URL}?${params.toString()}`, { credentials: "include" });
    const data = await res.json();
    if (!res.ok || !data?.ok) {
      throw new Error(data?.error || "Failed to refresh saved palettes");
    }
    const nextItems = Array.isArray(data.items) ? data.items : [];
      setItems(nextItems);
      if (nextSelectedId) {
        setMode("edit");
        setSelectedId(String(nextSelectedId));
        const current = nextItems.find((row) => String(row.id) === String(nextSelectedId));
        const defaultSet = (current?.sets || []).find((set) => Number(set.is_default) === 1) || current?.sets?.[0] || null;
        setActiveSetId(defaultSet?.id ? String(defaultSet.id) : "");
      }
      return nextItems;
    }

  async function handleSave(event) {
    event.preventDefault();
    setEditStatus({ loading: true, error: "", success: "" });
    try {
      const members = collapseMembersByColor(
        editMembers
          .map((row, index) => {
            const colorId = Number(row?.color?.id || row?.color?.color_id || 0);
            if (!colorId) return null;
            const role = row?.role?.trim() || null;
            return {
              color_id: colorId,
              order_index: index,
              role,
              color_name: row?.color?.name || "",
              color_code: row?.color?.code || "",
              color_hex6: (row?.color?.hex || "").replace(/^#/, ""),
              color_brand: row?.color?.brand || "",
              color_hcl_h: row?.color?.hcl_h ?? 0,
              color_hcl_c: row?.color?.hcl_c ?? 0,
              color_hcl_l: row?.color?.hcl_l ?? 0,
              color_chip_num: row?.color?.chip_num ?? "",
              color_cluster_id: row?.color?.cluster_id ?? 0,
            };
          })
          .filter(Boolean)
      ).map((row, index) => ({
        color_id: row.color_id,
        order_index: index,
        role: row.role || null,
      }));

      if (!members.length) {
        throw new Error("Add at least one color before saving.");
      }

      let nextPaletteId = editForm.palette_id;
      if (mode === "create") {
        if (!editForm.brand) {
          throw new Error("Pick a brand before saving.");
        }
        const createPayload = {
          brand: editForm.brand,
          color_ids: members,
          nickname: editForm.nickname,
          display_title: editForm.display_title,
          notes: editForm.notes,
          private_notes: editForm.private_notes,
          terry_fav: editForm.terry_fav ? 1 : 0,
          kicker_id: editForm.kicker_id ? Number(editForm.kicker_id) : null,
          palette_type: editForm.palette_type || "exterior",
        };
        const res = await fetch(SAVE_URL, {
          method: "POST",
          credentials: "include",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify(createPayload),
        });
        const json = await res.json().catch(() => ({}));
        if (!res.ok || !json.ok) {
          throw new Error(json.error || `HTTP ${res.status}`);
        }
        nextPaletteId = json?.data?.palette?.id || json?.data?.palette_id || null;
        if (!nextPaletteId) {
          throw new Error("Saved palette created but id was missing.");
        }
      } else {
        const updatePayload = {
          palette_id: editForm.palette_id,
          nickname: editForm.nickname,
          display_title: editForm.display_title,
          notes: editForm.notes,
          private_notes: editForm.private_notes,
          terry_fav: editForm.terry_fav ? 1 : 0,
          kicker_id: editForm.kicker_id ? Number(editForm.kicker_id) : null,
          palette_type: editForm.palette_type || "exterior",
          members,
        };
        if (showPhotoSection) {
          updatePayload.photos = editPhotos.map((photo) => ({
            id: photo.id,
            photo_type: photo.photo_type || "full",
            trigger_mode: photo.trigger_mode || "any",
            trigger_color_id: photo.trigger_color_id || null,
            alt_text: photo.alt_text || "",
            caption: photo.caption || "",
          }));
        }
        const res = await fetch(UPDATE_URL, {
          method: "POST",
          credentials: "include",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify(updatePayload),
        });
        const json = await res.json().catch(() => ({}));
        if (!res.ok || !json.ok) {
          throw new Error(json.error || `HTTP ${res.status}`);
        }
      }

      const attachedPhoto = await attachSelectedPhoto(nextPaletteId);
      await finalizeAttach(nextPaletteId, attachedPhoto);
    } catch (err) {
      setEditStatus({ loading: false, error: err?.message || "Failed to save palette", success: "" });
    }
  }

  if (!open) return null;

  if (linkOnlyMode) {
    return (
      <div className="asp-modal-backdrop" role="dialog" aria-modal="true">
        <div className="asp-modal asp-modal-attach-only asp-modal-attach-rebuilt">
          <header className="asp-modal-head">
            <h2>Attach Photo</h2>
            <button type="button" className="asp-close" onClick={onClose} aria-label="Close dialog">
              ✕
            </button>
          </header>

          {loadError && <div className="asp-error">{loadError}</div>}
          {loading && <div className="asp-loading">Loading saved palettes…</div>}

          <form className="asp-attach-rebuilt-form" onSubmit={handleAttachOnlySubmit}>
            <div className="asp-attach-rebuilt-main">
              <div className="asp-attach-rebuilt-photo">
                <img src={attachmentPreview} alt="Photo to attach" />
                {existingLinks.length > 0 ? (
                  <div className="asp-member-empty">Already linked to {existingLinks.length} viewer{existingLinks.length === 1 ? "" : "s"}.</div>
                ) : null}
              </div>

              <div className="asp-attach-rebuilt-fields">
                <label>
                  Palette
                  <select
                    value={selectedId}
                    onChange={(e) => {
                      setMode("edit");
                      setSelectedId(e.target.value);
                    }}
                  >
                    <option value="">Pick a palette</option>
                    {paletteOptions.map((palette) => (
                      <option key={palette.id} value={palette.id}>
                        {palette.label}
                      </option>
                    ))}
                  </select>
                </label>

                <div className="asp-attach-rebuilt-box">
                  <div className="asp-attach-rebuilt-box-title">What This Does</div>
                  <div className="asp-attach-rebuilt-note asp-attach-rebuilt-note--stack">
                    This attaches this photo to the palette as a full photo.
                  </div>
                  <div className="asp-attach-rebuilt-note asp-attach-rebuilt-note--stack">
                    Set up before and zoom photos in Palette Viewer Setup after this step.
                  </div>
                  {existingPaletteFullPhoto ? (
                    <div className="asp-attach-rebuilt-note asp-attach-rebuilt-note--stack">
                      This palette already has a full photo. Choose what this photo should be.
                    </div>
                  ) : null}
                </div>

                {existingLinks.length > 0 ? (
                  <div className="asp-attach-links">
                    {existingLinks.map((link) => (
                      <div key={link.id} className="asp-member-row">
                        <div>
                          <strong>{link.palette_label || `Saved #${link.saved_palette_id}`}</strong>
                          <div className="muted">{link.set_label} | {link.photo_type}</div>
                        </div>
                        <button
                          type="button"
                          className="ghost"
                          onClick={() => handleDeletePhoto(link.id, true)}
                          disabled={photoStatus.loading}
                        >
                          Unlink
                        </button>
                      </div>
                    ))}
                  </div>
                ) : null}
              </div>
            </div>

            {photoStatus.success && <div className="asp-success">{photoStatus.success}</div>}
            {photoStatus.error && <div className="asp-error">{photoStatus.error}</div>}
            {editStatus.error && <div className="asp-error">{editStatus.error}</div>}

            <div className="asp-modal-actions">
              <button type="button" className="ghost" onClick={onClose} disabled={editStatus.loading}>
                Close
              </button>
              <button type="submit" disabled={editStatus.loading}>
                {editStatus.loading ? "Saving…" : "Attach Palette"}
              </button>
            </div>
          </form>

          {attachChoiceOpen && existingPaletteFullPhoto ? (
            <div className="asp-confirm-overlay" role="dialog" aria-modal="true">
              <div className="asp-confirm-card">
                <h3>Full Photo Already Exists</h3>
                <p>
                  This palette already has a full photo:
                  {" "}
                  <strong>{existingPaletteFullPhoto.caption || existingPaletteFullPhoto.alt_text || existingPaletteFullPhoto.rel_path || `Photo #${existingPaletteFullPhoto.id}`}</strong>
                </p>
                <div className="asp-attach-tools">
                  <button
                    type="button"
                    className="ghost"
                    onClick={async () => {
                      setAttachChoiceOpen(false);
                      setCreateNewGroup(false);
                      setActiveSetId(existingPaletteFullSetId ? String(existingPaletteFullSetId) : "");
                      const nextPaletteId = editForm.palette_id || (selectedId ? Number(selectedId) : null);
                      if (!nextPaletteId) return;
                      setEditStatus({ loading: true, error: "", success: "" });
                      try {
                        const attachedPhoto = await attachSelectedPhoto(nextPaletteId, {
                          photoType: "before",
                          forceCreateNewGroup: false,
                          setId: existingPaletteFullSetId,
                        });
                        await finalizeAttach(nextPaletteId, attachedPhoto);
                      } catch (err) {
                        setEditStatus({ loading: false, error: err?.message || "Failed to attach photo", success: "" });
                      }
                    }}
                  >
                    Make This A Before
                  </button>
                  <button
                    type="button"
                    className="ghost"
                    onClick={async () => {
                      setAttachChoiceOpen(false);
                      setCreateNewGroup(false);
                      setActiveSetId(existingPaletteFullSetId ? String(existingPaletteFullSetId) : "");
                      const nextPaletteId = editForm.palette_id || (selectedId ? Number(selectedId) : null);
                      if (!nextPaletteId) return;
                      setEditStatus({ loading: true, error: "", success: "" });
                      try {
                        const attachedPhoto = await attachSelectedPhoto(nextPaletteId, {
                          photoType: "zoom",
                          forceCreateNewGroup: false,
                          setId: existingPaletteFullSetId,
                        });
                        await finalizeAttach(nextPaletteId, attachedPhoto);
                      } catch (err) {
                        setEditStatus({ loading: false, error: err?.message || "Failed to attach photo", success: "" });
                      }
                    }}
                  >
                    Make This A Zoom
                  </button>
                  <button
                    type="button"
                    className="ghost"
                    onClick={async () => {
                      setAttachChoiceOpen(false);
                      setCreateNewGroup(true);
                      setActiveSetId("");
                      setFullAttachMode("new");
                      const nextPaletteId = editForm.palette_id || (selectedId ? Number(selectedId) : null);
                      if (!nextPaletteId) return;
                      setEditStatus({ loading: true, error: "", success: "" });
                      try {
                        const attachedPhoto = await attachSelectedPhoto(nextPaletteId, {
                          photoType: "full",
                          forceCreateNewGroup: true,
                          setId: null,
                        });
                        await finalizeAttach(nextPaletteId, attachedPhoto);
                      } catch (err) {
                        setEditStatus({ loading: false, error: err?.message || "Failed to attach photo", success: "" });
                      }
                    }}
                  >
                    Create New Full
                  </button>
                  <button
                    type="button"
                    className="ghost"
                    onClick={async () => {
                      setAttachChoiceOpen(false);
                      setCreateNewGroup(false);
                      setFullAttachMode("replace");
                      setActiveSetId(existingPaletteFullSetId ? String(existingPaletteFullSetId) : "");
                      const nextPaletteId = editForm.palette_id || (selectedId ? Number(selectedId) : null);
                      if (!nextPaletteId) return;
                      setEditStatus({ loading: true, error: "", success: "" });
                      try {
                        const attachedPhoto = await attachSelectedPhoto(nextPaletteId, {
                          photoType: "full",
                          forceReplace: true,
                          setId: existingPaletteFullSetId,
                        });
                        await finalizeAttach(nextPaletteId, attachedPhoto);
                      } catch (err) {
                        setEditStatus({ loading: false, error: err?.message || "Failed to attach photo", success: "" });
                      }
                    }}
                  >
                    Replace Existing Full
                  </button>
                  <button
                    type="button"
                    className="ghost"
                    onClick={() => setAttachChoiceOpen(false)}
                  >
                    Cancel
                  </button>
                </div>
              </div>
            </div>
          ) : null}
        </div>
      </div>
    );
  }

  return (
    <div className="asp-modal-backdrop" role="dialog" aria-modal="true">
      <div className={`asp-modal${linkOnlyMode ? " asp-modal-attach-only" : ""}`}>
        <header className="asp-modal-head">
          <h2>{linkOnlyMode ? "Attach Photo" : (mode === "create" ? "Create Saved Palette" : "Edit Saved Palette")}</h2>
          <button type="button" className="asp-close" onClick={onClose} aria-label="Close dialog">
            ✕
          </button>
        </header>

        {loadError && <div className="asp-error">{loadError}</div>}
        {loading && <div className="asp-loading">Loading saved palettes…</div>}

        <div className={`asp-filter${linkOnlyMode ? " asp-filter-attach-only" : ""}`} style={{ marginBottom: 16 }}>
          <label>
            Existing Palette
            <select
              value={selectedId}
              onChange={(e) => {
                setMode("edit");
                setSelectedId(e.target.value);
              }}
            >
              <option value="">Pick a palette</option>
              {paletteOptions.map((palette) => (
                <option key={palette.id} value={palette.id}>
                  {palette.label}
                </option>
              ))}
            </select>
          </label>
          <div className="asp-filter-buttons">
            <button type="button" onClick={() => setMode("create")}>
              New Palette
            </button>
          </div>
        </div>

        <form className={`asp-modal-form${linkOnlyMode ? " asp-modal-form-attach-only" : ""}`} onSubmit={handleSave}>
          {showPhotoSection && attachmentPreview && (
            <div className="asp-photo-editor asp-attach-panel">
              <div className="asp-member-list-head">
                <h3>Attach Photo</h3>
              </div>

              <div className="asp-attach-form">
                <div className="asp-attach-preview">
                  <img src={attachmentPreview} alt="Photo to attach" />
                  <label className="asp-modal-checkbox">
                    <input
                      type="checkbox"
                      checked={attachEnabled}
                      onChange={(e) => setAttachEnabled(e.target.checked)}
                    />
                    Attach palette?
                  </label>
                  {existingLinks.length > 0 ? (
                    <div className="asp-member-empty">Linked viewers: {existingLinks.length}</div>
                  ) : null}
                </div>

                <div className="asp-attach-fields">
                  {attachEnabled ? (
                    <>
                      <div className="asp-attach-grid">
                        <label>
                          Role
                          <select
                            value={attachPhotoType}
                            onChange={(e) => setAttachPhotoType(e.target.value)}
                            disabled={!attachEnabled}
                          >
                            <option value="full">Full</option>
                            <option value="before">Before</option>
                            <option value="zoom">Zoom</option>
                          </select>
                        </label>

                        {editForm.palette_id ? (
                          <label>
                            Viewer
                            <select
                              value={createNewGroup ? "__new__" : activeSetId}
                              onChange={(e) => {
                                if (e.target.value === "__new__") {
                                  setCreateNewGroup(true);
                                  setActiveSetId("");
                                  setFullAttachMode("replace");
                                  return;
                                }
                                setCreateNewGroup(false);
                                setActiveSetId(e.target.value);
                                setFullAttachMode("replace");
                              }}
                              disabled={!attachEnabled}
                            >
                              <option value="">Primary / Default Viewer</option>
                              {(items.find((row) => String(row.id) === String(editForm.palette_id))?.sets || []).map((set) => (
                                <option key={set.id} value={set.id}>
                                  {formatViewerLabel(set)}
                                </option>
                              ))}
                              <option value="__new__">New Viewer</option>
                            </select>
                          </label>
                        ) : null}
                      </div>

                      {attachPhotoType === "full" && currentFullPhoto ? (
                        <div className="asp-inline-note">
                          <div>
                            <strong>Full photo already exists</strong>
                            {selectedSet ? ` in ${formatViewerLabel(selectedSet)}` : ""}.
                            {" "}
                            {currentFullPhoto.caption || currentFullPhoto.alt_text || currentFullPhoto.rel_path || `Photo #${currentFullPhoto.id}`}
                          </div>
                          <div className="asp-attach-tools">
                            <button
                              type="button"
                              className="ghost"
                              onClick={() => {
                                if (currentFullPhoto?.rel_path) {
                                  window.open(currentFullPhoto.rel_path, "_blank", "noopener,noreferrer");
                                }
                              }}
                            >
                              View Current
                            </button>
                            <button
                              type="button"
                              className={`ghost${fullAttachMode === "replace" && !createNewGroup ? " asp-choice-active" : ""}`}
                              onClick={async () => {
                                setCreateNewGroup(false);
                                setFullAttachMode("replace");
                                await handleQuickReplace();
                              }}
                              disabled={editStatus.loading}
                            >
                              Replace Now
                            </button>
                            <button
                              type="button"
                              className={`ghost${createNewGroup || fullAttachMode === "new" ? " asp-choice-active" : ""}`}
                              onClick={() => {
                                setCreateNewGroup(true);
                                setActiveSetId("");
                                setFullAttachMode("new");
                              }}
                            >
                              New Viewer
                            </button>
                          </div>
                        </div>
                      ) : null}

                      {attachPhotoType === "full" ? (
                        <div className="asp-inline-note">
                          <div>
                            <strong>Before photo</strong>
                            {" "}
                            {selectedBeforePhoto
                              ? `${selectedBeforePhoto.title || "Selected photo"} (#${selectedBeforePhoto.photo_library_id})`
                              : "Optional shared before photo."}
                          </div>
                          <div className="asp-attach-tools">
                            <button type="button" className="ghost" onClick={() => setBeforePickerOpen(true)}>
                              {selectedBeforePhoto ? "Change" : "Pick"}
                            </button>
                            {selectedBeforePhoto ? (
                              <button type="button" className="ghost" onClick={() => setSelectedBeforePhoto(null)}>
                                Clear
                              </button>
                            ) : null}
                          </div>
                        </div>
                      ) : null}

                      {attachPhotoType !== "before" ? (
                        <div className="asp-attach-grid">
                          <label>
                            Gallery Trigger
                            <select
                              value={attachTriggerMode}
                              onChange={(e) => setAttachTriggerMode(e.target.value)}
                            >
                              <option value="any">Any color</option>
                              <option value="color">Specific color</option>
                            </select>
                          </label>
                          {attachTriggerMode === "color" ? (
                            <label>
                              Trigger Color
                              <select
                                value={attachTriggerColorId || ""}
                                onChange={(e) => setAttachTriggerColorId(e.target.value ? Number(e.target.value) : null)}
                              >
                                <option value="">Pick color</option>
                                {editMembers.map((row) => (
                                  <option
                                    key={`attach-trigger-${row.color?.id || row.color?.color_id}`}
                                    value={row.color?.id || row.color?.color_id || ""}
                                  >
                                    {row.color?.name || row.color?.label || row.color?.code || row.color?.id}
                                  </option>
                                ))}
                              </select>
                            </label>
                          ) : null}
                        </div>
                      ) : (
                        <div className="asp-member-empty">Before photos are viewer companions only.</div>
                      )}
                    </>
                  ) : null}
                </div>
              </div>

              {existingLinks.length > 0 ? (
                <div className="asp-attach-links">
                  {existingLinks.map((link) => (
                    <div key={link.id} className="asp-member-row">
                      <div>
                        <strong>{link.palette_label || `Saved #${link.saved_palette_id}`}</strong>
                        <div className="muted">{link.set_label} | {link.photo_type}</div>
                      </div>
                      <button
                        type="button"
                        className="ghost"
                        onClick={() => handleDeletePhoto(link.id)}
                        disabled={photoStatus.loading}
                      >
                        Remove Link
                      </button>
                    </div>
                  ))}
                </div>
              ) : null}
            </div>
          )}

          {showPhotoSection && !linkOnlyMode && (
            <div className="asp-photo-editor">
              <div className="asp-member-list-head">
                <h3>Photos</h3>
                <div className="asp-photo-actions">
                  <button
                    type="button"
                    className="ghost"
                    onClick={() => setPhotoPickerOpen(true)}
                    disabled={photoStatus.loading || !editForm.palette_id}
                  >
                    Library
                  </button>
                  <button type="submit" className="asp-save-top" disabled={editStatus.loading}>
                    {editStatus.loading ? "Saving…" : attachSubmitLabel}
                  </button>
                </div>
              </div>
              {photoStatus.error && <div className="asp-error">{photoStatus.error}</div>}
              {photoStatus.success && <div className="asp-success">{photoStatus.success}</div>}
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
                <div className="asp-member-empty">
                  {editForm.palette_id ? "No photos yet." : "Save the palette first, then attach library photos here."}
                </div>
              )}
              {editForm.palette_id && (
                <div className="asp-photo-actions" style={{ marginTop: 12 }}>
                  <select
                    value={createNewGroup ? "__new__" : activeSetId}
                    onChange={(e) => {
                      if (e.target.value === "__new__") {
                        setCreateNewGroup(true);
                        setActiveSetId("");
                        return;
                      }
                      setCreateNewGroup(false);
                      setActiveSetId(e.target.value);
                    }}
                  >
                    <option value="">Primary / Default Group</option>
                    {(items.find((row) => String(row.id) === String(editForm.palette_id))?.sets || []).map((set) => (
                      <option key={set.id} value={set.id}>
                        {formatViewerLabel(set)}
                      </option>
                    ))}
                    <option value="__new__">Create New Group</option>
                  </select>
                </div>
              )}
            </div>
          )}

          <div className="asp-member-list">
            <div className="asp-member-list-head">
              <h3>Palette Colors</h3>
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
            </div>
            <label className="asp-kicker-field">
              <span>Kicker (optional)</span>
              <KickerDropdown
                value={editForm.kicker_id}
                onChange={(next) => setEditForm((prev) => ({ ...prev, kicker_id: next || "" }))}
              />
            </label>
            {mode === "create" && (
              <label>
                Brand
                <select value={editForm.brand} onChange={(e) => handleEditField("brand", e.target.value)}>
                  {BRAND_CHOICES.map((b) => (
                    <option key={b.code || "all"} value={b.code}>
                      {b.label}
                    </option>
                  ))}
                </select>
              </label>
            )}
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
                  <button type="button" className="ghost" onClick={() => handleRemoveMember(index)}>
                    ✕
                  </button>
                </div>
              ))}
              {!editMembers.length && <div className="asp-member-empty">No colors yet.</div>}
            </div>
          </div>

          <label>
            Nickname (for me)
            <input type="text" value={editForm.nickname} onChange={(e) => handleEditField("nickname", e.target.value)} />
          </label>

          <label>
            Display Title (shown in viewer)
            <input type="text" value={editForm.display_title} onChange={(e) => handleEditField("display_title", e.target.value)} />
          </label>

          <label>
            Public Notes (shown in viewer)
            <textarea rows={3} value={editForm.notes} onChange={(e) => handleEditField("notes", e.target.value)} />
          </label>

          <label>
            Private Notes (for me)
            <textarea
              rows={3}
              value={editForm.private_notes}
              onChange={(e) => handleEditField("private_notes", e.target.value)}
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
            <button type="button" className="ghost" onClick={onClose} disabled={editStatus.loading}>
              Close
            </button>
            <button type="submit" disabled={editStatus.loading}>
              {editStatus.loading ? "Saving…" : mode === "create" ? "Create Palette" : "Save Changes"}
            </button>
          </div>
        </form>

        <PhotoPickerModal
          open={photoPickerOpen}
          title="Pick Palette Photo"
          onClose={() => setPhotoPickerOpen(false)}
          onPick={handlePhotoPickFromLibrary}
        />
        <PhotoPickerModal
          open={beforePickerOpen}
          title="Pick Shared Before Photo"
          onClose={() => setBeforePickerOpen(false)}
          onPick={(item) => {
            setSelectedBeforePhoto(item);
            setBeforePickerOpen(false);
          }}
        />
      </div>
    </div>
  );
}

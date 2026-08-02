import { useEffect, useMemo, useState } from "react";
import { API_FOLDER } from "@helpers/config";
import EditableSwatch from "@components/EditableSwatch";
import KickerDropdown from "@components/KickerDropdown";
import FuzzySearchColorSelect from "@components/FuzzySearchColorSelect";
import PhotoPickerModal from "@components/PhotoPickerModal";
import "../AdminSavedPalettesPage/admin-saved-palettes.css";
import "./admin-palette-photos.css";

const SAVED_LIST_URL = `${API_FOLDER}/v2/admin/saved-palettes.php`;
const PALETTE_VIEWER_TOKEN_URL = `${API_FOLDER}/v2/admin/palette-viewer-token.php`;
const PLAYLISTS_URL = `${API_FOLDER}/v2/admin/playlists/list.php`;
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

const VIEWER_TEMPLATE_CHOICES = [
  { value: "full_palette", label: "Full Palette" },
  { value: "concept", label: "Concept" },
];

const emptySavedForm = {
  palette_id: null,
  nickname: "",
  display_title: "",
  intro: "",
  notes: "",
  cta_label: "",
  playlist_url: "",
  private_notes: "",
  terry_fav: false,
  kicker_id: "",
  viewer_kicker_id: "",
  kicker_text: "",
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

function normalizePalettePhoto(photo, fallbackOrder = 0) {
  return {
    id: photo.id,
    photo_library_id: photo.photo_library_id || null,
    saved_palette_set_id: photo.saved_palette_set_id || null,
    rel_path: photo.rel_path,
    photo_type: photo.photo_type || "full",
    trigger_mode: photo.trigger_mode || "any",
    trigger_color_id: photo.trigger_color_id ?? null,
    caption: photo.caption || "",
    alt_text: photo.alt_text || "",
    order_index: photo.order_index ?? fallbackOrder,
    set_title: photo.set_title || "",
    set_slug: photo.set_slug || "",
  };
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

function playlistShareUrl(playlist) {
  const prospectId = Number(playlist?.prospect_playlist_instance_id || 0);
  if (prospectId > 0) {
    return `/share/playlist.php?id=${prospectId}`;
  }
  const id = Number(playlist?.playlist_id || 0);
  return id > 0 ? `/playlist/share/id=${id}` : "";
}

function playlistLabel(playlist) {
  const id = Number(playlist?.playlist_id || 0);
  const title = String(playlist?.title || playlist?.headline || "").trim();
  const type = String(playlist?.type || "").trim();
  const prospectId = Number(playlist?.prospect_playlist_instance_id || 0);
  const parts = [];
  if (title) parts.push(title);
  if (type) parts.push(type);
  if (id > 0) parts.push(`#${id}`);
  if (prospectId > 0) parts.push(`prospect instance #${prospectId}`);
  return parts.join(" - ") || "Untitled playlist";
}

export default function AdminPalettePhotosPage() {
  const [savedPalettes, setSavedPalettes] = useState([]);
  const [playlists, setPlaylists] = useState([]);
  const [playlistStatus, setPlaylistStatus] = useState({ loading: false, error: "" });
  const [selectedId, setSelectedId] = useState(() => {
    if (typeof window === "undefined") return "";
    const params = new URLSearchParams(window.location.search);
    return params.get("id") || "";
  });
  const [loadingPalette, setLoadingPalette] = useState(false);
  const [loadError, setLoadError] = useState("");

  const [editForm, setEditForm] = useState(emptySavedForm);
  const [editMembers, setEditMembers] = useState([]);
  const [editPhotos, setEditPhotos] = useState([]);
  const [viewerContentRows, setViewerContentRows] = useState([]);
  const [selectedViewerSetId, setSelectedViewerSetId] = useState("");
  const [selectedViewerTemplateKey, setSelectedViewerTemplateKey] = useState("full_palette");
  const [photoPickerOpen, setPhotoPickerOpen] = useState(false);
  const [libraryPhotoType, setLibraryPhotoType] = useState("full");
  const [photoStatus, setPhotoStatus] = useState({ loading: false, error: "" });
  const [editStatus, setEditStatus] = useState({ loading: false, error: "", success: "" });
  const [testStatus, setTestStatus] = useState({ loading: false, error: "" });
  const [activeEditorTab, setActiveEditorTab] = useState("photos");

  const isApplied = false;
  const requestedViewerSetId = useMemo(() => {
    if (typeof window === "undefined") return "";
    const params = new URLSearchParams(window.location.search);
    return params.get("set_id") || "";
  }, []);
  const requestedPhotoLibraryId = useMemo(() => {
    if (typeof window === "undefined") return "";
    const params = new URLSearchParams(window.location.search);
    return params.get("photo_library_id") || "";
  }, []);
  const returnTo = useMemo(() => {
    if (typeof window === "undefined") return "/admin/photo-library";
    const params = new URLSearchParams(window.location.search);
    const value = params.get("return_to") || "";
    if (!value.startsWith("/admin/photo-library")) return "/admin/photo-library";
    return value;
  }, []);

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
    async function loadPlaylists() {
      setPlaylistStatus({ loading: true, error: "" });
      try {
        const params = new URLSearchParams();
        params.set("_", Date.now().toString());
        const res = await fetch(`${PLAYLISTS_URL}?${params.toString()}`, { credentials: "include" });
        const data = await res.json();
        if (!res.ok || !data?.ok) throw new Error(data?.error || "Failed to load playlists");
        if (!active) return;
        setPlaylists(Array.isArray(data.items) ? data.items : []);
        setPlaylistStatus({ loading: false, error: "" });
      } catch (err) {
        if (!active) return;
        setPlaylists([]);
        setPlaylistStatus({
          loading: false,
          error: err?.message || "Failed to load playlists",
        });
      }
    }
    loadPlaylists();
    return () => { active = false; };
  }, []);

  useEffect(() => {
    setSelectedViewerSetId("");
    setSelectedViewerTemplateKey("full_palette");
    setViewerContentRows([]);
    setEditMembers([]);
    setEditPhotos([]);
    setPhotoStatus({ loading: false, error: "" });
    setEditStatus({ loading: false, error: "", success: "" });
    setLoadError("");
    setEditForm(emptySavedForm);
  }, []);

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
      let active = true;
      async function loadSavedPaletteDetails() {
        setLoadingPalette(true);
        setLoadError("");
        try {
          setEditForm({
            palette_id: Number(palette.id) || palette.id,
            nickname: palette.nickname || "",
            display_title: "",
            intro: "",
            notes: "",
            cta_label: "",
            playlist_url: "",
            private_notes: palette.private_notes || "",
            terry_fav: Number(palette.terry_fav) === 1,
            kicker_id: "",
            viewer_kicker_id: "",
            kicker_text: "",
            palette_type: palette.palette_type || "exterior",
          });
          setViewerContentRows(Array.isArray(palette.viewer_content) ? palette.viewer_content : []);
          const members = (palette.members || []).map((member, index) => ({
            key: member.id ?? `${member.color_id}-${index}`,
            color: memberToSwatch(member),
            role: member.role || "",
          }));
          if (!active) return;
          setEditMembers(members);

          const sets = Array.isArray(palette.sets) ? palette.sets : [];
          const requestedSetId = String(requestedViewerSetId || "");
          const requestedLibraryId = String(requestedPhotoLibraryId || "");
          let rawPhotos = Array.isArray(palette.photos) ? [...palette.photos] : [];

          if (sets.length > 0) {
            const responses = await Promise.all(
              sets.map(async (set) => {
                const params = new URLSearchParams();
                params.set("id", String(palette.id));
                params.set("set_id", String(set.id));
                params.set("with_photos", "1");
                params.set("limit", "200");
                params.set("_", Date.now().toString());
                const res = await fetch(`${SAVED_LIST_URL}?${params.toString()}`, { credentials: "include" });
                const data = await res.json().catch(() => ({}));
                if (!res.ok || !data?.ok) {
                  throw new Error(data?.error || "Failed to load viewer photos");
                }
                const paletteRow = Array.isArray(data.items)
                  ? data.items.find((item) => String(item.id) === String(palette.id))
                  : null;
                const photos = Array.isArray(paletteRow?.photos) ? paletteRow.photos : [];
                return photos.map((photo) => ({
                  ...photo,
                  saved_palette_set_id: photo?.saved_palette_set_id || set.id,
                  set_title: photo?.set_title || set.title || "",
                  set_slug: photo?.set_slug || set.slug || "",
                }));
              })
            );
            rawPhotos = responses.flat();
          }

          const seen = new Set();
          const photos = rawPhotos
            .filter((photo) => {
              const key = String(photo.id || "");
              if (!key || seen.has(key)) return false;
              seen.add(key);
              return true;
            })
            .map((photo, index) => normalizePalettePhoto(photo, index));

          if (!active) return;
          setEditPhotos(photos);
          const matchingPhoto = requestedLibraryId
            ? photos.find((photo) => String(photo.photo_library_id || "") === requestedLibraryId)
            : null;
          const requestedExists = requestedSetId
            && photos.some((photo) => String(photo.saved_palette_set_id || "") === requestedSetId);
          const firstFull = photos.find((photo) => (photo.photo_type || "full") === "full");
          setSelectedViewerSetId(
            matchingPhoto?.saved_palette_set_id
              ? String(matchingPhoto.saved_palette_set_id)
              : requestedExists
              ? requestedSetId
              : (firstFull?.saved_palette_set_id ? String(firstFull.saved_palette_set_id) : "")
          );
        } catch (err) {
          if (!active) return;
          setLoadError(err?.message || "Failed to load saved palette");
          setEditMembers([]);
          setEditPhotos([]);
          setViewerContentRows([]);
        } finally {
          if (active) setLoadingPalette(false);
        }
      }
      loadSavedPaletteDetails();
      return () => { active = false; };
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
          saved_palette_set_id: photo.saved_palette_set_id || null,
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
        const requestedExists = requestedViewerSetId
          && photos.some((photo) => String(photo.saved_palette_set_id || "") === String(requestedViewerSetId));
        const firstFull = photos.find((photo) => (photo.photo_type || "full") === "full");
        setSelectedViewerSetId(
          requestedExists
            ? String(requestedViewerSetId)
            : (firstFull?.saved_palette_set_id ? String(firstFull.saved_palette_set_id) : "")
        );
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
  }, [selectedId, savedPalettes, isApplied, requestedViewerSetId, requestedPhotoLibraryId]);

  const paletteOptions = useMemo(() =>
    savedPalettes
      .map((palette) => ({
        id: palette.id,
        label: palette.nickname || palette.palette_hash || `Saved #${palette.id}`,
      }))
      .sort((a, b) => a.label.localeCompare(b.label, undefined, { sensitivity: "base" })),
  [savedPalettes]);
  const selectedPalette = useMemo(
    () => savedPalettes.find((row) => String(row.id) === String(selectedId)) || null,
    [savedPalettes, selectedId]
  );
  const viewerPreviewUrl = useMemo(() => {
    const hash = selectedPalette?.palette_hash || "";
    if (!hash) return "";
    const params = new URLSearchParams();
    if (Number(selectedViewerSetId || 0) > 0) {
      params.set("set_id", String(Number(selectedViewerSetId)));
    }
    const qs = params.toString();
    return `/palette/${encodeURIComponent(hash)}/share${qs ? `?${qs}` : ""}`;
  }, [selectedPalette?.palette_hash, selectedViewerSetId]);
  const paletteSetupReturnUrl = useMemo(() => {
    const paletteId = Number(selectedPalette?.id || selectedId || 0);
    const params = new URLSearchParams();
    params.set("type", "saved");
    if (paletteId > 0) {
      params.set("id", String(paletteId));
    }
    if (Number(selectedViewerSetId || 0) > 0) {
      params.set("set_id", String(Number(selectedViewerSetId)));
    }
    if (Number(requestedPhotoLibraryId || 0) > 0) {
      params.set("photo_library_id", String(Number(requestedPhotoLibraryId)));
    }
    return `/admin/palette-photos?${params.toString()}`;
  }, [selectedPalette?.id, selectedId, selectedViewerSetId, requestedPhotoLibraryId]);
  const viewerTestUrl = useMemo(() => {
    const hash = selectedPalette?.palette_hash || "";
    if (!hash || typeof window === "undefined") return "";
    const params = new URLSearchParams();
    if (Number(selectedViewerSetId || 0) > 0) {
      params.set("set_id", String(Number(selectedViewerSetId)));
    }
    params.set("return_to", paletteSetupReturnUrl);
    return `/palette/${encodeURIComponent(hash)}/share?${params.toString()}`;
  }, [selectedPalette?.palette_hash, selectedViewerSetId, paletteSetupReturnUrl]);

  const buildSavedPaletteUpdatePayload = (templateKey = selectedViewerTemplateKey) => {
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
      palette_id: editForm.palette_id,
      nickname: editForm.nickname,
      private_notes: editForm.private_notes,
      terry_fav: editForm.terry_fav ? 1 : 0,
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

    if (selectedViewerSetId) {
      payload.viewer_content = {
        saved_palette_set_id: Number(selectedViewerSetId),
        template_key: templateKey,
        kicker_text: editForm.kicker_text,
        title: editForm.display_title,
        intro: editForm.intro,
        notes: editForm.notes,
        cta_label: editForm.cta_label,
        playlist_url: editForm.playlist_url,
        is_active: 1,
      };
    }

    return payload;
  };

  const handleTestConcept = async () => {
    if (!selectedPalette?.id || !selectedViewerSetId || typeof window === "undefined") return;
    setTestStatus({ loading: true, error: "" });
    try {
      if (selectedViewerTemplateKey === "concept") {
        const saveRes = await fetch(SAVED_UPDATE_URL, {
          method: "POST",
          credentials: "include",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify(buildSavedPaletteUpdatePayload("concept")),
        });
        const saveJson = await saveRes.json().catch(() => ({}));
        if (!saveRes.ok || !saveJson.ok) {
          throw new Error(saveJson.error || `HTTP ${saveRes.status}`);
        }
        if (Array.isArray(saveJson.data?.viewer_content)) {
          setViewerContentRows(saveJson.data.viewer_content);
        }
      }

      const res = await fetch(PALETTE_VIEWER_TOKEN_URL, {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          palette_id: Number(selectedPalette.id),
          set_id: Number(selectedViewerSetId),
          template_key: "concept",
        }),
      });
      const json = await res.json().catch(() => ({}));
      if (!res.ok || !json?.ok || !json?.url) {
        throw new Error(json?.error || `HTTP ${res.status}`);
      }
      const url = new URL(json.url, window.location.origin);
      url.searchParams.set("return_to", paletteSetupReturnUrl);
      window.location.href = url.pathname + url.search;
    } catch (err) {
      setTestStatus({ loading: false, error: err?.message || "Failed to create concept viewer link" });
    }
  };

  const handleEditField = (name, value) => {
    setEditForm((prev) => ({ ...prev, [name]: value }));
  };

  const handleEditorTab = (tab) => {
    setActiveEditorTab(tab);
    if (tab === "full") {
      setSelectedViewerTemplateKey("full_palette");
    } else if (tab === "concept") {
      setSelectedViewerTemplateKey("concept");
    }
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

  const handlePhotoUpload = async (files, replacePhotoId = null) => {
    if (!editForm.palette_id || !files?.length) return;
    setPhotoStatus({ loading: true, error: "" });
    const uploadUrl = isApplied ? APPLIED_PHOTO_UPLOAD_URL : SAVED_PHOTO_UPLOAD_URL;
    try {
      const formData = new FormData();
      const replacePhoto = replacePhotoId
        ? editPhotos.find((photo) => Number(photo.id) === Number(replacePhotoId)) || null
        : null;
      formData.append("palette_id", String(editForm.palette_id));
      if (!isApplied && replacePhotoId) {
        formData.append("replace_photo_id", String(replacePhotoId));
        if (replacePhoto?.saved_palette_set_id) {
          formData.append("set_id", String(replacePhoto.saved_palette_set_id));
        }
        formData.append("photo_type", replacePhoto?.photo_type || "full");
      } else if (!isApplied) {
        if (selectedViewerSetId) {
          formData.append("set_id", String(selectedViewerSetId));
        }
        formData.append("photo_type", libraryPhotoType || "full");
      }
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
      setEditPhotos((prev) => {
        const normalized = added.map((photo, index) =>
          normalizePalettePhoto(photo, prev.length + index)
        );
        if (isApplied || !replacePhotoId) {
          const newFull = normalized.find((photo) => (photo.photo_type || "full") === "full");
          if (newFull?.saved_palette_set_id) {
            setSelectedViewerSetId(String(newFull.saved_palette_set_id));
          }
          return [...prev, ...normalized];
        }
        const first = normalized[0];
        if (first?.saved_palette_set_id) {
          setSelectedViewerSetId(String(first.saved_palette_set_id));
        }
        return prev.map((photo) => (photo.id === replacePhotoId ? { ...photo, ...first } : photo));
      });
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
      const photoType = libraryPhotoType || "full";
      const payload = {
        palette_id: editForm.palette_id,
        set_id: selectedViewerSetId ? Number(selectedViewerSetId) : null,
        photo_library_id: item.photo_library_id || null,
        raw_rel_path: item.raw_rel_path || "",
        rel_path: item.image_url,
        photo_type: photoType,
        trigger_mode: photoType === "before" ? "none" : "any",
        caption: photoType === "before" ? "Before" : null,
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
      const normalized = normalizePalettePhoto(photo, editPhotos.length);
      setEditPhotos((prev) => [
        ...prev,
        normalized,
      ]);
      if (normalized.saved_palette_set_id) {
        setSelectedViewerSetId(String(normalized.saved_palette_set_id));
      }
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
          palette_id: editForm.palette_id,
          nickname: editForm.nickname,
          private_notes: editForm.private_notes,
          terry_fav: editForm.terry_fav ? 1 : 0,
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
        if (activeViewer?.setId) {
          payload.viewer_content = {
            saved_palette_set_id: Number(activeViewer.setId),
            template_key: selectedViewerTemplateKey,
            kicker_text: editForm.kicker_text,
            title: editForm.display_title,
            intro: editForm.intro,
            notes: editForm.notes,
            cta_label: editForm.cta_label,
            playlist_url: editForm.playlist_url,
            is_active: 1,
          };
        }
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
        if (Array.isArray(json.data?.viewer_content)) {
          setViewerContentRows(json.data.viewer_content);
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
  const viewerSets = useMemo(() => {
    if (isApplied) return [];
    const grouped = new Map();
    editPhotos.forEach((photo, index) => {
      const setId = String(photo.saved_palette_set_id || "");
      if (!setId) return;
      if (!grouped.has(setId)) {
        grouped.set(setId, []);
      }
      grouped.get(setId).push({ ...photo, _index: index });
    });
    return Array.from(grouped.entries()).map(([setId, photos], index) => {
      const fullPhoto = photos.find((photo) => (photo.photo_type || "full") === "full") || null;
      const coverPhoto = fullPhoto || photos[0] || null;
      return {
        key: String(setId || coverPhoto?.id || index),
        setId: setId || null,
        fullPhoto,
        coverPhoto,
        title:
          photos[0]?.set_title ||
          fullPhoto?.caption ||
          fullPhoto?.alt_text ||
          coverPhoto?.caption ||
          coverPhoto?.alt_text ||
          `Viewer ${index + 1}`,
      };
    });
  }, [editPhotos, isApplied]);

  const activeViewer = useMemo(() => {
    if (isApplied) return null;
    const target = viewerSets.find((set) => String(set.setId || set.coverPhoto?.id) === String(selectedViewerSetId));
    return target || viewerSets[0] || null;
  }, [viewerSets, selectedViewerSetId, isApplied]);
  const activeViewerContent = useMemo(() => {
    if (isApplied || !activeViewer?.setId) return null;
    return viewerContentRows.find((row) =>
      String(row.saved_palette_set_id || "") === String(activeViewer.setId)
      && String(row.template_key || "full_palette") === selectedViewerTemplateKey
    ) || null;
  }, [activeViewer, viewerContentRows, selectedViewerTemplateKey, isApplied]);

  useEffect(() => {
    if (isApplied || !activeViewer?.setId) return;
    const fullFallback = viewerContentRows.find((row) =>
      String(row.saved_palette_set_id || "") === String(activeViewer.setId)
      && String(row.template_key || "") === "full_palette"
    ) || null;
    const content = activeViewerContent || (selectedViewerTemplateKey === "full_palette" ? fullFallback : null);
    setEditForm((prev) => ({
      ...prev,
      display_title: content?.title || "",
      intro: content?.intro || "",
      notes: content?.notes || "",
      cta_label: content?.cta_label || "",
      playlist_url: content?.playlist_url || "",
      viewer_kicker_id: "",
      kicker_text: content?.kicker_text || "",
    }));
  }, [activeViewer?.setId, selectedViewerTemplateKey, activeViewerContent, viewerContentRows, isApplied]);

  const selectedViewerPhotos = useMemo(() => {
    if (isApplied) return editPhotos;
    const activeSetId = activeViewer?.setId ? String(activeViewer.setId) : "";
    if (!activeSetId) return [];
    return editPhotos.filter((photo) => String(photo.saved_palette_set_id || "") === activeSetId);
  }, [editPhotos, activeViewer, isApplied]);
  const conceptLabels = selectedViewerTemplateKey === "concept";
  const viewerCopyLabels = {
    kicker: conceptLabels ? "Project" : "Kicker (optional)",
    title: conceptLabels ? "Concept title" : "Title",
    intro: conceptLabels ? "Goal/Problem" : "Intro",
    notes: conceptLabels ? "Design notes" : "Notes",
  };

  return (
    <section className="app-palette-photos">
      <header className="app-palette-photos__head">
        <div className="app-palette-photos__titlebar">
          <div>
            <h1>Palette Viewer Setup</h1>
          </div>
          <button
            type="button"
            className="app-palette-photos__back"
            onClick={() => {
              if (typeof window !== "undefined") {
                window.location.href = returnTo;
              }
            }}
          >
            ← Back To Library
          </button>
        </div>
        <div className="app-palette-photos__selectors">
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
          <button
            type="button"
            className="app-palette-photos__back"
            onClick={() => {
              if (viewerTestUrl && typeof window !== "undefined") {
                window.location.href = viewerTestUrl;
              }
            }}
            disabled={!viewerTestUrl}
          >
            Full Viewer
          </button>
          <button
            type="button"
            className="app-palette-photos__back"
            onClick={handleTestConcept}
            disabled={!selectedPalette?.id || !selectedViewerSetId || testStatus.loading}
          >
            {testStatus.loading ? "Opening…" : "Concept Viewer"}
          </button>
        </div>
        {testStatus.error && <div className="asp-error">{testStatus.error}</div>}
      </header>

      {loadError && <div className="asp-error">{loadError}</div>}
      {loadingPalette && <div className="asp-member-empty">Loading palette…</div>}

      {selectedId && !loadingPalette && (
        <div className="app-palette-photos__panel asp-modal">
          <header className="asp-modal-head">
            <h2>Edit Viewer Setup</h2>
          </header>

          <form className="asp-modal-form" onSubmit={handleSave}>
            <div className="app-palette-photos__tabs" role="tablist" aria-label="Palette setup sections">
              {[
                ["photos", "Photos"],
                ["colors", "Colors"],
                ["full", "Full"],
                ["concept", "Concept"],
              ].map(([tab, label]) => (
                <button
                  key={tab}
                  type="button"
                  role="tab"
                  aria-selected={activeEditorTab === tab}
                  className={`app-palette-photos__tab${activeEditorTab === tab ? " is-active" : ""}`}
                  onClick={() => handleEditorTab(tab)}
                >
                  {label}
                </button>
              ))}
            </div>

            <div className="app-palette-photos__workspace">
              <div className="app-palette-photos__controls">
                {activeEditorTab === "photos" && (
                  <>
                    {!isApplied && viewerSets.length > 0 && (
                      <div className="app-palette-photos__viewer-setup">
                        <div className="app-palette-photos__viewer-strip">
                          {viewerSets.map((viewer, index) => {
                            const key = String(viewer.setId || viewer.coverPhoto?.id || index);
                            const active = String(activeViewer?.setId || activeViewer?.coverPhoto?.id || "") === key;
                            return (
                              <button
                                key={key}
                                type="button"
                                className={`app-palette-photos__viewer-thumb${active ? " is-active" : ""}`}
                                onClick={() => setSelectedViewerSetId(key)}
                              >
                                {viewer.coverPhoto ? (
                                  <img src={viewer.coverPhoto.rel_path} alt={viewer.title} />
                                ) : (
                                  <div className="app-palette-photos__viewer-thumb-empty">No photo</div>
                                )}
                                <span>{viewer.title}</span>
                                {viewer.coverPhoto?.photo_library_id ? (
                                  <small>Photo #{viewer.coverPhoto.photo_library_id}</small>
                                ) : null}
                              </button>
                            );
                          })}
                        </div>
                      </div>
                    )}

                    <div className="asp-photo-editor">
                      <div className="asp-member-list-head">
                        <h3>Viewer Photos</h3>
                        <div className="asp-photo-actions">
                          <select
                            value={libraryPhotoType}
                            onChange={(e) => setLibraryPhotoType(e.target.value)}
                            disabled={photoStatus.loading}
                          >
                            <option value="full">Full</option>
                            <option value="before">Before</option>
                            <option value="zoom">Zoom</option>
                          </select>
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
                            Add From Library
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
                      {selectedViewerPhotos.length > 0 ? (
                        <div className="asp-photo-grid">
                          {selectedViewerPhotos.map((photo) => (
                            <div key={photo.id} className="asp-photo-card">
                              <div className="app-palette-photos__photo-meta">
                                Photo #{photo.photo_library_id || photo.id}
                              </div>
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
                              {!isApplied && (
                                <label className="asp-upload-btn ghost">
                                  Replace
                                  <input
                                    type="file"
                                    accept="image/*"
                                    onChange={(e) => {
                                      handlePhotoUpload(e.target.files, photo.id);
                                      e.target.value = "";
                                    }}
                                    disabled={photoStatus.loading}
                                  />
                                </label>
                              )}
                            </div>
                          ))}
                        </div>
                      ) : (
                        <div className="asp-member-empty">
                          {isApplied ? "No photos yet." : "No photos in this viewer yet."}
                        </div>
                      )}
                    </div>
                  </>
                )}

                {activeEditorTab === "colors" && (
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
                      <div className="app-palette-photos__hint">
                        Palette colors and roles are shared by every viewer template.
                      </div>
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
                )}

                {!isApplied && (activeEditorTab === "full" || activeEditorTab === "concept") && (
                  <>
                    <div className="app-palette-photos__viewer-copy">
                      <div className="app-palette-photos__section-head">
                        <h3>{activeEditorTab === "concept" ? "Concept Copy" : "Full Viewer Copy"}</h3>
                      </div>
                      <label className="asp-kicker-field">
                        {viewerCopyLabels.kicker}
                        <div className="app-palette-photos__kicker-copy">
                          <KickerDropdown
                            value={editForm.viewer_kicker_id}
                            blankLabel="Copy saved kicker..."
                            onChange={(next, kicker) => {
                              setEditForm((prev) => ({
                                ...prev,
                                viewer_kicker_id: next || "",
                                kicker_text: kicker?.display_text || prev.kicker_text,
                              }));
                            }}
                          />
                        </div>
                        <input
                          type="text"
                          value={editForm.kicker_text}
                          onChange={(e) => handleEditField("kicker_text", e.target.value)}
                          placeholder="Custom viewer kicker"
                        />
                      </label>
                      <label>
                        {viewerCopyLabels.title}
                        <input
                          type="text"
                          value={editForm.display_title}
                          onChange={(e) => handleEditField("display_title", e.target.value)}
                        />
                      </label>
                      <label>
                        {viewerCopyLabels.intro}
                        <textarea
                          rows={3}
                          value={editForm.intro}
                          onChange={(e) => handleEditField("intro", e.target.value)}
                        />
                      </label>
                      <label>
                        {viewerCopyLabels.notes}
                        <textarea
                          rows={3}
                          value={editForm.notes}
                          onChange={(e) => handleEditField("notes", e.target.value)}
                        />
                      </label>
                      <label>
                        CTA Label
                        <input
                          type="text"
                          value={editForm.cta_label}
                          onChange={(e) => handleEditField("cta_label", e.target.value)}
                          placeholder="See transformation"
                        />
                      </label>
                      <label>
                        Concept playlist
                        <select
                          value={editForm.playlist_url}
                          onChange={(e) => handleEditField("playlist_url", e.target.value)}
                          disabled={playlistStatus.loading}
                        >
                          <option value="">
                            {playlistStatus.loading ? "Loading playlists..." : "No concept playlist"}
                          </option>
                          {playlists
                            .map((playlist) => ({
                              playlist,
                              url: playlistShareUrl(playlist),
                              label: playlistLabel(playlist),
                            }))
                            .filter((option) => option.url)
                            .sort((a, b) => a.label.localeCompare(b.label, undefined, { sensitivity: "base" }))
                            .map((option) => (
                              <option key={`${option.url}-${option.playlist.playlist_id}`} value={option.url}>
                                {option.label}
                              </option>
                            ))}
                        </select>
                        {editForm.playlist_url && (
                          <div className="app-palette-photos__hint">{editForm.playlist_url}</div>
                        )}
                        {playlistStatus.error && (
                          <div className="asp-error">{playlistStatus.error}</div>
                        )}
                      </label>
                    </div>
                  </>
                )}

                {!isApplied && activeEditorTab === "colors" && (
                  <>
                    <label>
                      Nickname (for me)
                      <input
                        type="text"
                        value={editForm.nickname}
                        onChange={(e) => handleEditField("nickname", e.target.value)}
                      />
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
                  </>
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

                {isApplied && (
                  <label>
                    Public Notes (shown in viewer)
                    <textarea
                      rows={3}
                      value={editForm.notes}
                      onChange={(e) => handleEditField("notes", e.target.value)}
                    />
                  </label>
                )}

                {editStatus.error && <div className="asp-error">{editStatus.error}</div>}
                {editStatus.success && <div className="asp-success">{editStatus.success}</div>}

                <div className="asp-modal-actions">
                  <button
                    type="button"
                    className="ghost"
                    onClick={() => {
                      if (typeof window !== "undefined") {
                        window.location.href = returnTo;
                      }
                    }}
                  >
                    ← Back To Library
                  </button>
                  <button type="submit" disabled={editStatus.loading}>
                    {editStatus.loading ? "Saving…" : "Save Changes"}
                  </button>
                </div>
              </div>

              {!isApplied && viewerPreviewUrl && (
                <aside className="app-palette-photos__preview-column">
                  <div className="app-palette-photos__viewer-preview">
                    <div className="app-palette-photos__viewer-preview-head">
                      <strong>Live Viewer</strong>
                      <span>Quick check only</span>
                    </div>
                    <iframe
                      key={viewerPreviewUrl}
                      className="app-palette-photos__viewer-frame"
                      src={viewerPreviewUrl}
                      title="Palette viewer preview"
                    />
                  </div>
                </aside>
              )}
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

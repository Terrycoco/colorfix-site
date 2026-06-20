import { useEffect, useMemo, useState } from "react";
import { API_FOLDER } from "@helpers/config";
import {
  buildImageUrl,
  getImageRefreshEnabled,
  setImageRefreshEnabled,
} from "@helpers/assetImage";
import ClientPickerModal from "@components/ClientPickerModal";
import ModalDialog from "@components/ModalDialog";
import SavedPaletteEditorModal from "@components/SavedPaletteEditorModal";
import "./admin-photo-library.css";

const LIST_URL = `${API_FOLDER}/v2/admin/photo-library/list.php`;
const UPDATE_URL = `${API_FOLDER}/v2/admin/photo-library/update.php`;
const DELETE_URL = `${API_FOLDER}/v2/admin/photo-library/delete.php`;
const USAGE_URL = `${API_FOLDER}/v2/admin/photo-library/usage.php`;
const UPLOAD_URL = `${API_FOLDER}/v2/admin/photo-library/upload.php`;
const REPLACE_URL = `${API_FOLDER}/v2/admin/photo-library/replace.php`;
const SAVED_UPLOAD_URL = `${API_FOLDER}/v2/admin/saved-palette-photos/upload.php`;
const SAVED_LIST_URL = `${API_FOLDER}/v2/admin/saved-palettes.php`;
const CLIENTS_LIST_URL = `${API_FOLDER}/v2/admin/clients/list.php`;
const SERIES_LIST_URL = `${API_FOLDER}/v2/admin/photo-library/series.php`;
const GROUPS_LIST_URL = `${API_FOLDER}/v2/admin/photo-groups/list.php`;
const GROUPS_CREATE_URL = `${API_FOLDER}/v2/admin/photo-groups/create.php`;
const GROUPS_DELETE_URL = `${API_FOLDER}/v2/admin/photo-groups/delete.php`;
const GROUPS_ITEMS_URL = `${API_FOLDER}/v2/admin/photo-groups/items.php`;
const GROUPS_ITEMS_ALL_URL = `${API_FOLDER}/v2/admin/photo-groups/items-all.php`;
const GROUPS_ADD_URL = `${API_FOLDER}/v2/admin/photo-groups/add-item.php`;
const GROUPS_REMOVE_URL = `${API_FOLDER}/v2/admin/photo-groups/remove-item.php`;

const SOURCE_OPTIONS = [
  { value: "saved_palette", label: "Saved Palette" },
  { value: "progression", label: "Progression" },
  { value: "client", label: "Client" },
  { value: "article", label: "Article" },
  { value: "pin", label: "Pin" },
];

const defaultUpload = {
  source_type: "saved_palette",
  palette_id: "",
  set_id: "",
  photo_type: "full",
  series: "",
  title_prefix: "",
  client_name: "",
  client_email: "",
  client_id: "",
  photo_permission_status: "",
  tags: "",
  alt_text: "",
  show_in_gallery: false,
  has_palette: false,
};

const defaultFilters = {
  q: "",
  source_type: "",
  palette_id: "",
  sort: "newest",
  include_inactive: false,
  missing_tags: false,
};

const buildClientGroupId = (clientId) => `client:${clientId}`;

function isClientGroupId(value) {
  return String(value || "").startsWith("client:");
}

function parseClientGroupId(value) {
  const raw = String(value || "");
  if (!raw.startsWith("client:")) return 0;
  const id = Number(raw.slice("client:".length));
  return Number.isFinite(id) ? id : 0;
}

function shouldUseExpandedPhotoSearch(filters, photoLibraryIdsFilter) {
  return Boolean(
    String(filters.q || "").trim()
      || String(photoLibraryIdsFilter || "").trim()
      || String(filters.source_type || "").trim()
      || String(filters.palette_id || "").trim()
      || filters.include_inactive
      || filters.missing_tags
  );
}

async function parseJsonResponse(res, label) {
  const text = await res.text();
  try {
    return JSON.parse(text);
  } catch {
    const preview = text.replace(/\s+/g, " ").slice(0, 160);
    throw new Error(`${label} returned non-JSON (${res.status} ${res.statusText}): ${preview}`);
  }
}

function openSavedPaletteViewerSetup(paletteId, setId = null, photoLibraryId = null) {
  const id = Number(paletteId || 0);
  if (!id) return;
  if (typeof window !== "undefined") {
    const params = new URLSearchParams();
    params.set("type", "saved");
    params.set("id", String(id));
    if (Number(setId || 0) > 0) {
      params.set("set_id", String(setId));
    }
    if (Number(photoLibraryId || 0) > 0) {
      params.set("photo_library_id", String(photoLibraryId));
    }
    params.set("return_to", window.location.pathname + window.location.search);
    window.location.href = `/admin/palette-photos?${params.toString()}`;
  }
}

function formatDeleteUsageMessage(item, usages = []) {
  const header = [
    `Can't delete Photo Library #${item?.photo_library_id || "?"}.`,
    "",
    "This asset is still being used here:",
  ];

  const lines = usages.map((usage) => {
    const label = usage?.label || "Unknown usage";
    const detail = usage?.detail ? ` (${usage.detail})` : "";
    const path = usage?.admin_path ? ` -> ${usage.admin_path}` : "";
    return `- ${label}${detail}${path}`;
  });

  return [...header, ...lines, "", "Remove those references first, then delete it from Photo Library."].join("\n");
}

function formatAiAltStatus(item) {
  const status = item?.ai_alt_status || "";
  if (!status && item?.ai_alt_generated_at) return "AI alt generated";
  if (!status) return "";
  if (status === "complete") return item?.ai_alt_generated_at ? `AI alt generated ${item.ai_alt_generated_at}` : "AI alt generated";
  if (status === "processing") return "AI alt processing now";
  if (status === "retry") return item?.ai_alt_next_attempt_at ? `AI alt retry ${item.ai_alt_next_attempt_at}` : "AI alt queued for retry";
  if (status === "pending") return "AI alt queued";
  if (status === "failed") return item?.ai_alt_error ? `AI alt failed: ${item.ai_alt_error}` : "AI alt failed";
  return `AI alt ${status}`;
}

function displayClientListName(client) {
  const firstName = String(client?.first_name || "").trim();
  const lastName = String(client?.last_name || "").trim();
  if (lastName && firstName) return `${lastName}, ${firstName}`;
  if (lastName) return lastName;
  if (firstName) return firstName;
  return String(client?.name || "").trim();
}

function hasActiveLibrarySearch(filters, photoLibraryIdsFilter) {
  return Boolean(
    String(filters?.q || "").trim()
      || String(photoLibraryIdsFilter || "").trim()
      || String(filters?.source_type || "").trim()
      || String(filters?.palette_id || "").trim()
      || filters?.include_inactive
      || filters?.missing_tags
  );
}

export default function AdminPhotoLibraryPage() {
  const initialUrlState = (() => {
    if (typeof window === "undefined") {
      return {
        filters: defaultFilters,
        searchInput: "",
        photoLibraryIds: "",
      };
    }
    const params = new URLSearchParams(window.location.search);
    const q = params.get("q") || "";
    const sourceType = params.get("source_type") || "";
    const paletteId = params.get("palette_id") || "";
    const sort = params.get("sort") || defaultFilters.sort;
    const includeInactive = params.get("include_inactive") === "1";
    const missingTags = params.get("missing_tags") === "1";
    const photoLibraryIds = params.get("photo_library_ids") || "";
    return {
      filters: {
        ...defaultFilters,
        q,
        source_type: sourceType,
        palette_id: paletteId,
        sort,
        include_inactive: includeInactive,
        missing_tags: missingTags,
      },
      searchInput: q,
      photoLibraryIds,
    };
  })();

  const [uploadForm, setUploadForm] = useState(defaultUpload);
  const [uploading, setUploading] = useState(false);
  const [uploadStatus, setUploadStatus] = useState({ error: "", success: "" });
  const [replaceStatus, setReplaceStatus] = useState("");
  const [files, setFiles] = useState([]);

  const [filters, setFilters] = useState(initialUrlState.filters);
  const [searchInput, setSearchInput] = useState(initialUrlState.searchInput);
  const [photoLibraryIdsFilter, setPhotoLibraryIdsFilter] = useState(initialUrlState.photoLibraryIds);
  const [items, setItems] = useState([]);
  const [dirtyIds, setDirtyIds] = useState(() => new Set());
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState("");
  const [refreshKey, setRefreshKey] = useState(0);
  const [previewUrl, setPreviewUrl] = useState("");
  const [usageModal, setUsageModal] = useState(null);
  const [replaceFiles, setReplaceFiles] = useState({});
  const [expandedPathIds, setExpandedPathIds] = useState(() => new Set());
  const [thumbNonce, setThumbNonce] = useState(() => String(Date.now()));
  const [thumbStates, setThumbStates] = useState({});

  const [savedPalettes, setSavedPalettes] = useState([]);
  const [clients, setClients] = useState([]);
  const [seriesOptions, setSeriesOptions] = useState([]);
  const [clientPickerOpen, setClientPickerOpen] = useState(false);
  const [clientTargetPhotoId, setClientTargetPhotoId] = useState(null);
  const [paletteModalOpen, setPaletteModalOpen] = useState(false);
  const [paletteTarget, setPaletteTarget] = useState(null);
  const [groups, setGroups] = useState([]);
  const [groupId, setGroupId] = useState("");
  const [groupItems, setGroupItems] = useState(() => new Set());
  const [groupedItems, setGroupedItems] = useState(() => new Set());
  const [newGroupTitle, setNewGroupTitle] = useState("");
  const [groupStatus, setGroupStatus] = useState("");
  const [groupFilterMode, setGroupFilterMode] = useState("group");
  const [imageRefreshEnabled, setImageRefreshEnabledState] = useState(() => getImageRefreshEnabled());
  const [mobileFiltersOpen, setMobileFiltersOpen] = useState(false);
  const [expandedSections, setExpandedSections] = useState(() => ({
    upload: false,
  }));

  function toggleSection(sectionKey) {
    setExpandedSections((prev) => ({ ...prev, [sectionKey]: !prev[sectionKey] }));
  }

  function toggleImageRefresh() {
    const next = setImageRefreshEnabled(!imageRefreshEnabled);
    setImageRefreshEnabledState(next);
    setRefreshKey((value) => value + 1);
    setThumbNonce(String(Date.now()));
  }

  function getThumbState(photoLibraryId) {
    return thumbStates[String(photoLibraryId)] || "loading";
  }

  function markThumbLoaded(photoLibraryId) {
    setThumbStates((prev) => ({ ...prev, [String(photoLibraryId)]: "loaded" }));
  }

  function markThumbError(photoLibraryId) {
    setThumbStates((prev) => ({ ...prev, [String(photoLibraryId)]: "error" }));
  }

  useEffect(() => {
    let active = true;
    async function loadSavedPalettes() {
      try {
        const params = new URLSearchParams();
        params.set("limit", shouldUseExpandedPhotoSearch(filters, photoLibraryIdsFilter) ? "50" : "5");
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
    loadSavedPalettes();
    return () => {
      active = false;
    };
  }, []);

  useEffect(() => {
    let active = true;
    async function loadClients() {
      try {
        const params = new URLSearchParams();
        params.set("limit", "500");
        params.set("_", Date.now().toString());
        const res = await fetch(`${CLIENTS_LIST_URL}?${params.toString()}`, { credentials: "include" });
        const data = await res.json();
        if (!res.ok || !data?.ok) throw new Error(data?.error || "Failed to load clients");
        if (!active) return;
        setClients(Array.isArray(data.items) ? data.items : []);
      } catch {
        if (!active) return;
        setClients([]);
      }
    }
    loadClients();
    return () => {
      active = false;
    };
  }, []);

  useEffect(() => {
    let active = true;
    async function loadGroups() {
      try {
        const res = await fetch(`${GROUPS_LIST_URL}?_=${Date.now()}`, { credentials: "include" });
        const data = await res.json();
        if (!res.ok || !data?.ok) throw new Error(data?.error || "Failed to load groups");
        if (!active) return;
        setGroups(Array.isArray(data.items) ? data.items : []);
      } catch {
        if (!active) return;
        setGroups([]);
      }
    }
    loadGroups();
    return () => {
      active = false;
    };
  }, [refreshKey]);

  useEffect(() => {
    let active = true;
    async function loadGroupItems() {
      if (!groupId) {
        setGroupItems(new Set());
        return;
      }
      if (groupId === "__ungrouped__" || isClientGroupId(groupId)) {
        setGroupItems(new Set());
        return;
      }
      try {
        const res = await fetch(`${GROUPS_ITEMS_URL}?group_id=${groupId}&_=${Date.now()}`, { credentials: "include" });
        const data = await res.json();
        if (!res.ok || !data?.ok) throw new Error(data?.error || "Failed to load group");
        if (!active) return;
        setGroupItems(new Set((data.items || []).map(String)));
      } catch {
        if (!active) return;
        setGroupItems(new Set());
      }
    }
    loadGroupItems();
    return () => {
      active = false;
    };
  }, [groupId, refreshKey]);

  useEffect(() => {
    let active = true;
    async function loadGroupedItems() {
      try {
        const res = await fetch(`${GROUPS_ITEMS_ALL_URL}?_=${Date.now()}`, { credentials: "include" });
        const data = await res.json();
        if (!res.ok || !data?.ok) throw new Error(data?.error || "Failed to load grouped items");
        if (!active) return;
        setGroupedItems(new Set((data.items || []).map(String)));
      } catch {
        if (!active) return;
        setGroupedItems(new Set());
      }
    }
    loadGroupedItems();
    return () => {
      active = false;
    };
  }, [refreshKey]);

  const paletteOptions = useMemo(() => {
    return savedPalettes
      .map((palette) => ({
        id: palette.id,
        label: palette.nickname || palette.palette_hash || `Saved #${palette.id}`,
      }))
      .sort((a, b) => a.label.localeCompare(b.label, undefined, { sensitivity: "base" }));
  }, [savedPalettes]);

  const uploadSeriesLabel = useMemo(() => {
    if (uploadForm.source_type === "pin") return "Project folder";
    if (uploadForm.source_type === "client") return "Client folder";
    return "Series (folder label)";
  }, [uploadForm.source_type]);

  const uploadSeriesPlaceholder = useMemo(() => {
    if (uploadForm.source_type === "pin") return "mojdeh-interior-makeover";
    if (uploadForm.source_type === "client") return "Auto from client";
    return "ranch-demo";
  }, [uploadForm.source_type]);

  const uploadTitlePrefixLabel = useMemo(() => {
    if (uploadForm.source_type === "pin") return "Title prefix";
    return "Title prefix";
  }, [uploadForm.source_type]);

  const uploadTitlePrefixPlaceholder = useMemo(() => {
    if (uploadForm.source_type === "pin") return "Mojdeh pin";
    return "Ranch progression";
  }, [uploadForm.source_type]);

  useEffect(() => {
    let active = true;
    async function loadSeriesOptions() {
      if (uploadForm.source_type === "saved_palette" || uploadForm.source_type === "client") {
        setSeriesOptions([]);
        return;
      }
      try {
        const params = new URLSearchParams();
        params.set("source_type", uploadForm.source_type || "progression");
        params.set("limit", "300");
        params.set("_", Date.now().toString());
        const url = `${SERIES_LIST_URL}?${params.toString()}`;
        const res = await fetch(url, { credentials: "include" });
        const data = await parseJsonResponse(res, url);
        if (!res.ok || !data?.ok) {
          throw new Error(data?.error || "Failed to load folder labels");
        }
        if (!active) return;
        setSeriesOptions(Array.isArray(data.items) ? data.items : []);
      } catch {
        if (!active) return;
        setSeriesOptions([]);
      }
    }
    loadSeriesOptions();
    return () => {
      active = false;
    };
  }, [uploadForm.source_type, refreshKey]);

  const groupOptions = useMemo(() => {
    const clientOptions = clients
      .filter((client) => Number(client.id) > 0)
      .map((client) => ({
        id: buildClientGroupId(client.id),
        label: client.name ? `${client.name} (Client)` : `${client.email} (Client)`,
      }));

    return [
      { id: "__ungrouped__", label: "Ungrouped" },
      ...clientOptions,
      ...groups.map((group) => ({
        id: String(group.group_id),
        label: group.title,
      })),
    ];
  }, [clients, groups]);

  useEffect(() => {
    let active = true;
    async function loadLibrary() {
      if (!hasActiveLibrarySearch(filters, photoLibraryIdsFilter)) {
        if (!active) return;
        setLoading(false);
        setItems([]);
        setError("");
        return;
      }
      setLoading(true);
      setError("");
      setThumbNonce(String(Date.now()));
      setThumbStates({});
      try {
        const params = new URLSearchParams();
        if (filters.q) params.set("q", filters.q);
        if (photoLibraryIdsFilter) params.set("photo_library_ids", photoLibraryIdsFilter);
        if (filters.source_type) params.set("source_type", filters.source_type);
        if (filters.palette_id) params.set("palette_id", filters.palette_id);
        if (filters.sort) params.set("sort", filters.sort);
        if (filters.include_inactive) params.set("include_inactive", "1");
        if (filters.missing_tags) params.set("missing_tags", "1");
        params.set("limit", "200");
        params.set("_", Date.now().toString());
        const url = `${LIST_URL}?${params.toString()}`;
        const res = await fetch(url, { credentials: "include" });
        const data = await parseJsonResponse(res, url);
        if (!res.ok || !data?.ok) throw new Error(data?.error || "Failed to load photo library");
        if (!active) return;
        setItems(Array.isArray(data.items) ? data.items : []);
      } catch (err) {
        if (!active) return;
        setItems([]);
        setError(err?.message || "Failed to load photo library");
      } finally {
        if (active) setLoading(false);
      }
    }
    loadLibrary();
    return () => {
      active = false;
    };
  }, [filters.q, filters.source_type, filters.palette_id, filters.sort, filters.include_inactive, filters.missing_tags, photoLibraryIdsFilter, refreshKey]);

  const hasLibrarySearch = hasActiveLibrarySearch(filters, photoLibraryIdsFilter);

  const buildAdminImageUrl = (url, updatedAt = null) => {
    const base = buildImageUrl(url, updatedAt, imageRefreshEnabled);
    if (!base) return "";
    const sep = base.includes("?") ? "&" : "?";
    return `${base}${sep}admin=${thumbNonce}`;
  };

  const handleUploadField = (key, value) => {
    setUploadForm((prev) => ({ ...prev, [key]: value }));
  };

  const handleLibraryField = (id, key, value) => {
    setItems((prev) =>
      prev.map((item) => (item.photo_library_id === id ? { ...item, [key]: value } : item))
    );
    setDirtyIds((prev) => {
      const next = new Set(prev);
      next.add(id);
      return next;
    });
  };

  const patchLibraryItem = (id, fields) => {
    setItems((prev) =>
      prev.map((item) => (
        item.photo_library_id === id
          ? { ...item, ...fields }
          : item
      ))
    );
    setDirtyIds((prev) => {
      const next = new Set(prev);
      next.add(id);
      return next;
    });
  };

  const openPaletteModalForItem = (item) => {
    setPaletteTarget({
      paletteId: item?.attached_saved_palette_id || null,
      attachment: {
        photo_library_id: item?.photo_library_id || null,
        raw_rel_path: item?.raw_rel_path || item?.rel_path || "",
        rel_path: item?.raw_rel_path || item?.rel_path || "",
        image_url: item?.image_url || item?.raw_rel_path || item?.rel_path || "",
        alt_text: item?.alt_text || "",
        photo_type: item?.attached_saved_palette_photo_type || "full",
        trigger_mode: item?.attached_saved_palette_trigger_mode || "any",
        trigger_color_id: item?.attached_saved_palette_trigger_color_id || null,
        show_in_gallery: !!item?.show_in_gallery,
      },
    });
    setPaletteModalOpen(true);
  };

  const handleClientPicked = (client) => {
    if (!client) return;
    if (clientTargetPhotoId) {
      patchLibraryItem(clientTargetPhotoId, {
        source_type: "client",
        client_id: Number(client.id) || null,
        client_name: client.name || "",
        client_email: client.email || "",
      });
    } else {
      setUploadForm((prev) => ({
        ...prev,
        client_id: String(client.id || ""),
        client_name: client.name || "",
        client_email: client.email || "",
      }));
    }
    setClients((prev) => {
      const existing = prev.some((item) => Number(item.id) === Number(client.id));
      if (existing) {
        return prev.map((item) => (Number(item.id) === Number(client.id) ? client : item));
      }
      return [client, ...prev];
    });
    setClientTargetPhotoId(null);
    setClientPickerOpen(false);
  };

  const togglePath = (id) => {
    setExpandedPathIds((prev) => {
      const next = new Set(prev);
      if (next.has(id)) next.delete(id);
      else next.add(id);
      return next;
    });
  };

  const formatUpdatedAt = (value) => {
    const raw = String(value || "").trim();
    if (!raw) return "";
    const parsed = new Date(raw.replace(" ", "T"));
    if (Number.isNaN(parsed.getTime())) return raw;
    return parsed.toLocaleString([], {
      year: "numeric",
      month: "short",
      day: "numeric",
      hour: "numeric",
      minute: "2-digit",
    });
  };

  const handleReplaceFileChange = (id, file) => {
    setReplaceFiles((prev) => ({ ...prev, [id]: file || null }));
  };

  const handleReplace = async (item) => {
    const id = item.photo_library_id;
    const file = replaceFiles[id];
    if (!file) return;
    setError("");
    setReplaceStatus("");
    try {
      const formData = new FormData();
      formData.append("photo_library_id", String(id));
      formData.append("photo", file);
      const res = await fetch(REPLACE_URL, {
        method: "POST",
        credentials: "include",
        body: formData,
      });
      const data = await res.json().catch(() => ({}));
      if (!res.ok || !data?.ok) {
        throw new Error(data?.error || "Replace failed");
      }
      if (data?.written_to) {
        setReplaceStatus(`Replaced #${id} -> ${data.written_to}`);
      } else {
        setReplaceStatus(`Replaced #${id}.`);
      }
      setReplaceFiles((prev) => {
        const next = { ...prev };
        delete next[id];
        return next;
      });
      setRefreshKey((prev) => prev + 1);
    } catch (err) {
      setError(err?.message || "Replace failed");
    }
  };

  const resetUpload = () => {
    setUploadForm(defaultUpload);
    setFiles([]);
    setUploadStatus({ error: "", success: "" });
  };

  const handleUploadSubmit = async (event) => {
    event.preventDefault();
    if (!uploadForm.tags.trim()) {
      setUploadStatus({ error: "Add at least one tag before uploading photos.", success: "" });
      return;
    }
    if (!files.length) {
      setUploadStatus({ error: "Select at least one photo.", success: "" });
      return;
    }
    if (uploadForm.source_type === "saved_palette" && !uploadForm.palette_id) {
      setUploadStatus({ error: "Choose a saved palette.", success: "" });
      return;
    }
    if (uploadForm.source_type === "client" && !uploadForm.client_email.trim()) {
      setUploadStatus({ error: "Client email is required for client uploads.", success: "" });
      return;
    }
    setUploading(true);
    setUploadStatus({ error: "", success: "" });
    try {
      const formData = new FormData();
      Array.from(files).forEach((file) => formData.append("photos[]", file));

      let res;
      if (uploadForm.source_type === "saved_palette") {
        formData.append("palette_id", String(uploadForm.palette_id));
        if (uploadForm.set_id && uploadForm.set_id !== "__new__") formData.append("set_id", String(uploadForm.set_id));
        if (uploadForm.set_id === "__new__") formData.append("create_new_set", "1");
        formData.append("photo_type", uploadForm.photo_type || "full");
        formData.append("tags", uploadForm.tags || "");
        formData.append("alt_text", uploadForm.alt_text || "");
        formData.append("photo_permission_status", uploadForm.photo_permission_status || "");
        res = await fetch(SAVED_UPLOAD_URL, {
          method: "POST",
          credentials: "include",
          body: formData,
        });
      } else {
        formData.append("source_type", uploadForm.source_type);
        formData.append("series", uploadForm.series || "");
        formData.append("title_prefix", uploadForm.title_prefix || "");
        formData.append("client_name", uploadForm.client_name || "");
        formData.append("client_email", uploadForm.client_email || "");
        formData.append("photo_permission_status", uploadForm.photo_permission_status || "");
        formData.append("tags", uploadForm.tags || "");
        formData.append("alt_text", uploadForm.alt_text || "");
        if (uploadForm.show_in_gallery) formData.append("show_in_gallery", "1");
        if (uploadForm.has_palette) formData.append("has_palette", "1");
        res = await fetch(UPLOAD_URL, {
          method: "POST",
          credentials: "include",
          body: formData,
        });
      }

      const data = await res.json().catch(() => ({}));
      if (!res.ok || !data?.ok) {
        throw new Error(data?.error || `HTTP ${res.status}`);
      }
      setUploadStatus({ error: "", success: "Upload complete." });
      resetUpload();
      setRefreshKey((prev) => prev + 1);
    } catch (err) {
      setUploadStatus({ error: err?.message || "Upload failed", success: "" });
    } finally {
      setUploading(false);
    }
  };

  const handleLibrarySave = async (item) => {
    setError("");
    try {
      if (replaceFiles[item.photo_library_id]) {
        await handleReplace(item);
      }
      const payload = {
        photo_library_id: item.photo_library_id,
        source_type: item.source_type,
        title: item.title,
        tags: item.tags,
        alt_text: item.alt_text,
        note: item.note,
        photo_permission_status: item.photo_permission_override_status || "",
        show_in_gallery: !!item.show_in_gallery,
        has_palette: !!item.has_palette,
        is_inactive: !!item.is_inactive,
      };
      if (item.source_type === "client" || item.client_id) {
        payload.client_name = item.client_name || "";
        payload.client_email = item.client_email || "";
      }
      const res = await fetch(UPDATE_URL, {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(payload),
      });
      const data = await res.json().catch(() => ({}));
      if (!res.ok || !data?.ok) {
        throw new Error(data?.error || "Save failed");
      }
      setRefreshKey((prev) => prev + 1);
      setDirtyIds((prev) => {
        const next = new Set(prev);
        next.delete(item.photo_library_id);
        return next;
      });
    } catch (err) {
      setError(err?.message || "Failed to save row");
    }
  };

  const handleSaveAll = async () => {
    if (!dirtyIds.size) return;
    setError("");
    const dirtyItems = items.filter((row) => dirtyIds.has(row.photo_library_id));
    try {
      for (const item of dirtyItems) {
        const payload = {
          photo_library_id: item.photo_library_id,
          source_type: item.source_type,
          title: item.title,
          tags: item.tags,
          alt_text: item.alt_text,
          note: item.note,
          photo_permission_status: item.photo_permission_override_status || "",
          show_in_gallery: !!item.show_in_gallery,
          has_palette: !!item.has_palette,
          is_inactive: !!item.is_inactive,
        };
        if (item.source_type === "client" || item.client_id) {
          payload.client_name = item.client_name || "";
          payload.client_email = item.client_email || "";
        }
        const res = await fetch(UPDATE_URL, {
          method: "POST",
          credentials: "include",
          headers: { "Content-Type": "application/json" },
            body: JSON.stringify(payload),
        });
        const data = await res.json().catch(() => ({}));
        if (!res.ok || !data?.ok) {
          throw new Error(data?.error || `Save failed for #${item.photo_library_id}`);
        }
      }
      setDirtyIds(new Set());
      setRefreshKey((prev) => prev + 1);
    } catch (err) {
      setError(err?.message || "Failed to save rows");
    }
  };

  const handleLibraryDelete = async (item) => {
    setError("");
    try {
      const usageRes = await fetch(`${USAGE_URL}?photo_library_id=${encodeURIComponent(item.photo_library_id)}`, {
        credentials: "include",
      });
      const usageData = await usageRes.json().catch(() => ({}));
      if (!usageRes.ok || !usageData?.ok) {
        throw new Error(usageData?.error || "Failed to check photo usage");
      }
      if (Array.isArray(usageData.usages) && usageData.usages.length > 0) {
        window.alert(formatDeleteUsageMessage(item, usageData.usages));
        return;
      }

      if (!window.confirm("Delete this photo from the library?")) return;

      const res = await fetch(DELETE_URL, {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ photo_library_id: item.photo_library_id }),
      });
      const data = await res.json().catch(() => ({}));
      if (!res.ok || !data?.ok) {
        if (res.status === 409 && Array.isArray(data?.usages) && data.usages.length > 0) {
          window.alert(formatDeleteUsageMessage(item, data.usages));
          return;
        }
        throw new Error(data?.error || "Delete failed");
      }
      setItems((prev) => prev.filter((row) => row.photo_library_id !== item.photo_library_id));
      setRefreshKey((prev) => prev + 1);
    } catch (err) {
      setError(err?.message || "Failed to delete row");
    }
  };

  const handleInvestigate = async (item) => {
    setError("");
    try {
      const usageRes = await fetch(`${USAGE_URL}?photo_library_id=${encodeURIComponent(item.photo_library_id)}`, {
        credentials: "include",
      });
      const usageData = await usageRes.json().catch(() => ({}));
      if (!usageRes.ok || !usageData?.ok) {
        throw new Error(usageData?.error || "Failed to inspect photo usage");
      }
      setUsageModal({
        item,
        usages: Array.isArray(usageData.usages) ? usageData.usages : [],
      });
    } catch (err) {
      setError(err?.message || "Failed to inspect row");
    }
  };

  const showSavedFields = uploadForm.source_type === "saved_palette";
  const showLibraryFields = uploadForm.source_type !== "saved_palette";
  const showTagFields = true;
  const showClientFields = uploadForm.source_type === "client";
  const showSeriesField = showLibraryFields && !showClientFields;

  const inGroup = (photoId) => groupItems.has(String(photoId));

  const filteredItems = useMemo(() => {
    if (!groupId || groupFilterMode === "all") return items;
    if (groupId === "__ungrouped__") {
      return items.filter((item) => (
        !groupedItems.has(String(item.photo_library_id)) &&
        Number(item.client_id || 0) <= 0
      ));
    }
    if (isClientGroupId(groupId)) {
      const clientId = parseClientGroupId(groupId);
      if (!clientId) return items;
      return items.filter((item) => Number(item.client_id) === clientId);
    }
    return items.filter((item) => groupItems.has(String(item.photo_library_id)));
  }, [items, groupId, groupFilterMode, groupItems, groupedItems]);

  const getDisplaySourceType = (item) => {
    if (Number(item?.client_id || 0) > 0) return "client";
    return item?.source_type || "";
  };

  async function handleCreateGroup() {
    const title = newGroupTitle.trim();
    if (!title) return;
    setGroupStatus("");
    try {
      const res = await fetch(GROUPS_CREATE_URL, {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ title }),
      });
      const data = await res.json();
      if (!res.ok || !data?.ok) throw new Error(data?.error || "Create failed");
      setNewGroupTitle("");
      setGroupId(String(data.group_id));
      setRefreshKey((prev) => prev + 1);
    } catch (err) {
      setGroupStatus(err?.message || "Create failed");
    }
  }

  async function handleDeleteGroup() {
    if (!groupId) return;
    if (isClientGroupId(groupId)) return;
    if (!window.confirm("Delete this group? Photos will not be deleted.")) return;
    setGroupStatus("");
    try {
      const res = await fetch(GROUPS_DELETE_URL, {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ group_id: Number(groupId) }),
      });
      const data = await res.json();
      if (!res.ok || !data?.ok) throw new Error(data?.error || "Delete failed");
      setGroupId("");
      setGroupItems(new Set());
      setRefreshKey((prev) => prev + 1);
    } catch (err) {
      setGroupStatus(err?.message || "Delete failed");
    }
  }

  async function handleAddToGroup(photoId) {
    if (!groupId) return;
    if (groupId === "__ungrouped__") return;
    try {
      const anchor = document.querySelector(`#photo-row-${photoId}`);
      const res = await fetch(GROUPS_ADD_URL, {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ group_id: Number(groupId), photo_library_id: Number(photoId) }),
      });
      const data = await res.json();
      if (!res.ok || !data?.ok) throw new Error(data?.error || "Add failed");
      setGroupItems((prev) => new Set(prev).add(String(photoId)));
      setGroupedItems((prev) => {
        const next = new Set(prev);
        next.add(String(photoId));
        return next;
      });
      if (anchor) {
        anchor.scrollIntoView({ block: "center" });
      }
    } catch (err) {
      setGroupStatus(err?.message || "Add failed");
    }
  }

  async function handleRemoveFromGroup(photoId) {
    if (!groupId) return;
    if (groupId === "__ungrouped__") return;
    try {
      const anchor = document.querySelector(`#photo-row-${photoId}`);
      const res = await fetch(GROUPS_REMOVE_URL, {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ group_id: Number(groupId), photo_library_id: Number(photoId) }),
      });
      const data = await res.json();
      if (!res.ok || !data?.ok) throw new Error(data?.error || "Remove failed");
      setGroupItems((prev) => {
        const next = new Set(prev);
        next.delete(String(photoId));
        return next;
      });
      setGroupedItems((prev) => {
        const next = new Set(prev);
        next.delete(String(photoId));
        return next;
      });
      if (anchor) {
        anchor.scrollIntoView({ block: "center" });
      }
    } catch (err) {
      setGroupStatus(err?.message || "Remove failed");
    }
  }

  useEffect(() => {
    if (uploadForm.source_type === "saved_palette" && uploadForm.palette_id) {
      setFilters((prev) => ({
        ...prev,
        source_type: "saved_palette_photo",
        palette_id: uploadForm.palette_id,
      }));
    }
  }, [uploadForm.source_type, uploadForm.palette_id]);

  useEffect(() => {
    if (!hasActiveLibrarySearch(filters, photoLibraryIdsFilter)) return;
    setExpandedSections((prev) => ({
      ...prev,
      upload: false,
    }));
  }, [
    filters.q,
    filters.source_type,
    filters.palette_id,
    filters.include_inactive,
    filters.missing_tags,
    photoLibraryIdsFilter,
  ]);

  return (
    <div className="admin-photo-library">
      <header className="admin-photo-library__header">
        <h1>Photo Library</h1>
        <p>Upload and tag photos for playlists, progressions, or saved palettes.</p>
      </header>

      <section className="admin-photo-library__section admin-photo-library__accordion">
        <button
          type="button"
          className="admin-photo-library__accordion-toggle"
          onClick={() => toggleSection("upload")}
          aria-expanded={expandedSections.upload}
        >
          <span>Upload Photos</span>
          <span className="admin-photo-library__accordion-icon" aria-hidden="true">
            {expandedSections.upload ? "−" : "+"}
          </span>
        </button>
        {expandedSections.upload && (
          <div className="admin-photo-library__accordion-panel">
            {uploadStatus.error && <div className="admin-photo-library__error">{uploadStatus.error}</div>}
            {uploadStatus.success && <div className="admin-photo-library__status">{uploadStatus.success}</div>}
            {replaceStatus && <div className="admin-photo-library__status">{replaceStatus}</div>}
            <form className="admin-photo-library__upload" onSubmit={handleUploadSubmit}>
          <label>
            Type
            <select
              value={uploadForm.source_type}
              onChange={(e) => handleUploadField("source_type", e.target.value)}
            >
              {SOURCE_OPTIONS.map((opt) => (
                <option key={opt.value} value={opt.value}>
                  {opt.label}
                </option>
              ))}
            </select>
          </label>

          {showSavedFields && (
            <label>
              Saved Palette
              <select
                value={uploadForm.palette_id}
                onChange={(e) => {
                  handleUploadField("palette_id", e.target.value);
                  handleUploadField("set_id", "");
                }}
              >
                <option value="">Select palette</option>
                {paletteOptions.map((palette) => (
                  <option key={palette.id} value={palette.id}>
                    {palette.label}
                  </option>
                ))}
              </select>
            </label>
          )}

          {showSavedFields && uploadForm.palette_id && (
            <label>
              Photo Group
              <select
                value={uploadForm.set_id}
                onChange={(e) => handleUploadField("set_id", e.target.value)}
              >
                <option value="">Primary / Default Group</option>
                {(savedPalettes.find((palette) => String(palette.id) === String(uploadForm.palette_id))?.sets || []).map((set) => (
                  <option key={set.id} value={set.id}>
                    {set.title || set.slug || `Set #${set.id}`}
                  </option>
                ))}
                <option value="__new__">Create New Group</option>
              </select>
            </label>
          )}

          {showSavedFields && uploadForm.palette_id && (
            <label>
              Palette Photo Type
              <select
                value={uploadForm.photo_type}
                onChange={(e) => handleUploadField("photo_type", e.target.value)}
              >
                <option value="full">Full</option>
                <option value="before">Before</option>
                <option value="zoom">Zoom</option>
              </select>
            </label>
          )}

          {showClientFields && (
            <label>
              Existing client
              <div className="admin-photo-library__client-picker">
                <input
                  type="text"
                  value={(() => {
                    const selected = clients.find((client) => String(client.id) === String(uploadForm.client_id));
                    if (!selected) return "";
                    const label = displayClientListName(selected) || selected.email || "";
                    return selected.email ? `${label} (${selected.email})` : label;
                  })()}
                  readOnly
                  placeholder="Pick client"
                />
                <button
                  type="button"
                  className="ghost"
                  onClick={() => setClientPickerOpen(true)}
                >
                  Client…
                </button>
                {uploadForm.client_id ? (
                  <button
                    type="button"
                    className="ghost"
                    onClick={() => {
                      setUploadForm((prev) => ({
                        ...prev,
                        client_id: "",
                        client_name: "",
                        client_email: "",
                      }));
                    }}
                  >
                    Clear
                  </button>
                ) : null}
              </div>
            </label>
          )}

          {showClientFields && (
            <label>
              Client name
              <input
                type="text"
                value={uploadForm.client_name}
                onChange={(e) => handleUploadField("client_name", e.target.value)}
                placeholder="Mojdeh"
              />
            </label>
          )}

          {showClientFields && (
            <label>
              Client email
              <input
                type="email"
                value={uploadForm.client_email}
                onChange={(e) => handleUploadField("client_email", e.target.value)}
                placeholder="mojdeh@example.com"
              />
            </label>
          )}

          {showSeriesField && (
            <label>
              {uploadSeriesLabel}
              <div className="admin-photo-library__series-picker">
                <select
                  value={seriesOptions.includes(uploadForm.series) ? uploadForm.series : ""}
                  onChange={(e) => handleUploadField("series", e.target.value)}
                >
                  <option value="">Choose existing folder label</option>
                  {seriesOptions.map((series) => (
                    <option key={series} value={series}>
                      {series}
                    </option>
                  ))}
                </select>
                <input
                  type="text"
                  value={uploadForm.series}
                  onChange={(e) => handleUploadField("series", e.target.value)}
                  placeholder={uploadSeriesPlaceholder}
                />
              </div>
            </label>
          )}

          {showLibraryFields && (
            <label>
              {uploadTitlePrefixLabel}
              <input
                type="text"
                value={uploadForm.title_prefix}
                onChange={(e) => handleUploadField("title_prefix", e.target.value)}
                placeholder={uploadTitlePrefixPlaceholder}
              />
            </label>
          )}

          <label>
            Permission
            <select
              value={uploadForm.photo_permission_status}
              onChange={(e) => handleUploadField("photo_permission_status", e.target.value)}
            >
              <option value="">Use client/default</option>
              <option value="not_needed">Not needed</option>
              <option value="granted">Granted</option>
              <option value="requested">Requested</option>
              <option value="declined">Declined</option>
              <option value="unknown">Unknown</option>
            </select>
          </label>

          {showTagFields && (
            <label>
              Tags (comma separated)
              <input
                type="text"
                value={uploadForm.tags}
                onChange={(e) => handleUploadField("tags", e.target.value)}
                placeholder="ranch, progression, exterior"
              />
            </label>
          )}

          {showTagFields && (
            <label>
              Alt text (SEO)
              <input
                type="text"
                value={uploadForm.alt_text}
                onChange={(e) => handleUploadField("alt_text", e.target.value)}
                placeholder="Optional alt text"
              />
            </label>
          )}

          {showLibraryFields && (
            <label className="admin-photo-library__check">
              <input
                type="checkbox"
                checked={uploadForm.show_in_gallery}
                onChange={(e) => handleUploadField("show_in_gallery", e.target.checked)}
              />
              Show in gallery
            </label>
          )}

          {showLibraryFields && (
            <label className="admin-photo-library__check">
              <input
                type="checkbox"
                checked={uploadForm.has_palette}
                onChange={(e) => handleUploadField("has_palette", e.target.checked)}
              />
              Has palette
            </label>
          )}

          <label className="admin-photo-library__file">
            Photos
            <input
              type="file"
              accept="image/*"
              multiple
              onChange={(e) => setFiles(Array.from(e.target.files || []))}
              disabled={uploading}
            />
          </label>

              <div className="admin-photo-library__upload-actions">
                <button type="submit" disabled={uploading}>
                  {uploading ? "Uploading…" : "Upload"}
                </button>
                <button type="button" className="ghost" onClick={resetUpload} disabled={uploading}>
                  Clear
                </button>
              </div>
            </form>
          </div>
        )}
      </section>

      <section className="admin-photo-library__section admin-photo-library__search-strip">
        {error && <div className="admin-photo-library__error">{error}</div>}
        <div className="admin-photo-library__filters">
          <div className="admin-photo-library__filters-main">
            <form
              className="admin-photo-library__search-form"
              onSubmit={(e) => {
                e.preventDefault();
                setFilters((prev) => ({ ...prev, q: searchInput.trim() }));
              }}
            >
              <label className="admin-photo-library__search-label" aria-label="Search photo library">
                <input
                  type="text"
                  value={searchInput}
                  onChange={(e) => setSearchInput(e.target.value)}
                  placeholder="title, tags, path, or photo id"
                />
              </label>
              <div className="admin-photo-library__search-actions">
                <button type="submit">Search</button>
                <button
                  type="button"
                  className="ghost admin-photo-library__filters-inline-toggle"
                  onClick={() => setMobileFiltersOpen((prev) => !prev)}
                  aria-expanded={mobileFiltersOpen}
                >
                  {mobileFiltersOpen ? "Filters −" : "Filters +"}
                </button>
              </div>
            </form>
          </div>

          <div className={`admin-photo-library__filters-advanced${mobileFiltersOpen ? " is-open" : ""}`}>
            <div className="admin-photo-library__filters-advanced-actions">
              <button
                type="button"
                className="ghost"
                onClick={() => {
                  setSearchInput("");
                  setPhotoLibraryIdsFilter("");
                  setFilters((prev) => ({ ...prev, q: "" }));
                }}
              >
                Clear Search
              </button>
            </div>
            <label>
              Group
              <select
                value={groupId}
                onChange={(e) => {
                  setGroupId(e.target.value);
                  setGroupFilterMode("group");
                }}
              >
                <option value="">All photos</option>
                {groupOptions.map((group) => (
                  <option key={group.id} value={group.id}>
                    {group.label}
                  </option>
                ))}
              </select>
            </label>
            <div className="admin-photo-library__group-actions">
              <div className="admin-photo-library__group-create">
                <input
                  type="text"
                  value={newGroupTitle}
                  onChange={(e) => setNewGroupTitle(e.target.value)}
                  placeholder="New group title"
                />
                <button type="button" onClick={handleCreateGroup} disabled={!newGroupTitle.trim()}>
                  Add Group
                </button>
              </div>
              {groupId && !isClientGroupId(groupId) && (
                <button
                  type="button"
                  className="ghost"
                  onClick={() => setGroupFilterMode((prev) => (prev === "group" ? "all" : "group"))}
                >
                  {groupFilterMode === "group" ? "Show All To Add" : "Show Group Only"}
                </button>
              )}
              <button type="button" className="ghost danger" onClick={handleDeleteGroup} disabled={!groupId || isClientGroupId(groupId)}>
                Delete Group
              </button>
              {groupStatus && <div className="admin-photo-library__group-status">{groupStatus}</div>}
            </div>
            <label>
              Type
              <select
                value={filters.source_type}
                onChange={(e) => setFilters((prev) => ({ ...prev, source_type: e.target.value }))}
              >
                <option value="">All</option>
                <option value="saved_palette_photo">Saved palette</option>
                <option value="applied_palette">Applied palette</option>
                <option value="progression">Progression</option>
                <option value="client">Client</option>
                <option value="article">Article</option>
                <option value="pin">Pin</option>
                <option value="extra_photo">Extras</option>
              </select>
            </label>
            <label>
              Sort
              <select
                value={filters.sort}
                onChange={(e) => setFilters((prev) => ({ ...prev, sort: e.target.value }))}
              >
                <option value="newest">Newest uploads</option>
                <option value="oldest">Oldest uploads</option>
                <option value="id_desc">ID desc</option>
                <option value="id_asc">ID asc</option>
                <option value="title">Title</option>
              </select>
            </label>
            <label className="admin-photo-library__inline-check">
              <input
                type="checkbox"
                checked={!!filters.include_inactive}
                onChange={(e) => setFilters((prev) => ({ ...prev, include_inactive: e.target.checked }))}
              />
              Show retired
            </label>
            <label className="admin-photo-library__inline-check">
              <input
                type="checkbox"
                checked={!!filters.missing_tags}
                onChange={(e) => setFilters((prev) => ({ ...prev, missing_tags: e.target.checked }))}
              />
              Missing tags
            </label>
            {filters.source_type === "saved_palette_photo" && (
              <label>
                Palette
                <select
                  value={filters.palette_id}
                  onChange={(e) => setFilters((prev) => ({ ...prev, palette_id: e.target.value }))}
                >
                  <option value="">All palettes</option>
                  {paletteOptions.map((palette) => (
                    <option key={palette.id} value={palette.id}>
                      {palette.label}
                    </option>
                  ))}
                </select>
              </label>
            )}
            <div className="admin-photo-library__filters-extra-actions">
              <button type="button" className="ghost" onClick={toggleImageRefresh}>
                {imageRefreshEnabled ? "Image Refresh: On" : "Image Refresh: Off"}
              </button>
              <div className="admin-photo-library__save-all">
                <a className="admin-photo-library__tools-link" href="/admin/photo-library-tools">
                  Library Tools
                </a>
                <button type="button" onClick={handleSaveAll} disabled={!dirtyIds.size}>
                  Save All
                </button>
                {dirtyIds.size > 0 && <div className="admin-photo-library__dirty-count">{dirtyIds.size} unsaved</div>}
              </div>
            </div>
          </div>
        </div>
      </section>

      <section className="admin-photo-library__section admin-photo-library__results-panel">
        {!hasLibrarySearch ? (
          <div className="admin-photo-library__empty">Search to load photo library results.</div>
        ) : loading ? (
          <div className="admin-photo-library__loading">Loading…</div>
        ) : (
          <div className="admin-photo-library__table-wrap">
            <table className="admin-photo-library__table">
              <thead>
                <tr>
                  <th>ID</th>
                  <th>Preview</th>
                  <th>Text</th>
                  <th>Type</th>
                  <th>Palette Set</th>
                  <th>Flags</th>
                  <th />
                </tr>
              </thead>
              <tbody>
                {filteredItems.map((item) => (
                  <tr key={item.photo_library_id} className={dirtyIds.has(item.photo_library_id) ? "is-dirty" : ""}>
                    <td>
                      <div className="admin-photo-library__id">
                        <span>{item.photo_library_id}</span>
                        <button
                          type="button"
                          className="ghost admin-photo-library__copy"
                          onClick={() => navigator.clipboard.writeText(String(item.photo_library_id))}
                        >
                          Copy
                        </button>
                      </div>
                    </td>
                    <td>
                      {(() => {
                        const thumbSrc = buildAdminImageUrl(
                          item.raw_rel_path || item.rel_path || item.image_url,
                          item.updated_at || null
                        );
                        const thumbState = getThumbState(item.photo_library_id);
                        return (
                      <button
                        type="button"
                        className="admin-photo-library__thumb"
                        onClick={() =>
                          setPreviewUrl(
                            thumbSrc
                          )
                        }
                      >
                        {thumbState !== "loaded" ? (
                          <div
                            className={`admin-photo-library__thumb-placeholder${thumbState === "error" ? " is-error" : ""}`}
                          >
                            {thumbState === "error" ? "Missing" : "Loading..."}
                          </div>
                        ) : null}
                        <img
                          src={thumbSrc}
                          alt=""
                          className={thumbState === "loaded" ? "is-visible" : ""}
                          onLoad={() => markThumbLoaded(item.photo_library_id)}
                          onError={() => markThumbError(item.photo_library_id)}
                        />
                      </button>
                        );
                      })()}
                      <div className="admin-photo-library__thumb-name">
                        {item.filename || ""}
                      </div>
                      {item.updated_at ? (
                        <div className="admin-photo-library__thumb-updated">
                          Updated: {formatUpdatedAt(item.updated_at)}
                        </div>
                      ) : null}
                      <div className="admin-photo-library__thumb-actions">
                        <button
                          type="button"
                          className="ghost admin-photo-library__thumb-action"
                          onClick={() => togglePath(item.photo_library_id)}
                        >
                          {expandedPathIds.has(item.photo_library_id) ? "Hide path" : "Path"}
                        </button>
                        <button
                          type="button"
                          className="ghost admin-photo-library__thumb-action"
                          onClick={() => navigator.clipboard.writeText(item.raw_rel_path || item.rel_path || "")}
                        >
                          Copy path
                        </button>
                      </div>
                      {expandedPathIds.has(item.photo_library_id) && (
                        <div className="admin-photo-library__thumb-path">
                          {item.raw_rel_path || item.rel_path || ""}
                        </div>
                      )}
                    </td>
                    <td>
                      <div className="admin-photo-library__text-stack">
                        <input
                          type="text"
                          value={item.title || ""}
                          onChange={(e) => handleLibraryField(item.photo_library_id, "title", e.target.value)}
                          placeholder="title"
                        />
                        <input
                          type="text"
                          value={item.tags || ""}
                          onChange={(e) => handleLibraryField(item.photo_library_id, "tags", e.target.value)}
                          placeholder="tags"
                        />
                        <input
                          type="text"
                          value={item.alt_text || ""}
                          onChange={(e) => handleLibraryField(item.photo_library_id, "alt_text", e.target.value)}
                          placeholder="alt text"
                        />
                        {formatAiAltStatus(item) && (
                          <div className="admin-photo-library__ai-alt-status">
                            {formatAiAltStatus(item)}
                            {item.ai_filename_slug ? ` · ${item.ai_filename_slug}` : ""}
                          </div>
                        )}
                        <input
                          type="text"
                          value={item.note || ""}
                          onChange={(e) => handleLibraryField(item.photo_library_id, "note", e.target.value)}
                          placeholder="note"
                        />
                        {item.source_type === "client" && (
                          <>
                            <input
                              type="text"
                              value={item.client_name || ""}
                              onChange={(e) => handleLibraryField(item.photo_library_id, "client_name", e.target.value)}
                              placeholder="client name"
                            />
                          </>
                        )}
                      </div>
                    </td>
                    <td>
                      <div className="admin-photo-library__meta">
                        <div>{getDisplaySourceType(item)}</div>
                        {item.source_id && <div className="muted">#{item.source_id}</div>}
                        {item.client_name ? <div className="muted">Client: {item.client_name}</div> : null}
                      </div>
                    </td>
                    <td>
                      <div className="admin-photo-library__meta">
                        {item.attached_saved_palette_id ? (
                          <>
                            {(() => {
                              const type = String(item.attached_saved_palette_photo_type || "full").toLowerCase();
                              const isBefore = type === "before";
                              const linkCount = Number(item.attached_saved_palette_link_count || 0);
                              const typeLabel =
                                isBefore
                                  ? "Before companion"
                                  : type === "zoom"
                                    ? "Zoom image"
                                    : "Main image";
                              return (
                                <>
                                  <div>
                                    {linkCount > 1
                                      ? `${linkCount} viewer links`
                                      : isBefore
                                      ? typeLabel
                                      : (item.attached_saved_palette_label || `Saved #${item.attached_saved_palette_id}`)}
                                  </div>
                                  <div className="muted">
                                    {linkCount > 1
                                      ? `${item.attached_saved_palette_label || `Saved #${item.attached_saved_palette_id}`} | ${typeLabel}`
                                      : isBefore
                                      ? `${item.attached_saved_palette_label || `Saved #${item.attached_saved_palette_id}`} | ${item.attached_saved_palette_set_label || "Primary"}`
                                      : `${item.attached_saved_palette_set_label || "Primary"} | ${typeLabel}`}
                                  </div>
                                </>
                              );
                            })()}
                            <div className="admin-photo-library__palette-actions">
                              <button
                                type="button"
                                className="ghost"
                                onClick={() => openPaletteModalForItem(item)}
                              >
                                Link…
                              </button>
                              <button
                                type="button"
                                className="ghost"
                                onClick={() => openSavedPaletteViewerSetup(
                                  item.attached_saved_palette_id,
                                  item.attached_saved_palette_set_id,
                                  item.photo_library_id
                                )}
                              >
                                Viewer
                              </button>
                            </div>
                          </>
                        ) : (
                          <>
                            <div className="muted">No palette attached</div>
                            <div className="admin-photo-library__palette-actions">
                              <button
                                type="button"
                                className="ghost"
                                onClick={() => openPaletteModalForItem(item)}
                              >
                                Attach
                              </button>
                            </div>
                          </>
                        )}
                      </div>
                    </td>
                    <td>
                      <div className="admin-photo-library__flags">
                        <label className="admin-photo-library__check">
                          <input
                            type="checkbox"
                            checked={!!item.show_in_gallery}
                            onChange={(e) => handleLibraryField(item.photo_library_id, "show_in_gallery", e.target.checked)}
                          />
                          Gallery
                        </label>
                        <label className="admin-photo-library__check">
                          <input
                            type="checkbox"
                            checked={!!item.has_palette}
                            onChange={(e) => handleLibraryField(item.photo_library_id, "has_palette", e.target.checked)}
                          />
                          Palette
                        </label>
                        <label className="admin-photo-library__check">
                          <input
                            type="checkbox"
                            checked={!!item.is_inactive}
                            onChange={(e) => handleLibraryField(item.photo_library_id, "is_inactive", e.target.checked)}
                          />
                          Retired
                        </label>
                        <label className="admin-photo-library__permission-select">
                          Permission
                          <select
                            value={item.photo_permission_override_status || ""}
                            onChange={(e) => {
                              handleLibraryField(item.photo_library_id, "photo_permission_override_status", e.target.value);
                              handleLibraryField(item.photo_library_id, "photo_permission_status", e.target.value || item.client_photo_permission_status || "unknown");
                            }}
                          >
                            <option value="">Use client/default</option>
                            <option value="not_needed">Not needed</option>
                            <option value="granted">Granted</option>
                            <option value="requested">Requested</option>
                            <option value="declined">Declined</option>
                            <option value="unknown">Unknown</option>
                          </select>
                        </label>
                      </div>
                    </td>
                    <td className="admin-photo-library__row-actions">
                      <div className="admin-photo-library__row-actions-top">
                        <button
                          type="button"
                          className="ghost"
                          onClick={() => {
                            setClientTargetPhotoId(item.photo_library_id);
                            setClientPickerOpen(true);
                          }}
                        >
                          Client…
                        </button>
                        <button
                          type="button"
                          className="ghost admin-photo-library__inspect"
                          onClick={() => handleInvestigate(item)}
                          title="Investigate usage"
                          aria-label={`Investigate photo ${item.photo_library_id}`}
                        >
                          🔎
                        </button>
                        <button type="button" className="ghost" onClick={() => handleLibrarySave(item)}>
                          Save
                        </button>
                        <a
                          className="ghost"
                          href={buildAdminImageUrl(
                            item.raw_rel_path || item.rel_path || item.image_url,
                            item.updated_at || null
                          )}
                          download
                        >
                          Download
                        </a>
                        <button type="button" className="ghost danger" onClick={() => handleLibraryDelete(item)}>
                          Delete
                        </button>
                        {groupId && groupId !== "__ungrouped__" && !isClientGroupId(groupId) && (
                          inGroup(item.photo_library_id) ? (
                            <button
                              type="button"
                              className="ghost danger"
                              onClick={() => handleRemoveFromGroup(item.photo_library_id)}
                            >
                              Remove
                            </button>
                          ) : (
                            <button
                              type="button"
                              className="ghost"
                              onClick={() => handleAddToGroup(item.photo_library_id)}
                            >
                              Add
                            </button>
                          )
                        )}
                      </div>
                      <label className="admin-photo-library__replace">
                        Replace
                        <input
                          type="file"
                          accept="image/*"
                          onChange={(e) =>
                            handleReplaceFileChange(
                              item.photo_library_id,
                              (e.target.files && e.target.files[0]) || null
                            )
                          }
                        />
                        <span className="admin-photo-library__replace-name">
                          {replaceFiles[item.photo_library_id]?.name || "No file chosen"}
                        </span>
                      </label>
                    </td>
                  </tr>
                ))}
                {!items.length && (
                  <tr>
                    <td colSpan={7} className="admin-photo-library__empty">No photos yet.</td>
                  </tr>
                )}
              </tbody>
            </table>
          </div>
        )}
      </section>

      {previewUrl && (
        <div
          className="admin-photo-library__preview"
          role="button"
          tabIndex={0}
          onClick={() => setPreviewUrl("")}
          onKeyDown={(e) => {
            if (e.key === "Escape") setPreviewUrl("");
          }}
        >
          <img src={previewUrl} alt="" />
        </div>
      )}

      <ModalDialog
        open={Boolean(usageModal)}
        title={`Photo #${usageModal?.item?.photo_library_id || "?"}`}
        subtitle={usageModal?.item?.title || ""}
        onClose={() => setUsageModal(null)}
      >
        {usageModal?.usages?.length ? (
          <ul className="admin-photo-library__usage-list">
            {usageModal.usages.map((usage, index) => (
              <li key={`${usage.usage_type || "usage"}-${usage.ref_id || index}-${index}`}>
                <div>{usage?.label || "Unknown usage"}</div>
                {usage?.detail ? (
                  <div className="admin-photo-library__usage-detail">{usage.detail}</div>
                ) : null}
              </li>
            ))}
          </ul>
        ) : (
          <div className="admin-photo-library__usage-empty">Not currently used anywhere.</div>
        )}
      </ModalDialog>

      <ClientPickerModal
        open={clientPickerOpen}
        onClose={() => {
          setClientPickerOpen(false);
          setClientTargetPhotoId(null);
        }}
        onPick={handleClientPicked}
      />
      <SavedPaletteEditorModal
        open={paletteModalOpen}
        paletteId={paletteTarget?.paletteId || null}
        attachment={paletteTarget?.attachment || null}
        onChanged={() => {
          setRefreshKey((prev) => prev + 1);
        }}
        onClose={() => {
          setPaletteModalOpen(false);
          setPaletteTarget(null);
        }}
        onSaved={({ palette }) => {
          setPaletteModalOpen(false);
          setPaletteTarget(null);
          if (palette) {
            setSavedPalettes((prev) => {
              const next = prev.filter((item) => String(item.id) !== String(palette.id));
              return [palette, ...next];
            });
          }
          setRefreshKey((prev) => prev + 1);
        }}
      />
    </div>
  );
}

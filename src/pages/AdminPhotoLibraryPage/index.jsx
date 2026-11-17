import PhotoPreviewImage from "@components/PhotoPreview/PhotoPreviewImage";
import PhotoPreview from "@components/PhotoPreview";
import { photoImageProps } from "@helpers/imageThumb";
import { useEffect, useMemo, useRef, useState } from "react";
import { API_FOLDER } from "@helpers/config";
import {
  buildImageUrl,
  getImageRefreshEnabled,
  setImageRefreshEnabled,
} from "@helpers/assetImage";
import ClientPickerModal from "@components/ClientPickerModal";
import ModalDialog from "@components/ModalDialog";
import SavedPaletteEditorModal from "@components/SavedPaletteEditorModal";
import PhotoCropDialog from "@components/PhotoCropDialog/PhotoCropDialog";
import ArtworkDialog from "@components/ArtworkDialog/ArtworkDialog";
import {
  AdminMasterDetail, AdminListPane, AdminObjectList, AdminObjectListItem,
  AdminDetailPane, AdminPanel, AdminField, AdminButton, AdminToolbar,
  AdminEmptyState, AdminNotice, AdminSearchField, AdminDialog,
} from "@components/AdminLayout";
import PhotoDetailEditor from "./PhotoDetailEditor";
import PhotoEditorialDialog from "@components/PhotoEditorialDialog";
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
  { value: "art", label: "Art" },
  { value: "art_inspired", label: "Art Inspired" },
  { value: "inspiration", label: "Inspiration" },
];

const defaultUpload = {
  source_type: "saved_palette",
  palette_id: "",

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

function openSavedPaletteViewerSetup(paletteId, viewerId = null) {
  if (!Number(paletteId)) return;
  const params = new URLSearchParams();
  if (Number(viewerId) > 0) params.set('pv_id', String(viewerId));
  else params.set('palette_id', String(paletteId));
  window.location.href = `/admin/palette-viewers?${params}`;
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
  const [selectedId, setSelectedId] = useState(null);
  const [pendingSelection, setPendingSelection] = useState(null);
  const [saving, setSaving] = useState(false);
  const [saveStatus, setSaveStatus] = useState("");
  const [pageOffset, setPageOffset] = useState(0);
  const [hasMore, setHasMore] = useState(false);
  const originals = useRef({});
  const drafts = useRef({});
  const records = useRef({});
  const [dirtyIds, setDirtyIds] = useState(() => new Set());
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState("");
  const [refreshKey, setRefreshKey] = useState(0);
  const [previewUrl, setPreviewUrl] = useState("");
  const [cropTarget, setCropTarget] = useState(null);
  const [artworkTarget, setArtworkTarget] = useState(null);
  const [editorialTarget, setEditorialTarget] = useState(null);
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
        params.set("limit", "50");
        params.set("offset", String(pageOffset));
        params.set("_", Date.now().toString());
        const url = `${LIST_URL}?${params.toString()}`;
        const res = await fetch(url, { credentials: "include" });
        const data = await parseJsonResponse(res, url);
        if (!res.ok || !data?.ok) throw new Error(data?.error || "Failed to load photo library");
        if (!active) return;
        const rows = Array.isArray(data.items) ? data.items : [];
        rows.forEach((row) => { records.current[row.photo_library_id] = row; });
        setHasMore(rows.length === 50);
        setItems((previous) => {
          const merged = pageOffset ? [...previous, ...rows] : rows;
          return [...new Map(merged.map((row) => [row.photo_library_id,
            drafts.current[row.photo_library_id] || row])).values()];
        });
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
  }, [filters.q, filters.source_type, filters.palette_id, filters.sort, filters.include_inactive, filters.missing_tags, photoLibraryIdsFilter, refreshKey, pageOffset]);

  const hasLibrarySearch = hasActiveLibrarySearch(filters, photoLibraryIdsFilter);

  const buildAdminImageUrl = (url, updatedAt = null) => {
    const base = buildImageUrl(url, updatedAt, imageRefreshEnabled);
    if (!base) return "";
    const sep = base.includes("?") ? "&" : "?";
    return base;
  };

  const handleUploadField = (key, value) => {
    setUploadForm((prev) => ({
      ...prev, [key]: value,
      // Switching from another upload type must not carry Gallery consent
      // into a new artwork. The user can explicitly enable it afterward.
      ...(key === "source_type" && value === "art" ? { show_in_gallery: false } : {}),
    }));
  };

  const handleLibraryField = (id, key, value) => {
    const current = drafts.current[id] || items.find((item) => item.photo_library_id === id) || records.current[id];
    if (!current) return;
    if (!originals.current[id]) originals.current[id] = { ...current };
    drafts.current[id] = {
      ...current,
      ...(key === "palette_id" ? { palette_changed: true,
        expected_palette_id: current.palette_changed ? current.expected_palette_id : current.palette_id } : {}),
      [key]: value,
    };
    setItems((prev) => prev.map((item) => item.photo_library_id === id ? drafts.current[id] : item));
    setDirtyIds((prev) => {
      const next = new Set(prev);
      next.add(id);
      return next;
    });
  };

  const patchLibraryItem = (id, fields) => {
    Object.entries(fields).forEach(([key, value]) => handleLibraryField(id, key, value));
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
      throw err;
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


        formData.append("photo_type", "full");
        if (uploadForm.show_in_gallery) formData.append("show_in_gallery", "1");
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
      resetUpload();
      setUploadStatus({ error: "", success: "Upload complete." });
      setRefreshKey((prev) => prev + 1);
    } catch (err) {
      setUploadStatus({ error: err?.message || "Upload failed", success: "" });
    } finally {
      setUploading(false);
    }
  };

  const handleLibrarySave = async (item) => {
    if (saving) return false;
    setSaving(true);
    setSaveStatus("");
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
        ...(item.palette_owned_by_library && item.palette_changed
          ? { palette_id: item.palette_id, expected_palette_id: item.expected_palette_id } : {}),
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
      records.current[item.photo_library_id] = { ...item, palette_changed: false };
      setItems((previous) => previous.map((row) => row.photo_library_id === item.photo_library_id ? records.current[item.photo_library_id] : row));
      delete drafts.current[item.photo_library_id];
      delete originals.current[item.photo_library_id];
      setRefreshKey((prev) => prev + 1);
      setSaveStatus("Photo saved.");
      setDirtyIds((prev) => {
        const next = new Set(prev);
        next.delete(item.photo_library_id);
        return next;
      });
      return true;
    } catch (err) {
      setError(err?.message || "Failed to save photo");
      return false;
    } finally {
      setSaving(false);
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
      delete drafts.current[item.photo_library_id];
      delete originals.current[item.photo_library_id];
      delete records.current[item.photo_library_id];
      setSelectedId(null);
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

  const selectedPhoto = drafts.current[selectedId] || items.find((item) => item.photo_library_id === selectedId) || records.current[selectedId];
  const selectedDirty = Boolean(dirtyIds.has(selectedId) || replaceFiles[selectedId]);

  function discardPhoto(id) {
    const original = originals.current[id] || records.current[id];
    delete drafts.current[id];
    delete originals.current[id];
    setItems((previous) => previous.map((item) => item.photo_library_id === id ? original || item : item));
    setDirtyIds((previous) => { const next = new Set(previous); next.delete(id); return next; });
    setReplaceFiles((previous) => { const next = { ...previous }; delete next[id]; return next; });
    setError("");
  }

  function selectPhoto(id) {
    if (saving || id === selectedId) return;
    if (selectedDirty) { setPendingSelection(id); return; }
    setSelectedId(id);
    setSaveStatus("");
    setError("");
  }

  useEffect(() => {
    setPageOffset(0);
  }, [filters, photoLibraryIdsFilter]);

  useEffect(() => {
    function warnOnLeave(event) {
      if (!dirtyIds.size && !Object.values(replaceFiles).some(Boolean)) return;
      event.preventDefault();
      event.returnValue = "";
    }
    window.addEventListener("beforeunload", warnOnLeave);
    return () => window.removeEventListener("beforeunload", warnOnLeave);
  }, [dirtyIds, replaceFiles]);

  return (
    <div className="admin-photo-library admin-photo-library--master-detail">
      <AdminMasterDetail
        storageKey="admin-photo-library-list-width"
        defaultListWidth={360} minListWidth={280} maxListWidth={560}
        selectedId={selectedId}
        list={
          <AdminListPane title="Photo Library" actions={
            <AdminButton type="button" onClick={() => toggleSection("upload")}>+ Upload</AdminButton>
          } toolbar={
            <div className="admin-photo-library__list-controls">
              <form onSubmit={(event) => { event.preventDefault(); setPageOffset(0); setFilters((previous) => ({ ...previous, q: searchInput.trim() })); }}>
                <AdminSearchField value={searchInput} onChange={setSearchInput} placeholder="Search tags, title, or #ID…" />
                <AdminToolbar>
                  <AdminButton type="submit">Search</AdminButton>
                  <AdminButton type="button" variant="secondary" aria-expanded={mobileFiltersOpen} onClick={() => setMobileFiltersOpen((open) => !open)}>Filters {mobileFiltersOpen ? "−" : "+"}</AdminButton>
                </AdminToolbar>
              </form>
              {mobileFiltersOpen && <div className="admin-photo-library__sidebar-filters">            <div className="admin-photo-library__filters-advanced-actions">
              <AdminButton
                type="button"
                variant="secondary" className="ghost"
                onClick={() => {
                  setSearchInput("");
                  setPhotoLibraryIdsFilter("");
                  setFilters((prev) => ({ ...prev, q: "" }));
                }}
              >
                Clear Search
              </AdminButton>
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
                <AdminButton type="button" onClick={handleCreateGroup} disabled={!newGroupTitle.trim()}>
                  Add Group
                </AdminButton>
              </div>
              {groupId && !isClientGroupId(groupId) && (
                <AdminButton
                  type="button"
                  variant="secondary" className="ghost"
                  onClick={() => setGroupFilterMode((prev) => (prev === "group" ? "all" : "group"))}
                >
                  {groupFilterMode === "group" ? "Show All To Add" : "Show Group Only"}
                </AdminButton>
              )}
              <AdminButton type="button" variant="secondary" className="ghost danger" onClick={handleDeleteGroup} disabled={!groupId || isClientGroupId(groupId)}>
                Delete Group
              </AdminButton>
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
                <option value="progression">Progression</option>
                <option value="client">Client</option>
                <option value="article">Article</option>
                <option value="pin">Pin</option>
                <option value="art">Art</option>
                <option value="art_inspired">Art Inspired</option>
                <option value="inspiration">Inspiration</option>
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
              <AdminButton type="button" variant="secondary" className="ghost" onClick={toggleImageRefresh}>
                {imageRefreshEnabled ? "Image Refresh: On" : "Image Refresh: Off"}
              </AdminButton>
              <div className="admin-photo-library__save-all">
                <a className="admin-photo-library__tools-link" href="/admin/photo-library-tools">
                  Library Tools
                </a>

              </div>
            </div></div>}
              {hasLibrarySearch && <span className="admin-meta-text">{filteredItems.length} photos loaded</span>}
            </div>
          }>
            {!hasLibrarySearch ? <AdminEmptyState title="Search your photos" message="Enter a tag, title, or photo ID to find photos." /> : null}
            {loading && <AdminNotice>Loading photos…</AdminNotice>}
            {hasLibrarySearch && !loading && !filteredItems.length && <AdminEmptyState title="No matching photos" message="Try another search or change the filters." />}
            <AdminObjectList ariaLabel="Photos">
              {filteredItems.map((item) => <AdminObjectListItem
                key={item.photo_library_id} id={item.photo_library_id}
                title={item.title || item.filename || "Untitled photo"}
                thumbnail={<PhotoPreviewImage {...photoImageProps(item.photo_library_id, item.raw_rel_path || item.rel_path || item.image_url, { width: 160 })} alt="" loading="lazy" />}
                meta={[item.tags, item.is_inactive ? "Retired" : null]}
                selected={selectedId === item.photo_library_id}
                onSelect={() => selectPhoto(item.photo_library_id)}
              />)}
            </AdminObjectList>
            {hasMore && hasLibrarySearch && <AdminButton type="button" variant="secondary" disabled={loading} onClick={() => setPageOffset((offset) => offset + 50)}>Load more</AdminButton>}
          </AdminListPane>
        }
        detail={
          <AdminDetailPane title={selectedPhoto?.title || (selectedPhoto ? "Untitled photo" : "Photo details")}
            meta={selectedPhoto ? `#${selectedId}${selectedDirty ? " · Unsaved changes" : ""}` : null}
            actions={selectedPhoto && <AdminToolbar>
              <AdminButton type="button" disabled={saving || !selectedDirty} onClick={() => handleLibrarySave(selectedPhoto)}>{saving ? "Saving…" : "Save"}</AdminButton>
              <AdminButton type="button" variant="secondary" disabled={saving || !selectedDirty} onClick={() => discardPhoto(selectedId)}>Cancel</AdminButton>
            </AdminToolbar>}>
            {error && <AdminNotice variant="danger">{error}</AdminNotice>}
            {saveStatus && <AdminNotice>{saveStatus}</AdminNotice>}
            {replaceStatus && <AdminNotice>{replaceStatus}</AdminNotice>}
            {selectedPhoto ? (() => { const item = selectedPhoto; return (<PhotoDetailEditor saving={saving} onSave={() => handleLibrarySave(selectedPhoto)}>
<AdminPanel title="Photo">
                      <div className="admin-photo-library__id">
                        <span>{item.photo_library_id}</span>
                        <AdminButton
                          type="button"
                          variant="secondary" className="ghost admin-photo-library__copy"
                          onClick={() => navigator.clipboard.writeText(String(item.photo_library_id))}
                        >
                          Copy
                        </AdminButton>
                        <AdminButton
                          type="button"
                          variant="secondary"
                          onClick={() => setEditorialTarget({
                            photoLibraryId: item.photo_library_id,
                            imageUrl: buildAdminImageUrl(item.raw_rel_path || item.rel_path || item.image_url, item.updated_at || null),
                          })}
                        >
                          Editorial
                        </AdminButton>
                        <AdminButton
                          type="button"
                          variant="secondary" className="ghost admin-photo-library__crop-button"
                          title={`Edit crop for photo #${item.photo_library_id}`}
                          aria-label={`Edit crop for photo #${item.photo_library_id}`}
                          onClick={() => setCropTarget({
                            photoLibraryId: item.photo_library_id,
                            imageUrl: buildAdminImageUrl(
                              item.raw_rel_path || item.rel_path || item.image_url,
                              item.updated_at || null
                            ),
                          })}
                        >
                          <svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                            <path d="M6 2v14a2 2 0 0 0 2 2h14" />
                            <path d="M18 22V8a2 2 0 0 0-2-2H2" />
                          </svg>
                        </AdminButton>
                      </div>

                      {(() => {
                        const thumbSrc = buildAdminImageUrl(
                          item.raw_rel_path || item.rel_path || item.image_url,
                          item.updated_at || null
                        );
                        const thumbState = getThumbState(item.photo_library_id);
                        return (
                      <AdminButton
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
                      </AdminButton>
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
                        <AdminButton
                          type="button"
                          variant="secondary" className="ghost admin-photo-library__thumb-action"
                          onClick={() => togglePath(item.photo_library_id)}
                        >
                          {expandedPathIds.has(item.photo_library_id) ? "Hide path" : "Path"}
                        </AdminButton>
                        <AdminButton
                          type="button"
                          variant="secondary" className="ghost admin-photo-library__thumb-action"
                          onClick={() => navigator.clipboard.writeText(item.raw_rel_path || item.rel_path || "")}
                        >
                          Copy path
                        </AdminButton>
                      </div>
                      {expandedPathIds.has(item.photo_library_id) && (
                        <div className="admin-photo-library__thumb-path">
                          {item.raw_rel_path || item.rel_path || ""}
                        </div>
                      )}
                    </AdminPanel>
<AdminPanel title="Photo information">
                      <div className="admin-photo-library__text-stack">
                        <AdminField label="Title"><input
                          type="text"
                          value={item.title || ""}
                          onChange={(e) => handleLibraryField(item.photo_library_id, "title", e.target.value)}
                          placeholder="title"
                        /></AdminField>
                        <AdminField label="Tags"><input
                          type="text"
                          value={item.tags || ""}
                          onChange={(e) => handleLibraryField(item.photo_library_id, "tags", e.target.value)}
                          placeholder="tags"
                        /></AdminField>
                        <AdminField label="Alt text"><input
                          type="text"
                          value={item.alt_text || ""}
                          onChange={(e) => handleLibraryField(item.photo_library_id, "alt_text", e.target.value)}
                          placeholder="alt text"
                        /></AdminField>
                        {formatAiAltStatus(item) && (
                          <div className="admin-photo-library__ai-alt-status">
                            {formatAiAltStatus(item)}
                            {item.ai_filename_slug ? ` · ${item.ai_filename_slug}` : ""}
                          </div>
                        )}
                        <AdminField label="Notes"><input
                          type="text"
                          value={item.note || ""}
                          onChange={(e) => handleLibraryField(item.photo_library_id, "note", e.target.value)}
                          placeholder="note"
                        /></AdminField>
                        {item.source_type === "client" && (
                          <>
                            <AdminField label="Client name"><input
                              type="text"
                              value={item.client_name || ""}
                              onChange={(e) => handleLibraryField(item.photo_library_id, "client_name", e.target.value)}
                              placeholder="client name"
                            /></AdminField>
                          </>
                        )}
                      </div>
                    </AdminPanel>
<AdminPanel title="Source and client">
                      <div className="admin-photo-library__meta">
                        <select
                          className="admin-photo-library__source-type"
                          aria-label={`Type for photo #${item.photo_library_id}`}
                          value={item.source_type || ""}
                          onChange={(e) => handleLibraryField(item.photo_library_id, "source_type", e.target.value)}
                        >
                          {!SOURCE_OPTIONS.some((option) => option.value === item.source_type) && (
                            <option value={item.source_type || ""}>
                              {item.source_type || "Unknown"} (legacy)
                            </option>
                          )}
                          {SOURCE_OPTIONS.map((option) => (
                            <option key={option.value} value={option.value}>{option.label}</option>
                          ))}
                        </select>
                        {item.source_type === "art" && (
                          <AdminButton
                            type="button"
                            variant="secondary" className="ghost admin-photo-library__artwork-button"
                            title={`Add or edit artwork information for photo #${item.photo_library_id}`}
                            aria-label={`Add or edit artwork information for photo #${item.photo_library_id}`}
                            onClick={() => setArtworkTarget({
                              photoLibraryId: item.photo_library_id,
                              imageUrl: buildAdminImageUrl(
                                item.raw_rel_path || item.rel_path || item.image_url,
                                item.updated_at || null
                              ),
                            })}
                          >
                            <svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinejoin="round" aria-hidden="true">
                              <rect x="2.5" y="3" width="19" height="18" rx="1.5" />
                              <path d="M6 17l4.5-5 3.5 3 2.4-2.5L20 17" />
                              <circle cx="8.5" cy="8" r="1.5" />
                            </svg>
                          </AdminButton>
                        )}
                        {item.source_id && <div className="muted">#{item.source_id}</div>}
                        {item.client_name ? <div className="muted">Client: {item.client_name}</div> : null}
                      </div>
                    </AdminPanel>
<AdminPanel title="Palette">
                      <div className="admin-photo-library__meta">
                        {item.palette_owned_by_library ? (
                          <select
                            aria-label={`Palette for photo ${item.photo_library_id}`}
                            value={item.palette_id || ""}
                            onChange={(event) => handleLibraryField(item.photo_library_id, "palette_id", Number(event.target.value) || null)}
                          >
                            <option value="">No palette</option>
                            {item.palette_id && !paletteOptions.some((palette) => Number(palette.id) === Number(item.palette_id)) && (
                              <option value={item.palette_id}>{item.attached_saved_palette_label || `Palette #${item.palette_id}`}</option>
                            )}
                            {paletteOptions.map((palette) => <option key={palette.id} value={palette.id}>{palette.label}</option>)}
                          </select>
                        ) : item.attached_saved_palette_id ? (
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
                                      ? `${item.attached_saved_palette_label || `Saved #${item.attached_saved_palette_id}`} | ${"Main"}`
                                      : `${"Main"} | ${typeLabel}`}
                                  </div>
                                </>
                              );
                            })()}
                            <div className="admin-photo-library__palette-actions">
                              <AdminButton
                                type="button"
                                variant="secondary" className="ghost"
                                onClick={() => openPaletteModalForItem(item)}
                              >
                                Link…
                              </AdminButton>
                              <AdminButton
                                type="button"
                                variant="secondary" className="ghost"
                                onClick={() => openSavedPaletteViewerSetup(
                                  item.attached_saved_palette_id,
                                  item.attached_palette_viewer_id,
                                  item.photo_library_id
                                )}
                              >
                                Viewer
                              </AdminButton>
                            </div>
                          </>
                        ) : (
                          <>
                            <div className="muted">No palette attached</div>
                            <div className="admin-photo-library__palette-actions">
                              <AdminButton
                                type="button"
                                variant="secondary" className="ghost"
                                onClick={() => openPaletteModalForItem(item)}
                              >
                                Attach
                              </AdminButton>
                            </div>
                          </>
                        )}
                      </div>
                    </AdminPanel>
<AdminPanel title="Visibility and permission">
                      <div className="admin-photo-library__flags">
                        <label className="admin-photo-library__check">
                          <input
                            type="checkbox"
                            checked={!!item.show_in_gallery}
                            onChange={(e) => handleLibraryField(item.photo_library_id, "show_in_gallery", e.target.checked)}
                          />
                          Gallery
                        </label>
                        {!item.palette_owned_by_library && <label className="admin-photo-library__check">
                          <input
                            type="checkbox"
                            checked={!!item.has_palette}
                            onChange={(e) => handleLibraryField(item.photo_library_id, "has_palette", e.target.checked)}
                          />
                          Palette
                        </label>}
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
                    </AdminPanel>
<AdminPanel title="Photo actions">
                      <div className="admin-photo-library__row-actions-top">
                        <AdminButton
                          type="button"
                          variant="secondary" className="ghost"
                          onClick={() => {
                            setClientTargetPhotoId(item.photo_library_id);
                            setClientPickerOpen(true);
                          }}
                        >
                          Client…
                        </AdminButton>
                        <AdminButton
                          type="button"
                          variant="secondary" className="ghost admin-photo-library__inspect"
                          onClick={() => handleInvestigate(item)}
                          title="Investigate usage"
                          aria-label={`Investigate photo ${item.photo_library_id}`}
                        >
                          🔎
                        </AdminButton>

                        <a
                          variant="secondary" className="ghost"
                          href={buildAdminImageUrl(
                            item.raw_rel_path || item.rel_path || item.image_url,
                            item.updated_at || null
                          )}
                          download
                        >
                          Download
                        </a>
                        <AdminButton type="button" variant="secondary" className="ghost danger" onClick={() => handleLibraryDelete(item)}>
                          Delete
                        </AdminButton>
                        {groupId && groupId !== "__ungrouped__" && !isClientGroupId(groupId) && (
                          inGroup(item.photo_library_id) ? (
                            <AdminButton
                              type="button"
                              variant="secondary" className="ghost danger"
                              onClick={() => handleRemoveFromGroup(item.photo_library_id)}
                            >
                              Remove
                            </AdminButton>
                          ) : (
                            <AdminButton
                              type="button"
                              variant="secondary" className="ghost"
                              onClick={() => handleAddToGroup(item.photo_library_id)}
                            >
                              Add
                            </AdminButton>
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
                    </AdminPanel>
</PhotoDetailEditor>); })() : <AdminEmptyState title="Select a photo" message="Choose a thumbnail on the left to edit its details." />}
          </AdminDetailPane>
        }
      />
      <AdminDialog open={pendingSelection !== null} title="Unsaved photo changes" message="Save your changes before selecting another photo?" onClose={() => { if (!saving) setPendingSelection(null); }} actions={
        <AdminToolbar>
          <AdminButton disabled={saving} onClick={async () => { if (await handleLibrarySave(selectedPhoto)) { setSelectedId(pendingSelection); setPendingSelection(null); } }}>Save and continue</AdminButton>
          <AdminButton variant="secondary" disabled={saving} onClick={() => { discardPhoto(selectedId); setSelectedId(pendingSelection); setPendingSelection(null); }}>Discard</AdminButton>
          <AdminButton variant="secondary" disabled={saving} onClick={() => setPendingSelection(null)}>Stay</AdminButton>
        </AdminToolbar>
      }>{error && <AdminNotice variant="danger">{error}</AdminNotice>}</AdminDialog>
      <AdminDialog open={expandedSections.upload} title="Upload photos" width={760} onClose={() => { if (!uploading) toggleSection("upload"); }} actions={<AdminButton type="button" variant="secondary" disabled={uploading} onClick={() => toggleSection("upload")}>Close</AdminButton>}>
        {uploadStatus.error && <AdminNotice variant="danger">{uploadStatus.error}</AdminNotice>}
        {uploadStatus.success && <AdminNotice>{uploadStatus.success}</AdminNotice>}
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
                <AdminButton
                  type="button"
                  variant="secondary" className="ghost"
                  onClick={() => setClientPickerOpen(true)}
                >
                  Client…
                </AdminButton>
                {uploadForm.client_id ? (
                  <AdminButton
                    type="button"
                    variant="secondary" className="ghost"
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
                  </AdminButton>
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
              multiple={!showSavedFields}
              onChange={(e) => setFiles(Array.from(e.target.files || []))}
              disabled={uploading}
            />
          </label>

              <div className="admin-photo-library__upload-actions">
                <AdminButton type="submit" disabled={uploading}>
                  {uploading ? "Uploading…" : "Upload"}
                </AdminButton>
                <AdminButton type="button" variant="secondary" className="ghost" onClick={resetUpload} disabled={uploading}>
                  Clear
                </AdminButton>
              </div>
            </form>
      </AdminDialog>

      <PhotoEditorialDialog
        open={Boolean(editorialTarget)}
        photoLibraryId={editorialTarget?.photoLibraryId ?? null}
        imageUrl={editorialTarget?.imageUrl || ""}
        onClose={() => setEditorialTarget(null)}
      />

      <ArtworkDialog
        open={Boolean(artworkTarget)}
        photoLibraryId={artworkTarget?.photoLibraryId ?? null}
        imageUrl={artworkTarget?.imageUrl || ""}
        onClose={() => setArtworkTarget(null)}
      />

      <PhotoCropDialog
        open={Boolean(cropTarget)}
        photoLibraryId={cropTarget?.photoLibraryId ?? null}
        imageUrl={cropTarget?.imageUrl || ""}
        cropType="swatch"
        onClose={() => setCropTarget(null)}
        onSaved={() => setRefreshKey((previous) => previous + 1)}
      />

      <PhotoPreview src={previewUrl} onClose={() => setPreviewUrl("")} />

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

import { useEffect, useMemo, useState } from "react";
import { API_FOLDER } from "@helpers/config";
import ClientPickerModal from "@components/ClientPickerModal";
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
const BACKFILL_EXTERIORS_URL = `${API_FOLDER}/v2/admin/photo-library/backfill-exteriors.php`;
const GROUPS_LIST_URL = `${API_FOLDER}/v2/admin/photo-groups/list.php`;
const GROUPS_CREATE_URL = `${API_FOLDER}/v2/admin/photo-groups/create.php`;
const GROUPS_DELETE_URL = `${API_FOLDER}/v2/admin/photo-groups/delete.php`;
const GROUPS_ITEMS_URL = `${API_FOLDER}/v2/admin/photo-groups/items.php`;
const GROUPS_ITEMS_ALL_URL = `${API_FOLDER}/v2/admin/photo-groups/items-all.php`;
const GROUPS_ADD_URL = `${API_FOLDER}/v2/admin/photo-groups/add-item.php`;
const GROUPS_REMOVE_URL = `${API_FOLDER}/v2/admin/photo-groups/remove-item.php`;

const SOURCE_OPTIONS = [
  { value: "saved_palette", label: "Saved Palette" },
  { value: "applied_palette", label: "Applied Palette" },
  { value: "progression", label: "Progression" },
  { value: "client", label: "Client" },
  { value: "article", label: "Article" },
  { value: "pin", label: "Pin" },
];

const defaultUpload = {
  source_type: "saved_palette",
  palette_id: "",
  series: "",
  title_prefix: "",
  client_name: "",
  client_email: "",
  client_id: "",
  tags: "",
  alt_text: "",
  show_in_gallery: false,
  has_palette: false,
};

const defaultFilters = {
  q: "",
  source_type: "",
  palette_id: "",
};

const normalizeTagToken = (token) => {
  const value = String(token || "").trim().toLowerCase();
  if (!value) return "";
  if (value === "cyan" || value === "teal") return "teal";
  return value;
};

const parseTagTokens = (value) =>
  String(value || "")
    .split(",")
    .map((token) => normalizeTagToken(token))
    .filter(Boolean);

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

export default function AdminPhotoLibraryPage() {
  const [uploadForm, setUploadForm] = useState(defaultUpload);
  const [uploading, setUploading] = useState(false);
  const [uploadStatus, setUploadStatus] = useState({ error: "", success: "" });
  const [replaceStatus, setReplaceStatus] = useState("");
  const [files, setFiles] = useState([]);

  const [filters, setFilters] = useState(defaultFilters);
  const [searchInput, setSearchInput] = useState("");
  const [items, setItems] = useState([]);
  const [dirtyIds, setDirtyIds] = useState(() => new Set());
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState("");
  const [refreshKey, setRefreshKey] = useState(0);
  const [previewUrl, setPreviewUrl] = useState("");
  const [replaceFiles, setReplaceFiles] = useState({});
  const [replacingId, setReplacingId] = useState(null);
  const [backfillStatus, setBackfillStatus] = useState("");
  const [expandedPathIds, setExpandedPathIds] = useState(() => new Set());

  const [savedPalettes, setSavedPalettes] = useState([]);
  const [clients, setClients] = useState([]);
  const [clientPickerOpen, setClientPickerOpen] = useState(false);
  const [clientTargetPhotoId, setClientTargetPhotoId] = useState(null);
  const [groups, setGroups] = useState([]);
  const [groupId, setGroupId] = useState("");
  const [groupItems, setGroupItems] = useState(() => new Set());
  const [groupedItems, setGroupedItems] = useState(() => new Set());
  const [newGroupTitle, setNewGroupTitle] = useState("");
  const [groupStatus, setGroupStatus] = useState("");
  const [groupFilterMode, setGroupFilterMode] = useState("group");

  useEffect(() => {
    let active = true;
    async function loadSavedPalettes() {
      try {
        const params = new URLSearchParams();
        params.set("limit", "200");
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
    return savedPalettes.map((palette) => ({
      id: palette.id,
      label: palette.nickname || palette.palette_hash || `Saved #${palette.id}`,
    }));
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
      setLoading(true);
      setError("");
      try {
        const params = new URLSearchParams();
        if (filters.q) params.set("q", filters.q);
        if (filters.source_type) params.set("source_type", filters.source_type);
        if (filters.palette_id) params.set("palette_id", filters.palette_id);
        params.set("limit", "200");
        params.set("_", Date.now().toString());
        const res = await fetch(`${LIST_URL}?${params.toString()}`, { credentials: "include" });
        const data = await res.json();
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
  }, [filters.q, filters.source_type, filters.palette_id, refreshKey]);

  const handleUploadField = (key, value) => {
    setUploadForm((prev) => ({ ...prev, [key]: value }));
  };

  const handleClientSelect = (value) => {
    if (!value) {
      setUploadForm((prev) => ({
        ...prev,
        client_id: "",
        client_name: "",
        client_email: "",
      }));
      return;
    }
    if (value === "__new__") {
      setUploadForm((prev) => ({
        ...prev,
        client_id: "__new__",
        client_name: "",
        client_email: "",
      }));
      return;
    }
    const selected = clients.find((client) => String(client.id) === String(value));
    if (!selected) return;
    setUploadForm((prev) => ({
      ...prev,
      client_id: String(selected.id),
      client_name: selected.name || "",
      client_email: selected.email || "",
    }));
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

  const withCacheBuster = (src, updatedAt) => {
    if (!src || !updatedAt) return src;
    const sep = src.includes("?") ? "&" : "?";
    const stamp = Date.parse(updatedAt);
    if (!Number.isFinite(stamp)) return src;
    return `${src}${sep}v=${stamp}`;
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
    setReplacingId(id);
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
    } finally {
      setReplacingId(null);
    }
  };

  const resetUpload = () => {
    setUploadForm(defaultUpload);
    setFiles([]);
    setUploadStatus({ error: "", success: "" });
  };

  const handleUploadSubmit = async (event) => {
    event.preventDefault();
    if (!files.length) {
      setUploadStatus({ error: "Select at least one photo.", success: "" });
      return;
    }
    if (uploadForm.source_type === "applied_palette") {
      setUploadStatus({ error: "Applied palette photos are auto-synced; no upload needed.", success: "" });
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
        show_in_gallery: !!item.show_in_gallery,
        has_palette: !!item.has_palette,
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
          show_in_gallery: !!item.show_in_gallery,
          has_palette: !!item.has_palette,
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

  const handleBackfill = async () => {
    if (!window.confirm("Scan /photos/exteriors/*/*/prepared/base.jpg and add to Photo Library?")) return;
    setBackfillStatus("");
    setError("");
    try {
      const res = await fetch(BACKFILL_EXTERIORS_URL, { method: "POST", credentials: "include" });
      const data = await res.json();
      if (!res.ok || !data?.ok) throw new Error(data?.error || "Backfill failed");
      setBackfillStatus(`Added ${data.added} (skipped ${data.skipped}).`);
      setRefreshKey((prev) => prev + 1);
    } catch (err) {
      setBackfillStatus(err?.message || "Backfill failed");
    }
  };

  const showSavedFields = uploadForm.source_type === "saved_palette";
  const showLibraryFields = uploadForm.source_type !== "saved_palette" && uploadForm.source_type !== "applied_palette";
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
    return items.filter((item) => inGroup(item.photo_library_id));
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

  return (
    <div className="admin-photo-library">
      <header className="admin-photo-library__header">
        <h1>Photo Library</h1>
        <p>Upload and tag photos for playlists, progressions, or saved palettes.</p>
      </header>

      <section className="admin-photo-library__section">
        <h2>Upload Photos</h2>
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
                onChange={(e) => handleUploadField("palette_id", e.target.value)}
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
                <select
                  value={uploadForm.client_id}
                  onChange={(e) => handleClientSelect(e.target.value)}
                >
                  <option value="">Pick client</option>
                  {clients.map((client) => (
                    <option key={client.id} value={client.id}>
                      {client.name ? `${client.name} (${client.email})` : client.email}
                    </option>
                  ))}
                </select>
                <button
                  type="button"
                  className="ghost"
                  onClick={() => setClientPickerOpen(true)}
                >
                  Client…
                </button>
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
              <input
                type="text"
                value={uploadForm.series}
                onChange={(e) => handleUploadField("series", e.target.value)}
                placeholder={uploadSeriesPlaceholder}
              />
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

          {showLibraryFields && (
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

          {showLibraryFields && (
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
        {uploadForm.source_type === "applied_palette" && (
          <div className="admin-photo-library__hint">
            Applied palette photos are auto-synced when a render is cached.
          </div>
        )}
      </section>

      <section className="admin-photo-library__section">
        <h2>Library</h2>
        {error && <div className="admin-photo-library__error">{error}</div>}
        {backfillStatus && <div className="admin-photo-library__status">{backfillStatus}</div>}
        <div className="admin-photo-library__filters">
          <form
            className="admin-photo-library__tag-filter"
            onSubmit={(e) => {
              e.preventDefault();
              setFilters((prev) => ({ ...prev, q: searchInput.trim() }));
            }}
          >
            <label>
              Search
              <input
                type="text"
                value={searchInput}
                onChange={(e) => setSearchInput(e.target.value)}
                placeholder="title, tags, path, or photo id"
              />
            </label>
            <div className="admin-photo-library__tag-input">
              <button type="submit">Search</button>
              <button
                type="button"
                className="ghost"
                onClick={() => {
                  setSearchInput("");
                  setFilters((prev) => ({ ...prev, q: "" }));
                }}
              >
                Clear
              </button>
            </div>
          </form>
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
          <div className="admin-photo-library__save-all">
            <button type="button" onClick={handleBackfill}>
              Sync Base Photos
            </button>
            <button type="button" onClick={handleSaveAll} disabled={!dirtyIds.size}>
              Save All
            </button>
            {dirtyIds.size > 0 && <div className="admin-photo-library__dirty-count">{dirtyIds.size} unsaved</div>}
          </div>
        </div>

        {loading ? (
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
                      <button
                        type="button"
                        className="admin-photo-library__thumb"
                        onClick={() => setPreviewUrl(item.image_url || withCacheBuster(item.raw_rel_path || item.rel_path, item.updated_at))}
                      >
                        <img src={item.image_url || withCacheBuster(item.raw_rel_path || item.rel_path, item.updated_at)} alt="" />
                      </button>
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
                        <button type="button" className="ghost" onClick={() => handleLibrarySave(item)}>
                          Save
                        </button>
                        <a className="ghost" href={item.image_url || withCacheBuster(item.raw_rel_path || item.rel_path, item.updated_at)} download>
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
                    <td colSpan={8} className="admin-photo-library__empty">No photos yet.</td>
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

      <ClientPickerModal
        open={clientPickerOpen}
        onClose={() => {
          setClientPickerOpen(false);
          setClientTargetPhotoId(null);
        }}
        onPick={handleClientPicked}
      />
    </div>
  );
}

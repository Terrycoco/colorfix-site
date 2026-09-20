import { useEffect, useMemo, useRef, useState } from "react";
import InsertLinkModal from "@components/InsertLinkModal";
import LookupTypeManagerModal from "@components/LookupTypeManagerModal";
import ModalDialog from "@components/ModalDialog";
import {
  AdminMasterDetail,
  AdminListPane,
  AdminDetailPane,
  AdminObjectList,
  AdminObjectListItem,
  AdminEmptyState,
  AdminWorkbenchAddButton,
} from "@components/AdminLayout";
import { API_FOLDER } from "@helpers/config";
import { useAppState } from "@context/AppStateContext";
import { copyShareText, openTextShare } from "@helpers/shareUrls";
import { BRAND } from "@config/brand";
import "./admin-clients.css";

const LIST_URL = `${API_FOLDER}/v2/admin/clients/list.php`;
const GET_URL = `${API_FOLDER}/v2/admin/clients/get.php`;
const SAVE_URL = `${API_FOLDER}/v2/admin/clients/save.php`;
const DELETE_URL = `${API_FOLDER}/v2/admin/clients/delete.php`;
const DELETE_ACTIVITY_URL = `${API_FOLDER}/v2/admin/clients/delete-activity.php`;
const SEND_EMAIL_URL = `${API_FOLDER}/v2/admin/clients/send-email.php`;
const ACTIVITY_URL = `${API_FOLDER}/v2/admin/clients/activity.php`;
const MARK_ACTIVITY_READ_URL = `${API_FOLDER}/v2/admin/clients/mark-activity-read.php`;
const PHOTO_LIBRARY_LIST_URL = `${API_FOLDER}/v2/admin/photo-library/list.php`;
const EMAIL_TEMPLATES_URL = `${API_FOLDER}/v2/admin/email-templates.php`;
const CLIENT_TYPES_URL = `${API_FOLDER}/v2/admin/client-types/list.php`;
const SAVE_CLIENT_TYPE_URL = `${API_FOLDER}/v2/admin/client-types/save.php`;
const PERMISSION_TEMPLATE_KEY = "permission-photo-request";
const FREEFORM_TEMPLATE_KEY = "__freeform__";
const EMAIL_DRAFT_STORAGE_KEY = "admin-client-email-drafts-v1";

const EMPTY_FORM = {
  id: "",
  name: "",
  first_name: "",
  last_name: "",
  email: "",
  phone: "",
  notes: "",
  client_type: "homeowner",
  started_at: "",
  photo_permission_status: "unknown",
  photo_permission_requested_at: "",
  photo_permission_granted_at: "",
  photo_count: 0,
  applied_palette_count: 0,
  share_count: 0,
};

const EMPTY_EMAIL_DRAFT = {
  templateKey: FREEFORM_TEMPLATE_KEY,
  templateUpdatedAt: "",
  toEmail: "",
  cc: "",
  bcc: "",
  subject: "",
  message: "",
  html: "",
};

const EMPTY_TEXT_DRAFT = {
  toPhone: "",
  message: "",
};

function displayClientName(client) {
  const firstName = String(client?.first_name || "").trim();
  const lastName = String(client?.last_name || "").trim();
  const combined = [firstName, lastName].filter(Boolean).join(" ").trim();
  if (combined) return combined;
  const fullName = String(client?.name || "").trim();
  return fullName;
}

function displayClientListName(client) {
  const firstName = String(client?.first_name || "").trim();
  const lastName = String(client?.last_name || "").trim();
  if (lastName && firstName) return `${lastName}, ${firstName}`;
  if (lastName) return lastName;
  if (firstName) return firstName;
  return String(client?.name || "").trim();
}

function hydrateTemplate(text, client = {}) {
  const clientName = client?.first_name?.trim() || displayClientName(client).split(/\s+/)[0] || "there";
  const siteUrl = "https://colorfix.terrymarr.com";
  return String(text || "")
    .replace(/\{\{\s*client_name\s*\}\}/gi, clientName)
    .replace(/\{client_name\}/gi, clientName)
    .replace(/\{\{\s*client_first_name\s*\}\}/gi, clientName)
    .replace(/\{client_first_name\}/gi, clientName)
    .replace(/\{\{\s*client-first-name\s*\}\}/gi, clientName)
    .replace(/\{client-first-name\}/gi, clientName)
    .replace(/\{\{\s*site_url\s*\}\}/gi, siteUrl)
    .replace(/\{site_url\}/gi, siteUrl);
}

function formatPermissionStatus(status) {
  const normalized = String(status || "unknown").trim().toLowerCase();
  if (normalized === "requested") return "Requested";
  if (normalized === "granted") return "Granted";
  if (normalized === "declined") return "Declined";
  return "Unknown";
}

function formatClientType(clientType) {
  const normalized = String(clientType || "").trim().toLowerCase();
  if (normalized === "homeowner") return "Homeowner";
  if (normalized === "contractor") return "Contractor";
  if (normalized === "hoa") return "HOA";
  if (!normalized) return "Homeowner";
  return normalized
    .split("-")
    .filter(Boolean)
    .map((part) => part.charAt(0).toUpperCase() + part.slice(1))
    .join(" ");
}

function formatDateTime(value) {
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
}

function formatLocalDateTimeValue(date = new Date()) {
  const year = date.getFullYear();
  const month = String(date.getMonth() + 1).padStart(2, "0");
  const day = String(date.getDate()).padStart(2, "0");
  const hour = String(date.getHours()).padStart(2, "0");
  const minute = String(date.getMinutes()).padStart(2, "0");
  const second = String(date.getSeconds()).padStart(2, "0");
  return `${year}-${month}-${day} ${hour}:${minute}:${second}`;
}

function toDateTimeLocalValue(value) {
  const raw = String(value || "").trim();
  if (!raw) return "";
  const normalized = raw.replace(" ", "T");
  const match = normalized.match(/^(\d{4}-\d{2}-\d{2}T\d{2}:\d{2})/);
  if (match) return match[1];
  const parsed = new Date(normalized);
  if (Number.isNaN(parsed.getTime())) return raw;
  return formatLocalDateTimeValue(parsed).replace(" ", "T").slice(0, 16);
}

function fromDateTimeLocalValue(value) {
  const raw = String(value || "").trim();
  if (!raw) return "";
  return raw.replace("T", " ") + (raw.length === 16 ? ":00" : "");
}

function formatPhoneNumber(value) {
  const digits = String(value || "").replace(/\D+/g, "");
  if (!digits) return "";

  if (digits.length === 1 && digits === "1") {
    return "1";
  }

  if (digits.length > 10 && digits.startsWith("1")) {
    const core = digits.slice(1, 11);
    const rest = digits.slice(11);
    const formattedCore = formatPhoneNumber(core);
    return rest ? `1 ${formattedCore} x${rest}` : `1 ${formattedCore}`;
  }

  if (digits.length <= 3) {
    return digits;
  }

  if (digits.length <= 6) {
    return `(${digits.slice(0, 3)}) ${digits.slice(3)}`;
  }

  const core = `(${digits.slice(0, 3)}) ${digits.slice(3, 6)}-${digits.slice(6, 10)}`;
  const rest = digits.slice(10);
  return rest ? `${core} x${rest}` : core;
}

function usageSummary(client) {
  const photoCount = Number(client?.photo_count || 0);
  const appliedCount = Number(client?.applied_palette_count || 0);
  const shareCount = Number(client?.share_count || 0);
  const parts = [];
  if (photoCount) parts.push(`${photoCount} photo${photoCount === 1 ? "" : "s"}`);
  if (appliedCount) parts.push(`${appliedCount} palette link${appliedCount === 1 ? "" : "s"}`);
  if (shareCount) parts.push(`${shareCount} share${shareCount === 1 ? "" : "s"}`);
  return parts.length ? parts.join(" • ") : "No linked records";
}

function normalizeTemplate(template) {
  return {
    key: String(template?.key || ""),
    label: String(template?.label || ""),
    description: String(template?.description || ""),
    subject: String(template?.subject || ""),
    message: String(template?.message || ""),
    html: String(template?.html || ""),
    isActive: template?.is_active !== false && template?.is_active !== 0,
    updatedAt: String(template?.updated_at || ""),
  };
}

function buildDraftFromTemplate(client, template, previousDraft = EMPTY_EMAIL_DRAFT) {
  if (!template || template.key === FREEFORM_TEMPLATE_KEY) {
    return {
      ...EMPTY_EMAIL_DRAFT,
      toEmail: client?.email || previousDraft.toEmail || "",
      cc: previousDraft.cc || "",
      bcc: previousDraft.bcc || "",
    };
  }
  return {
    templateKey: template.key,
    templateUpdatedAt: template.updatedAt || "",
    toEmail: client?.email || previousDraft.toEmail || "",
    cc: previousDraft.cc || "",
    bcc: previousDraft.bcc || "",
    subject: hydrateTemplate(template.subject, client),
    message: hydrateTemplate(template.message, client),
    html: hydrateTemplate(template.html, client),
  };
}

function buildTextDraft(client, previousDraft = EMPTY_TEXT_DRAFT) {
  return {
    toPhone: client?.phone || previousDraft.toPhone || "",
    message: previousDraft.message || "",
  };
}

function shouldRestoreStoredDraft(storedDraft, templateOptions) {
  if (!storedDraft || typeof storedDraft !== "object") return false;
  const templateKey = String(storedDraft.templateKey || "");
  if (!templateKey || templateKey === FREEFORM_TEMPLATE_KEY) return true;
  const template = templateOptions.find((item) => item.key === templateKey);
  if (!template) return false;
  const storedUpdatedAt = String(storedDraft.templateUpdatedAt || "").trim();
  const templateUpdatedAt = String(template.updatedAt || "").trim();
  if (!storedUpdatedAt || !templateUpdatedAt) return false;
  return storedUpdatedAt === templateUpdatedAt;
}

function appendTextLink(message, payload) {
  const line = payload.text?.trim() ? `${payload.text.trim()}: ${payload.url}` : payload.url;
  return `${String(message || "").trimEnd()}${message ? "\n" : ""}${line}`.trim();
}

function appendHtmlLink(html, payload) {
  const label = payload.text?.trim() || payload.url;
  const anchor = `<p><a href="${payload.url}">${label}</a></p>`;
  const source = String(html || "");
  if (/\[LINK\]/i.test(source)) {
    return source.replace(/\[LINK\]/i, `<a href="${payload.url}">${label}</a>`);
  }
  return `${source.trim()}${html ? "\n" : ""}${anchor}`.trim();
}

function htmlToPlainText(html) {
  const normalized = String(html || "")
    .replace(/<br\s*\/?>/gi, "\n")
    .replace(/<\/p>/gi, "\n\n")
    .replace(/<\/div>/gi, "\n")
    .replace(/<\/li>/gi, "\n")
    .replace(/<li\b[^>]*>/gi, "* ");

  if (typeof window === "undefined" || !window.document) {
    return normalized.replace(/<[^>]+>/g, "").trim();
  }

  const container = window.document.createElement("div");
  container.innerHTML = normalized;
  return String(container.textContent || container.innerText || "")
    .replace(/\n{3,}/g, "\n\n")
    .trim();
}

function escapeHtml(value) {
  return String(value || "")
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;")
    .replace(/'/g, "&#39;");
}

function plainTextToHtml(text) {
  const normalized = String(text || "").replace(/\r\n/g, "\n").trim();
  if (!normalized) return "";

  return normalized
    .split(/\n{2,}/)
    .map((paragraph) => `<p>${escapeHtml(paragraph).replace(/\n/g, "<br />")}</p>`)
    .join("\n");
}

function insertTextAtSelection(value, insertValue, selectionStart, selectionEnd) {
  const source = String(value || "");
  const start = Number.isInteger(selectionStart) ? selectionStart : source.length;
  const end = Number.isInteger(selectionEnd) ? selectionEnd : start;
  return `${source.slice(0, start)}${insertValue}${source.slice(end)}`;
}

function activityRowId(item) {
  return Number(item?.id || item?.client_activity_id || 0);
}

function isUnreadSiteNote(item) {
  return item?.activity_type === "site_note_received" && item?.needs_attention !== false && !item?.admin_read_at;
}

function isSiteNoteActivity(item) {
  return item?.activity_type === "site_note_received" || item?.email?.direction === "inbound";
}

function siteNoteMessage(item) {
  const text = String(item?.email?.text_body || item?.details || "").trim();
  if (!text) return "";

  const match = text.match(/(?:^|\n)Message:\s*\n?([\s\S]*)$/i);
  if (match?.[1]) {
    return match[1].trim();
  }

  return text;
}

function clientPhotoHref(photoLibraryId) {
  const id = Number(photoLibraryId || 0);
  return id > 0 ? `/admin/photo-library?photo_library_ids=${id}` : "/admin/photo-library";
}

function readStoredDrafts() {
  if (typeof window === "undefined") return {};
  try {
    const raw = window.localStorage.getItem(EMAIL_DRAFT_STORAGE_KEY);
    const parsed = raw ? JSON.parse(raw) : {};
    return parsed && typeof parsed === "object" ? parsed : {};
  } catch {
    return {};
  }
}

function writeStoredDrafts(nextDrafts) {
  if (typeof window === "undefined") return;
  try {
    window.localStorage.setItem(EMAIL_DRAFT_STORAGE_KEY, JSON.stringify(nextDrafts));
  } catch {
    // Ignore storage failures; draft persistence is best-effort.
  }
}

export default function AdminClientsPage() {
  const {
    adminExitPath,
    clearAdminExitPath,
  } = useAppState();

  const initialRouteRef = useRef(
    typeof window === "undefined"
      ? { clientId: "", action: "" }
      : {
          clientId: new URLSearchParams(window.location.search).get("client_id") || "",
          action: new URLSearchParams(window.location.search).get("action") || "",
        }
  );
  const routeConsumedRef = useRef(false);

  const [query, setQuery] = useState("");
  const [items, setItems] = useState([]);
  const [selectedId, setSelectedId] = useState("");
  const [isCreatingNew, setIsCreatingNew] = useState(false);
  const [form, setForm] = useState(EMPTY_FORM);
  const [clientTypes, setClientTypes] = useState([]);
  const [clientTypesLoading, setClientTypesLoading] = useState(true);
  const [clientTypeModalOpen, setClientTypeModalOpen] = useState(false);
  const [clientTypeSaveError, setClientTypeSaveError] = useState("");
  const [savingClientType, setSavingClientType] = useState(false);
  const [templates, setTemplates] = useState([]);
  const [templatesLoading, setTemplatesLoading] = useState(false);
  const [emailDraft, setEmailDraft] = useState(EMPTY_EMAIL_DRAFT);
  const [textDraft, setTextDraft] = useState(EMPTY_TEXT_DRAFT);
  const [activityItems, setActivityItems] = useState([]);
  const [activityLoading, setActivityLoading] = useState(false);
  const [photoItems, setPhotoItems] = useState([]);
  const [photosLoading, setPhotosLoading] = useState(false);
  const [photosLoadedForClientId, setPhotosLoadedForClientId] = useState("");
  const [activeTab, setActiveTab] = useState("details");
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [savingDraft, setSavingDraft] = useState(false);
  const [deleting, setDeleting] = useState(false);
  const [sendingEmail, setSendingEmail] = useState(false);
  const [deletingActivityId, setDeletingActivityId] = useState("");
  const [linkModalOpen, setLinkModalOpen] = useState(false);
  const [linkModalTarget, setLinkModalTarget] = useState("email");
  const [emailView, setEmailView] = useState("plain");
  const [emailHtmlManuallyEdited, setEmailHtmlManuallyEdited] = useState(false);
  const [activityDetail, setActivityDetail] = useState(null);
  const [error, setError] = useState("");
  const [notice, setNotice] = useState("");
  const plainTextBodyRef = useRef(null);
  const textBodyRef = useRef(null);
  const linkSelectionRef = useRef({ target: "email", start: null, end: null });

  function handleBack() {
    const target =
      String(
        adminExitPath ||
        ""
      ).trim();

    if (target) {
      clearAdminExitPath();
      window.location.assign(target);
      return;
    }

    window.history.back();
  }

  const templateOptions = useMemo(() => {
    const activeTemplates = templates.filter((template) => template.isActive);
    return [{ key: FREEFORM_TEMPLATE_KEY, label: "Freeform", description: "Start with a blank draft." }, ...activeTemplates];
  }, [templates]);

  const selectedTemplate = useMemo(
    () => templateOptions.find((template) => template.key === emailDraft.templateKey) || templateOptions[0] || null,
    [emailDraft.templateKey, templateOptions]
  );

  const selectedUsage = useMemo(() => usageSummary(form), [form]);
  const selectedUnreadSiteNoteCount = useMemo(
    () => activityItems.filter(isUnreadSiteNote).length,
    [activityItems]
  );
  const clientTypeLabelMap = useMemo(
    () => new Map(clientTypes.map((item) => [item.key, item.label])),
    [clientTypes]
  );

  function clientTypeLabel(key) {
    const normalized = String(key || "").trim().toLowerCase();
    if (!normalized) return "Homeowner";
    return clientTypeLabelMap.get(normalized) || formatClientType(normalized);
  }

  function normalizeClientForm(client) {
    return {
      id: String(client?.id || ""),
      name: client?.name || "",
      first_name: client?.first_name || "",
      last_name: client?.last_name || "",
      email: client?.email || "",
      phone: client?.phone || "",
      notes: client?.notes || "",
      client_type: client?.client_type || "homeowner",
      started_at: client?.started_at || "",
      photo_permission_status: client?.photo_permission_status || "unknown",
      photo_permission_requested_at: client?.photo_permission_requested_at || "",
      photo_permission_granted_at: client?.photo_permission_granted_at || "",
      photo_count: Number(client?.photo_count || 0),
      applied_palette_count: Number(client?.applied_palette_count || 0),
      share_count: Number(client?.share_count || 0),
    };
  }

  async function loadClientDetail(clientId) {
    const normalizedClientId = String(clientId || "").trim();
    if (!normalizedClientId) return null;
    const res = await fetch(`${GET_URL}?id=${encodeURIComponent(normalizedClientId)}&_=${Date.now()}`, { credentials: "include" });
    const data = await res.json();
    if (!res.ok || !data?.ok || !data?.client) {
      throw new Error(data?.error || "Failed to load client");
    }
    return normalizeClientForm(data.client);
  }

  async function loadClients(preferredId = null) {
    setLoading(true);
    setError("");
    try {
      const params = new URLSearchParams();
      if (query.trim()) params.set("q", query.trim());
      params.set("limit", "500");
      params.set("_", String(Date.now()));
      const res = await fetch(`${LIST_URL}?${params.toString()}`, { credentials: "include" });
      const data = await res.json();
      if (!res.ok || !data?.ok) throw new Error(data?.error || "Failed to load clients");
      const nextItems = Array.isArray(data.items) ? data.items : [];
      setItems(nextItems);

      const targetId = preferredId ?? selectedId;
      const target = nextItems.find((item) => String(item.id) === String(targetId));
      if (target) {
        await selectClient(target);
      } else if (!targetId && nextItems[0]) {
        await selectClient(nextItems[0]);
      } else if (!target && targetId) {
        setSelectedId("");
        setForm(EMPTY_FORM);
        setEmailDraft(EMPTY_EMAIL_DRAFT);
        setTextDraft(EMPTY_TEXT_DRAFT);
        setActivityItems([]);
      }
    } catch (err) {
      setError(err?.message || "Failed to load clients");
    } finally {
      setLoading(false);
    }
  }

  async function loadTemplates() {
    setTemplatesLoading(true);
    try {
      const res = await fetch(`${EMAIL_TEMPLATES_URL}?_=${Date.now()}`, { credentials: "include" });
      const data = await res.json();
      if (!res.ok || !data?.ok) throw new Error(data?.error || "Failed to load templates");
      const nextTemplates = (Array.isArray(data.templates) ? data.templates : []).map(normalizeTemplate);
      setTemplates(nextTemplates);
      return nextTemplates;
    } catch (err) {
      setError(err?.message || "Failed to load templates");
      setTemplates([]);
      return [];
    } finally {
      setTemplatesLoading(false);
    }
  }

  async function loadClientTypes() {
    setClientTypesLoading(true);
    try {
      const res = await fetch(`${CLIENT_TYPES_URL}?_=${Date.now()}`, { credentials: "include" });
      const data = await res.json();
      if (!res.ok || !data?.ok) throw new Error(data?.error || "Failed to load client types");
      const items = Array.isArray(data.items) ? data.items : [];
      setClientTypes(items.map((item) => ({
        key: String(item.key || "").trim(),
        label: String(item.label || item.key || "").trim(),
      })).filter((item) => item.key && item.label));
    } catch (err) {
      setError(err?.message || "Failed to load client types");
      setClientTypes([
        { key: "homeowner", label: "Homeowner" },
        { key: "contractor", label: "Contractor" },
        { key: "hoa", label: "HOA" },
      ]);
    } finally {
      setClientTypesLoading(false);
    }
  }

  async function fetchTemplateByKey(templateKey) {
    const key = String(templateKey || "").trim();
    if (!key || key === FREEFORM_TEMPLATE_KEY) {
      return { key: FREEFORM_TEMPLATE_KEY };
    }
    const res = await fetch(`${EMAIL_TEMPLATES_URL}?key=${encodeURIComponent(key)}&_=${Date.now()}`, { credentials: "include" });
    const data = await res.json();
    if (!res.ok || !data?.ok || !data?.template) {
      throw new Error(data?.error || "Failed to load template");
    }
    return normalizeTemplate(data.template);
  }

  async function loadActivity(clientId) {
    if (!clientId) {
      setActivityItems([]);
      return;
    }
    setActivityLoading(true);
    try {
      const params = new URLSearchParams({
        client_id: String(clientId),
        limit: "200",
        _: String(Date.now()),
      });
      const res = await fetch(`${ACTIVITY_URL}?${params.toString()}`, { credentials: "include" });
      const data = await res.json();
      if (!res.ok || !data?.ok) throw new Error(data?.error || "Failed to load activity");
      setActivityItems(Array.isArray(data.items) ? data.items : []);
    } catch (err) {
      setError(err?.message || "Failed to load activity");
      setActivityItems([]);
    } finally {
      setActivityLoading(false);
    }
  }

  async function loadPhotos(clientId, { force = false } = {}) {
    const normalizedClientId = String(clientId || "").trim();
    if (!normalizedClientId) {
      setPhotoItems([]);
      setPhotosLoadedForClientId("");
      return;
    }
    if (!force && photosLoadedForClientId === normalizedClientId) {
      return;
    }

    setPhotosLoading(true);
    try {
      const params = new URLSearchParams({
        client_id: normalizedClientId,
        sort: "newest",
        limit: "200",
        _: String(Date.now()),
      });
      const res = await fetch(`${PHOTO_LIBRARY_LIST_URL}?${params.toString()}`, { credentials: "include" });
      const data = await res.json();
      if (!res.ok || !data?.ok) throw new Error(data?.error || "Failed to load photos");
      setPhotoItems(Array.isArray(data.items) ? data.items : []);
      setPhotosLoadedForClientId(normalizedClientId);
    } catch (err) {
      setError(err?.message || "Failed to load photos");
      setPhotoItems([]);
      setPhotosLoadedForClientId("");
    } finally {
      setPhotosLoading(false);
    }
  }

  useEffect(() => {
    void loadClientTypes();
  }, []);

  useEffect(() => {
    if (activeTab !== "email") return;
    if (templates.length > 0) return;
    void loadTemplates();
  }, [activeTab, templates.length, templatesLoading]);

  useEffect(() => {
    let active = true;
    async function fetchClients() {
      setLoading(true);
      setError("");
      try {
        const params = new URLSearchParams();
        if (query.trim()) params.set("q", query.trim());
        params.set("limit", "500");
        params.set("_", String(Date.now()));
        const res = await fetch(`${LIST_URL}?${params.toString()}`, { credentials: "include" });
        const data = await res.json();
        if (!res.ok || !data?.ok) throw new Error(data?.error || "Failed to load clients");
        if (!active) return;

        const nextItems = Array.isArray(data.items) ? data.items : [];
        setItems(nextItems);

        if (!routeConsumedRef.current) {
          routeConsumedRef.current = true;

          const requestedAction =
            String(
              initialRouteRef.current.action ||
              ""
            ).trim().toLowerCase();

          const requestedClientId =
            String(
              initialRouteRef.current.clientId ||
              ""
            ).trim();

          if (requestedAction === "new") {
            startNew();
            return;
          }

          if (requestedClientId) {
            await selectClient({
              id: requestedClientId,
            });
            return;
          }
        }

        const targetId = selectedId;
        const target = nextItems.find((item) => String(item.id) === String(targetId));
        if (!target && targetId) {
          setSelectedId("");
          setIsCreatingNew(false);
          setForm(EMPTY_FORM);
          setEmailDraft(EMPTY_EMAIL_DRAFT);
          setTextDraft(EMPTY_TEXT_DRAFT);
          setActivityItems([]);
          setPhotoItems([]);
          setPhotosLoadedForClientId("");
        }
      } catch (err) {
        if (!active) return;
        setError(err?.message || "Failed to load clients");
      } finally {
        if (active) setLoading(false);
      }
    }

    void fetchClients();
    return () => {
      active = false;
    };
  }, [query, isCreatingNew]);

  useEffect(() => {
    if (!selectedId) {
      setActivityItems([]);
      return;
    }
    void loadActivity(selectedId);
  }, [selectedId]);

  useEffect(() => {
    if (activeTab !== "photos") return;
    if (!form.id) {
      setPhotoItems([]);
      setPhotosLoadedForClientId("");
      return;
    }
    void loadPhotos(form.id);
  }, [activeTab, form.id]);

  async function selectClient(client, nextTemplates = templates) {
    const next = await loadClientDetail(client?.id);
    if (!next) return;
    const storedDraft = readStoredDrafts()[next.id];
    setSelectedId(next.id);
    setIsCreatingNew(false);
    setForm(next);
    setEmailDraft(
      shouldRestoreStoredDraft(storedDraft, nextTemplates)
        ? { ...EMPTY_EMAIL_DRAFT, ...storedDraft, toEmail: storedDraft.toEmail || next.email || "" }
        : buildDraftFromTemplate(next, { key: FREEFORM_TEMPLATE_KEY })
    );
    setTextDraft((previousDraft) => buildTextDraft(next, previousDraft));
    setEmailHtmlManuallyEdited(false);
    setPhotoItems([]);
    setPhotosLoadedForClientId("");
    setError("");
    setNotice("");
  }

  function startNew() {
    const nextForm = { ...EMPTY_FORM, started_at: formatLocalDateTimeValue() };
    setSelectedId("");
    setIsCreatingNew(true);
    setForm(nextForm);
    setEmailDraft(buildDraftFromTemplate(nextForm, { key: FREEFORM_TEMPLATE_KEY }));
    setTextDraft(buildTextDraft(nextForm));
    setEmailHtmlManuallyEdited(false);
    setActivityItems([]);
    setPhotoItems([]);
    setPhotosLoadedForClientId("");
    setActiveTab("details");
    setEmailView("plain");
    setError("");
    setNotice("");
  }

  function updateField(key, value) {
    setForm((prev) => ({ ...prev, [key]: value }));
    setNotice("");
  }

  async function handleCreateClientType(label) {
    setSavingClientType(true);
    setClientTypeSaveError("");
    try {
      const res = await fetch(SAVE_CLIENT_TYPE_URL, {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ label }),
      });
      const data = await res.json();
      if (!res.ok || !data?.ok) throw new Error(data?.error || "Failed to save client type");
      await loadClientTypes();
      const nextKey = String(data.item?.key || "").trim();
      if (nextKey) {
        updateField("client_type", nextKey);
      }
      return true;
    } catch (err) {
      setClientTypeSaveError(err?.message || "Failed to save client type");
      return false;
    } finally {
      setSavingClientType(false);
    }
  }

  async function handleSaveDraft() {
    if (!selectedId) {
      setError("Save the client first before saving a draft.");
      return;
    }

    setSavingDraft(true);
    setError("");
    setNotice("");
    try {
      const normalizedDraft = emailView === "html"
        ? {
            ...emailDraft,
            message: htmlToPlainText(emailDraft.html),
          }
        : {
            ...emailDraft,
            html: plainTextToHtml(emailDraft.message),
          };

      setEmailDraft(normalizedDraft);
      setEmailHtmlManuallyEdited(emailView === "html");
      const nextDrafts = readStoredDrafts();
      nextDrafts[String(selectedId)] = normalizedDraft;
      writeStoredDrafts(nextDrafts);
      setNotice("Draft saved. Plain text and HTML were synced.");
    } catch (err) {
      setError(err?.message || "Failed to save draft");
    } finally {
      setSavingDraft(false);
    }
  }

  async function applyTemplate(templateKey) {
    setError("");
    setNotice("");
    if (templateKey === FREEFORM_TEMPLATE_KEY) {
      setEmailDraft((prev) => buildDraftFromTemplate(form, { key: FREEFORM_TEMPLATE_KEY }, prev));
      setEmailHtmlManuallyEdited(false);
      setEmailView("plain");
      return;
    }

    try {
      const freshTemplate = await fetchTemplateByKey(templateKey);
      setTemplates((prev) => {
        const remaining = prev.filter((item) => item.key !== freshTemplate.key);
        return [...remaining, freshTemplate].sort((a, b) => a.label.localeCompare(b.label));
      });
      setEmailDraft((prev) => buildDraftFromTemplate(form, freshTemplate, prev));
      setEmailHtmlManuallyEdited(false);
      setEmailView("plain");
    } catch (err) {
      setError(err?.message || "Failed to load template");
    }
  }

  async function handleSave(event) {
    event.preventDefault();
    setSaving(true);
    setError("");
    setNotice("");
    try {
      const res = await fetch(SAVE_URL, {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          id: form.id ? Number(form.id) : null,
          name: displayClientName(form),
          first_name: form.first_name,
          last_name: form.last_name,
          email: form.email,
          phone: form.phone,
          notes: form.notes,
          client_type: form.client_type || null,
          started_at: form.started_at || null,
          photo_permission_status: form.photo_permission_status,
          photo_permission_requested_at: form.photo_permission_requested_at || null,
          photo_permission_granted_at: form.photo_permission_granted_at || null,
        }),
      });
      const data = await res.json();
      if (!res.ok || !data?.ok) throw new Error(data?.error || "Failed to save client");
      setNotice(form.id ? "Client updated." : "Client created.");
      setIsCreatingNew(false);
      await loadClients(String(data.client?.id || ""));
    } catch (err) {
      setError(err?.message || "Failed to save client");
    } finally {
      setSaving(false);
    }
  }

  async function handleDelete() {
    if (!form.id) return;
    const summary = usageSummary(form);
    const confirmed = window.confirm(
      `Delete client "${displayClientName(form) || form.email || `#${form.id}`}"?\n\n${summary}\n\nPhoto links will be cleared. Related palette/share links for this client will be removed.`
    );
    if (!confirmed) return;

    setDeleting(true);
    setError("");
    setNotice("");
    try {
      const res = await fetch(DELETE_URL, {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ id: Number(form.id) }),
      });
      const data = await res.json();
      if (!res.ok || !data?.ok) throw new Error(data?.error || "Failed to delete client");
      setNotice("Client deleted.");
      startNew();
      await loadClients();
    } catch (err) {
      setError(err?.message || "Failed to delete client");
    } finally {
      setDeleting(false);
    }
  }

  async function handleSendEmail() {
    if (!form.id) {
      setError("Save the client first before sending email.");
      return;
    }
    setSendingEmail(true);
    setError("");
    setNotice("");
    try {
      const normalizedDraft = emailHtmlManuallyEdited
        ? {
            ...emailDraft,
            message: emailDraft.message.trim() ? emailDraft.message : htmlToPlainText(emailDraft.html),
          }
        : {
            ...emailDraft,
            html: "",
          };
      setEmailDraft(normalizedDraft);

      const res = await fetch(SEND_EMAIL_URL, {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          client_id: Number(form.id),
          template_key: normalizedDraft.templateKey === FREEFORM_TEMPLATE_KEY ? "" : normalizedDraft.templateKey,
          to_email: normalizedDraft.toEmail,
          cc: normalizedDraft.cc,
          bcc: normalizedDraft.bcc,
          subject: normalizedDraft.subject,
          message: normalizedDraft.message,
          html_body: emailHtmlManuallyEdited ? normalizedDraft.html : "",
          purpose: normalizedDraft.templateKey === PERMISSION_TEMPLATE_KEY ? "photo_permission_request" : "client_email",
          activity_type: normalizedDraft.templateKey === PERMISSION_TEMPLATE_KEY ? "permission_request_sent" : "email_sent",
          activity_summary: normalizedDraft.templateKey === PERMISSION_TEMPLATE_KEY ? "Photo permission request sent" : `Email sent: ${normalizedDraft.subject}`,
          mark_permission_requested: normalizedDraft.templateKey === PERMISSION_TEMPLATE_KEY,
        }),
      });
      const data = await res.json();
      if (!res.ok || !data?.ok) throw new Error(data?.error || "Failed to send email");

      if (emailDraft.templateKey === PERMISSION_TEMPLATE_KEY) {
        setForm((prev) => ({
          ...prev,
          photo_permission_status: data.permission_status || "requested",
          photo_permission_requested_at: data.sent_at || prev.photo_permission_requested_at,
        }));
      }

      setNotice("Email sent.");
      if (form.id) {
        const nextDrafts = readStoredDrafts();
        delete nextDrafts[String(form.id)];
        writeStoredDrafts(nextDrafts);
      }
      setEmailDraft(buildDraftFromTemplate(form, { key: FREEFORM_TEMPLATE_KEY }));
      setEmailHtmlManuallyEdited(false);
      setEmailView("plain");
      await loadActivity(form.id);
      await loadClients(form.id);
      setActiveTab("activity");
    } catch (err) {
      setError(err?.message || "Failed to send email");
    } finally {
      setSendingEmail(false);
    }
  }

  async function handleDeleteActivity(item) {
    const activityId = activityRowId(item);
    const clientId = Number(item?.client_id || form.id || 0);
    if (!activityId || !clientId) {
      setError("Missing activity id. Refresh the client activity list and try again.");
      return;
    }
    const label = item?.summary || item?.activity_type || `activity #${activityId}`;
    const confirmed = window.confirm(`Delete this activity entry?\n\n${label}`);
    if (!confirmed) return;

    setDeletingActivityId(String(activityId));
    setError("");
    setNotice("");
    try {
      const res = await fetch(DELETE_ACTIVITY_URL, {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          activity_id: activityId,
          client_id: clientId,
        }),
      });
      const data = await res.json();
      if (!res.ok || !data?.ok) throw new Error(data?.error || "Failed to delete activity");
      if (String(activityRowId(activityDetail)) === String(activityId)) {
        setActivityDetail(null);
      }
      setNotice("Activity deleted.");
      await loadActivity(clientId);
    } catch (err) {
      setError(err?.message || "Failed to delete activity");
    } finally {
      setDeletingActivityId("");
    }
  }

  async function handleOpenActivity(item) {
    setActivityDetail(item);
    if (!isUnreadSiteNote(item)) return;

    const activityId = activityRowId(item);
    const clientId = Number(item?.client_id || form.id || 0);
    if (!activityId) return;

    const readAt = new Date().toISOString();
    const markLocalRead = () => {
      setActivityItems((prev) => prev.map((activity) => (
        activityRowId(activity) === activityId
          ? { ...activity, needs_attention: false, admin_read_at: readAt }
          : activity
      )));
      setItems((prev) => prev.map((client) => {
        if (String(client.id) !== String(clientId)) return client;
        return {
          ...client,
          unread_site_note_count: Math.max(0, Number(client.unread_site_note_count || 0) - 1),
        };
      }));
      setActivityDetail((prev) => (
        prev && activityRowId(prev) === activityId
          ? { ...prev, needs_attention: false, admin_read_at: readAt }
          : prev
      ));
    };

    try {
      const res = await fetch(MARK_ACTIVITY_READ_URL, {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          activity_id: activityId,
          client_id: clientId || undefined,
        }),
      });
      const data = await res.json();
      if (!res.ok || !data?.ok) throw new Error(data?.error || "Failed to mark note read");
      markLocalRead();
    } catch (err) {
      setError(err?.message || "Failed to mark note read");
    }
  }

  function handleLinkInsert(payload) {
    const line = payload.text?.trim() ? `${payload.text.trim()}: ${payload.url}` : payload.url;
    const insertion = line ? `${line}\n` : "";
    const savedSelection = linkSelectionRef.current?.target === linkModalTarget
      ? linkSelectionRef.current
      : { start: null, end: null };

    if (linkModalTarget === "text") {
      const textarea = textBodyRef.current;
      const selectionStart = Number.isInteger(savedSelection.start)
        ? savedSelection.start
        : (textarea && typeof textarea.selectionStart === "number" ? textarea.selectionStart : null);
      const selectionEnd = Number.isInteger(savedSelection.end)
        ? savedSelection.end
        : (textarea && typeof textarea.selectionEnd === "number" ? textarea.selectionEnd : null);

      setTextDraft((prev) => {
        const nextMessage = selectionStart !== null && selectionEnd !== null
          ? insertTextAtSelection(prev.message, insertion, selectionStart, selectionEnd)
          : appendTextLink(prev.message, payload);
        return {
          ...prev,
          message: nextMessage,
        };
      });

      setNotice("Link inserted into the text draft.");
      setLinkModalOpen(false);
      window.requestAnimationFrame(() => {
        const nextTextarea = textBodyRef.current;
        if (!nextTextarea) return;
        const caretBase = selectionStart ?? nextTextarea.value.length;
        const caret = caretBase + insertion.length;
        nextTextarea.focus();
        nextTextarea.setSelectionRange(caret, caret);
      });
      return;
    }

    const textarea = plainTextBodyRef.current;
    const selectionStart = Number.isInteger(savedSelection.start)
      ? savedSelection.start
      : (emailView === "plain" && textarea && typeof textarea.selectionStart === "number" ? textarea.selectionStart : null);
    const selectionEnd = Number.isInteger(savedSelection.end)
      ? savedSelection.end
      : (emailView === "plain" && textarea && typeof textarea.selectionEnd === "number" ? textarea.selectionEnd : null);

    setEmailDraft((prev) => {
      const hasPlainSelection = emailView === "plain"
        && selectionStart !== null
        && selectionEnd !== null;

      const nextMessage = hasPlainSelection
        ? insertTextAtSelection(prev.message, insertion, selectionStart, selectionEnd)
        : appendTextLink(prev.message, payload);
      const nextHtml = prev.html.trim()
        ? appendHtmlLink(prev.html, payload)
        : plainTextToHtml(nextMessage);

      return {
        ...prev,
        message: nextMessage,
        html: nextHtml,
      };
    });
    setEmailView("plain");
    setNotice(
      emailView === "plain"
        ? "Link inserted at the current cursor position."
        : "Link inserted into the draft."
    );
    setLinkModalOpen(false);

    if (emailView === "plain") {
      window.requestAnimationFrame(() => {
        const nextTextarea = plainTextBodyRef.current;
        if (!nextTextarea) return;
        const caretBase = selectionStart ?? nextTextarea.value.length;
        const caret = caretBase + insertion.length;
        nextTextarea.focus();
        nextTextarea.setSelectionRange(caret, caret);
      });
    }
  }

  function rememberLinkSelection(target) {
    const textarea = target === "text" ? textBodyRef.current : plainTextBodyRef.current;
    linkSelectionRef.current = {
      target,
      start: textarea && typeof textarea.selectionStart === "number" ? textarea.selectionStart : null,
      end: textarea && typeof textarea.selectionEnd === "number" ? textarea.selectionEnd : null,
    };
  }

  function openLinkModal(target) {
    setLinkModalTarget(target);
    rememberLinkSelection(target);
    setLinkModalOpen(true);
  }

  function handleOpenTextMessage() {
    const phone = String(textDraft.toPhone || "").trim();
    if (!phone) {
      setError("Add a phone number first.");
      return;
    }
    const body = String(textDraft.message || "").trim();
    openTextShare({ text: body, phone }).catch(() => {});
  }

  async function handleCopyTextMessage() {
    const message = String(textDraft.message || "").trim();
    if (!message) {
      setError("Write the text message first.");
      return;
    }
    const copied = await copyShareText(message);
    if (copied) {
      setNotice("Text copied.");
    } else {
      setError("Failed to copy text.");
    }
  }

  return (
    <>
      <AdminMasterDetail
        storageKey="admin-clients-list-width"
        defaultListWidth={300}
        minListWidth={220}
        maxListWidth={460}
        list={
          <AdminListPane
            title="Clients"
            actions={
              <div
                style={{
                  display: "flex",
                  alignItems: "center",
                  gap: "6px",
                }}
              >
                <button
                  type="button"
                  className="admin-clients__secondary"
                  onClick={handleBack}
                  title="Back"
                >
                  ← Back
                </button>

                <AdminWorkbenchAddButton
                  onClick={startNew}
                  title="New Client"
                />
              </div>
            }
            toolbar={
              <input
                className="admin-clients__search"
                type="search"
                placeholder="Search by name, email, or phone"
                value={query}
                onChange={(e) => setQuery(e.target.value)}
              />
            }
          >
            {loading ? (
              <div className="admin-clients__empty">Loading clients…</div>
            ) : null}

            {!loading && items.length === 0 ? (
              <AdminEmptyState
                title="No clients found"
                message={query.trim() ? "Try a different search." : "Create the first client to get started."}
              />
            ) : null}

            {!loading && items.length > 0 ? (
              <AdminObjectList ariaLabel="Clients">
                {items.map((client) => {
                  const unreadCount = Number(client.unread_site_note_count || 0);
                  return (
                    <AdminObjectListItem
                      key={client.id}
                      id={client.id}
                      title={displayClientListName(client) || "Unnamed client"}
                      meta={[
                        client.email || "No email",
                        client.phone || "No phone",
                      ]}
                      selected={String(client.id) === String(selectedId)}
                      status={unreadCount > 0 ? {
                        active: true,
                        count: unreadCount,
                        label: `${unreadCount} unread site note${unreadCount === 1 ? "" : "s"}`,
                      } : null}
                      onSelect={() => { void selectClient(client); }}
                      onStatusClick={() => {
                        void selectClient(client).then(() => setActiveTab("activity"));
                      }}
                    />
                  );
                })}
              </AdminObjectList>
            ) : null}
          </AdminListPane>
        }
        detail={
          <AdminDetailPane ariaLabel="Client detail">
            {form.id || isCreatingNew ? (
              <>
                <div className="admin-detail-header">
                  <div>
                    <h1 className="admin-detail-header__title">
                      {form.id ? (displayClientName(form) || `Client #${form.id}`) : "New Client"}
                    </h1>
                    <p className="admin-detail-header__description">
                      {clientTypeLabel(form.client_type)} · {selectedUsage}
                      {form.started_at ? ` · Started ${formatDateTime(form.started_at)}` : ""}
                      {form.photo_permission_requested_at ? ` · Requested ${formatDateTime(form.photo_permission_requested_at)}` : ""}
                      {form.photo_permission_granted_at ? ` · Granted ${formatDateTime(form.photo_permission_granted_at)}` : ""}
                    </p>
                  </div>
                  <div className="admin-detail-header__actions">
                    <div className={`admin-clients__perm-badge is-${form.photo_permission_status || "unknown"}`}>
                      {formatPermissionStatus(form.photo_permission_status)}
                    </div>
                  </div>
                </div>

          {error ? <div className="admin-clients__message admin-clients__message--error">{error}</div> : null}
          {notice ? <div className="admin-clients__message admin-clients__message--ok">{notice}</div> : null}

          <div className="admin-clients__tabs" role="tablist" aria-label="Client sections">
            {[
              { key: "details", label: "Details" },
              { key: "email", label: "Email" },
              { key: "text", label: "Text" },
              { key: "activity", label: "Activity", count: selectedUnreadSiteNoteCount },
              { key: "photos", label: "Photos" },
            ].map((tab) => (
              <button
                key={tab.key}
                type="button"
                role="tab"
                aria-selected={activeTab === tab.key}
                className={`admin-clients__tab ${activeTab === tab.key ? "is-active" : ""}`}
                onClick={() => setActiveTab(tab.key)}
              >
                {tab.label}
                {Number(tab.count || 0) > 0 ? (
                  <span className="admin-clients__tab-badge">{tab.count}</span>
                ) : null}
              </button>
            ))}
          </div>

          {activeTab === "details" ? (
            <form className="admin-clients__form" onSubmit={handleSave}>
              <div className="admin-clients__form-grid">
                <label>
                  First name
                  <input type="text" value={form.first_name} onChange={(e) => updateField("first_name", e.target.value)} />
                </label>
                <label>
                  Last name
                  <input type="text" value={form.last_name} onChange={(e) => updateField("last_name", e.target.value)} />
                </label>
              </div>
              <div className="admin-clients__form-grid admin-clients__form-grid--contact">
                <label>
                  Email
                  <input type="email" value={form.email} onChange={(e) => updateField("email", e.target.value)} />
                </label>
                <label>
                  Phone
                  <input
                    type="text"
                    value={form.phone}
                    onChange={(e) => updateField("phone", formatPhoneNumber(e.target.value))}
                    placeholder="(555) 123-4567"
                  />
                </label>
              </div>
              <div className="admin-clients__form-grid">
                <label>
                  Client type
                  <select
                    value={form.client_type}
                    onChange={(e) => updateField("client_type", e.target.value)}
                    onDoubleClick={() => {
                      setClientTypeSaveError("");
                      setClientTypeModalOpen(true);
                    }}
                    disabled={clientTypesLoading}
                  >
                    {clientTypes.map((item) => (
                      <option key={item.key} value={item.key}>
                        {item.label}
                      </option>
                    ))}
                  </select>
                  <span
                    className="admin-clients__field-note"
                    role="button"
                    tabIndex={0}
                    onClick={() => {
                      setClientTypeSaveError("");
                      setClientTypeModalOpen(true);
                    }}
                    onKeyDown={(e) => {
                      if (e.key === "Enter" || e.key === " ") {
                        e.preventDefault();
                        setClientTypeSaveError("");
                        setClientTypeModalOpen(true);
                      }
                    }}
                  >
                    Double-click the dropdown to add a new client type.
                  </span>
                </label>
                <label>
                  Permission status
                  <select
                    value={form.photo_permission_status}
                    onChange={(e) => {
                      const nextStatus = e.target.value;
                      updateField("photo_permission_status", nextStatus);
                      if (nextStatus === "requested" && !form.photo_permission_requested_at) {
                        updateField("photo_permission_requested_at", formatLocalDateTimeValue());
                      }
                      if (nextStatus === "granted" && !form.photo_permission_granted_at) {
                        updateField("photo_permission_granted_at", formatLocalDateTimeValue());
                      }
                    }}
                  >
                    <option value="unknown">Unknown</option>
                    <option value="requested">Requested</option>
                    <option value="granted">Granted</option>
                    <option value="declined">Declined</option>
                  </select>
                </label>
              </div>
              <div className="admin-clients__permission-grid">
                <label>
                  Started at
                  <input
                    type="datetime-local"
                    value={toDateTimeLocalValue(form.started_at)}
                    onChange={(e) => updateField("started_at", fromDateTimeLocalValue(e.target.value))}
                  />
                </label>
                <label>
                  Requested at
                  <input
                    type="datetime-local"
                    value={toDateTimeLocalValue(form.photo_permission_requested_at)}
                    onChange={(e) => updateField("photo_permission_requested_at", fromDateTimeLocalValue(e.target.value))}
                  />
                </label>
                <label>
                  Granted at
                  <input
                    type="datetime-local"
                    value={toDateTimeLocalValue(form.photo_permission_granted_at)}
                    onChange={(e) => updateField("photo_permission_granted_at", fromDateTimeLocalValue(e.target.value))}
                  />
                </label>
              </div>
              <label>
                Notes
                <textarea rows={7} value={form.notes} onChange={(e) => updateField("notes", e.target.value)} />
              </label>
              <div className="admin-clients__actions">
                <button type="submit" className="admin-clients__primary" disabled={saving}>
                  {saving ? "Saving…" : form.id ? "Save Client" : "Create Client"}
                </button>
                {form.id ? (
                  <button type="button" className="admin-clients__danger" onClick={handleDelete} disabled={deleting}>
                    {deleting ? "Deleting…" : "Delete Client"}
                  </button>
                ) : null}
              </div>
            </form>
          ) : null}

          {activeTab === "email" ? (
            <div className="admin-clients__email">
              <div className="admin-clients__email-topbar">
                <div className="admin-clients__email-topbar-copy">
                  <div className="admin-clients__preview-title">Compose Email</div>
                  <div className="admin-clients__preview-sub">Choose a template or freeform draft, then send from here.</div>
                </div>
                <button
                  type="button"
                  className="admin-clients__primary admin-clients__email-send"
                  disabled={!form.id || !emailDraft.toEmail || !emailDraft.subject || sendingEmail}
                  onClick={handleSendEmail}
                >
                  {sendingEmail ? "Sending…" : "Send Email"}
                </button>
              </div>

              <div className="admin-clients__email-grid">
                <label>
                  Template
                  <select
                    value={emailDraft.templateKey}
                    onChange={(e) => applyTemplate(e.target.value)}
                    disabled={templatesLoading}
                  >
                    {templateOptions.map((template) => (
                      <option key={template.key} value={template.key}>
                        {template.label}
                      </option>
                    ))}
                  </select>
                </label>
                <label>
                  To
                  <input
                    type="email"
                    value={emailDraft.toEmail}
                    onChange={(e) => setEmailDraft((prev) => ({ ...prev, toEmail: e.target.value }))}
                  />
                </label>
                <label>
                  Cc
                  <input
                    type="text"
                    value={emailDraft.cc}
                    onChange={(e) => setEmailDraft((prev) => ({ ...prev, cc: e.target.value }))}
                    placeholder="comma-separated"
                  />
                </label>
                <label>
                  Bcc
                  <input
                    type="text"
                    value={emailDraft.bcc}
                    onChange={(e) => setEmailDraft((prev) => ({ ...prev, bcc: e.target.value }))}
                    placeholder="comma-separated"
                  />
                </label>
              </div>

              {selectedTemplate?.description ? (
                <div className="admin-clients__template-note">{selectedTemplate.description}</div>
              ) : null}

              <label className="admin-clients__stack">
                Subject
                <input
                  type="text"
                  value={emailDraft.subject}
                  onChange={(e) => setEmailDraft((prev) => ({ ...prev, subject: e.target.value }))}
                />
              </label>

              <div className="admin-clients__email-actions">
                <button type="button" className="admin-clients__secondary" onClick={() => openLinkModal("email")}>
                  Insert Link
                </button>
                <button
                  type="button"
                  className="admin-clients__secondary"
                  onClick={handleSaveDraft}
                  disabled={!form.id || savingDraft}
                >
                  {savingDraft ? "Saving Draft…" : "Save Draft"}
                </button>
                <button
                  type="button"
                  className="admin-clients__secondary"
                  onClick={() => applyTemplate(emailDraft.templateKey)}
                  disabled={emailDraft.templateKey === FREEFORM_TEMPLATE_KEY}
                >
                  Reset From Template
                </button>
              </div>

              <div className="admin-clients__subtabs" role="tablist" aria-label="Email content views">
                {[
                  { key: "plain", label: "Plain" },
                  { key: "html", label: "HTML" },
                  { key: "preview", label: "Preview" },
                ].map((tab) => (
                  <button
                    key={tab.key}
                    type="button"
                    role="tab"
                    aria-selected={emailView === tab.key}
                    className={`admin-clients__subtab ${emailView === tab.key ? "is-active" : ""}`}
                    onClick={() => setEmailView(tab.key)}
                  >
                    {tab.label}
                  </button>
                ))}
              </div>

              {emailView === "plain" ? (
                <label className="admin-clients__stack">
                  Plain text body
                  <textarea
                    ref={plainTextBodyRef}
                    rows={14}
                    value={emailDraft.message}
                    onSelect={() => rememberLinkSelection("email")}
                    onKeyUp={() => rememberLinkSelection("email")}
                    onClick={() => rememberLinkSelection("email")}
                    onChange={(e) => {
                      const nextMessage = e.target.value;
                      window.requestAnimationFrame(() => rememberLinkSelection("email"));
                      setEmailDraft((prev) => ({
                        ...prev,
                        message: nextMessage,
                        ...(emailHtmlManuallyEdited ? {} : { html: plainTextToHtml(nextMessage) }),
                      }));
                    }}
                  />
                </label>
              ) : null}

              {emailView === "html" ? (
                <label className="admin-clients__stack">
                  HTML body
                  <textarea
                    rows={14}
                    value={emailDraft.html}
                    onChange={(e) => {
                      const nextHtml = e.target.value;
                      setEmailHtmlManuallyEdited(true);
                      setEmailDraft((prev) => ({ ...prev, html: nextHtml }));
                    }}
                  />
                </label>
              ) : null}

              {emailView === "preview" ? (
                <div className="admin-clients__preview-wrap">
                  <div className="admin-clients__preview-head">
                    <div className="admin-clients__preview-title">Preview</div>
                    <div className="admin-clients__preview-sub">
                      {emailHtmlManuallyEdited && emailDraft.html.trim()
                        ? "Rendered from HTML body."
                        : "Using plain-text body."}
                    </div>
                  </div>
                  <div className="admin-clients__preview">
                    <div className="admin-clients__preview-subject">{emailDraft.subject || "No subject yet"}</div>
                    <div className="admin-clients__preview-body">
                      {emailHtmlManuallyEdited && emailDraft.html.trim() ? (
                        <div dangerouslySetInnerHTML={{ __html: emailDraft.html }} />
                      ) : (
                        <pre>{emailDraft.message || "No message yet"}</pre>
                      )}
                    </div>
                    <div className="admin-clients__preview-footer">
                      <a
                        href={BRAND.homeUrl}
                        target="_blank"
                        rel="noreferrer"
                        className="admin-clients__preview-logo-link"
                      >
                        <img src={BRAND.logoUrl} alt={BRAND.name} className="admin-clients__preview-logo" />
                      </a>
                    </div>
                  </div>
                </div>
              ) : null}

            </div>
          ) : null}

          {activeTab === "text" ? (
            <div className="admin-clients__text">
              <div className="admin-clients__email-topbar">
                <div className="admin-clients__email-topbar-copy">
                  <div className="admin-clients__preview-title">Compose Text</div>
                  <div className="admin-clients__preview-sub">Use your Mac or phone Messages app to send this draft.</div>
                </div>
                <button
                  type="button"
                  className="admin-clients__primary admin-clients__email-send"
                  disabled={!form.id || !textDraft.toPhone}
                  onClick={handleOpenTextMessage}
                >
                  Open in Messages
                </button>
              </div>

              <div className="admin-clients__email-grid">
                <label>
                  To
                  <input
                    type="text"
                    value={textDraft.toPhone}
                    onChange={(e) => setTextDraft((prev) => ({ ...prev, toPhone: e.target.value }))}
                    placeholder="Client phone number"
                  />
                </label>
              </div>

              <div className="admin-clients__email-actions">
                <button
                  type="button"
                  className="admin-clients__secondary"
                  onClick={() => openLinkModal("text")}
                >
                  Insert Link
                </button>
                <button
                  type="button"
                  className="admin-clients__secondary"
                  onClick={handleCopyTextMessage}
                  disabled={!textDraft.message.trim()}
                >
                  Copy Text
                </button>
              </div>

              <label className="admin-clients__stack">
                Message
                <textarea
                  ref={textBodyRef}
                  rows={10}
                  value={textDraft.message}
                  onSelect={() => rememberLinkSelection("text")}
                  onKeyUp={() => rememberLinkSelection("text")}
                  onClick={() => rememberLinkSelection("text")}
                  onChange={(e) => {
                    const nextMessage = e.target.value;
                    window.requestAnimationFrame(() => rememberLinkSelection("text"));
                    setTextDraft((prev) => ({ ...prev, message: nextMessage }));
                  }}
                  placeholder="Hi Lorena, I wanted to share this with you..."
                />
              </label>
            </div>
          ) : null}

          {activeTab === "activity" ? (
            <div className="admin-clients__activity">
              {activityLoading ? <div className="admin-clients__empty">Loading activity…</div> : null}
              {!activityLoading && !form.id ? <div className="admin-clients__empty">Save a client to start tracking activity.</div> : null}
              {!activityLoading && form.id && activityItems.length === 0 ? (
                <div className="admin-clients__empty">No activity yet.</div>
              ) : null}
              {!activityLoading && form.id && activityItems.length > 0 ? (
                <div className="admin-clients__activity-table-wrap">
                  <table className="admin-clients__activity-table">
                    <thead>
                      <tr>
                        <th>When</th>
                        <th>Type</th>
                        <th>Summary</th>
                        <th>To / From</th>
                        <th>Subject</th>
                        <th>View</th>
                        <th>Delete</th>
                      </tr>
                    </thead>
                    <tbody>
                      {activityItems.map((item) => (
                        <tr key={item.id} className={isUnreadSiteNote(item) ? "is-unread" : ""}>
                          <td>{formatDateTime(item.occurred_at)}</td>
                          <td>{item.activity_type}</td>
                          <td>{item.summary || "—"}</td>
                          <td>{item.email?.direction === "inbound" ? item.email?.from_email || "—" : item.email?.to_email || "—"}</td>
                          <td>{item.email?.subject || "—"}</td>
                          <td>
                            <button
                              type="button"
                              className="admin-clients__table-btn"
                              onClick={() => { void handleOpenActivity(item); }}
                            >
                              Open
                            </button>
                          </td>
                          <td>
                            <button
                              type="button"
                              className="admin-clients__table-btn admin-clients__table-btn--danger"
                              onClick={() => handleDeleteActivity(item)}
                              disabled={deletingActivityId === String(activityRowId(item))}
                            >
                              {deletingActivityId === String(activityRowId(item)) ? "Deleting…" : "Delete"}
                            </button>
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              ) : null}
            </div>
          ) : null}

          {activeTab === "photos" ? (
            <div className="admin-clients__photos">
              {photosLoading ? <div className="admin-clients__empty">Loading photos…</div> : null}
              {!photosLoading && !form.id ? <div className="admin-clients__empty">Save a client to look up linked photos.</div> : null}
              {!photosLoading && form.id && photoItems.length === 0 ? (
                <div className="admin-clients__empty">No linked photos yet.</div>
              ) : null}
              {!photosLoading && form.id && photoItems.length > 0 ? (
                <div className="admin-clients__photo-grid">
                  {photoItems.map((item) => (
                    <article key={item.photo_library_id} className="admin-clients__photo-card">
                      <a
                        href={item.image_url || item.rel_path || "#"}
                        target="_blank"
                        rel="noreferrer"
                        className="admin-clients__photo-thumb-link"
                      >
                        <img
                          src={item.image_url || item.rel_path}
                          alt={item.alt_text || item.title || item.filename || `Photo ${item.photo_library_id}`}
                          className="admin-clients__photo-thumb"
                          loading="lazy"
                        />
                      </a>
                      <div className="admin-clients__photo-body">
                        <div className="admin-clients__photo-title">{item.title || item.filename || `Photo #${item.photo_library_id}`}</div>
                        <div className="admin-clients__photo-meta">
                          #{item.photo_library_id} · {item.source_type || "photo"}
                          {item.has_palette ? " · palette" : ""}
                          {item.show_in_gallery ? " · gallery" : ""}
                        </div>
                        <div className="admin-clients__photo-actions">
                          <a
                            href={clientPhotoHref(item.photo_library_id)}
                            className="admin-clients__table-btn"
                          >
                            Open in Library
                          </a>
                        </div>
                      </div>
                    </article>
                  ))}
                </div>
              ) : null}
            </div>
          ) : null}

              </>
            ) : (
              <AdminEmptyState
                title="Select a client"
                message="Choose a client from the list, or create a new client."
              />
            )}
          </AdminDetailPane>
        }
      />

      <InsertLinkModal
        open={linkModalOpen}
        onClose={() => setLinkModalOpen(false)}
        onInsert={handleLinkInsert}
        initialSourceTag={linkModalTarget}
      />
      <LookupTypeManagerModal
        open={clientTypeModalOpen}
        onClose={() => setClientTypeModalOpen(false)}
        title="Client Types"
        subtitle="Add new client types here. Existing types stay available for selection."
        items={clientTypes}
        loading={clientTypesLoading}
        saving={savingClientType}
        error={clientTypeSaveError}
        inputLabel="New client type"
        placeholder="Designer, realtor, builder..."
        createButtonLabel="Add Client Type"
        emptyMessage="No client types yet."
        helperText="A key is generated automatically from the label. Types can be added here but not deleted."
        onCreate={handleCreateClientType}
      />
      <ModalDialog
        open={Boolean(activityDetail)}
        onClose={() => setActivityDetail(null)}
        title={activityDetail?.summary || activityDetail?.activity_type || "Activity"}
        subtitle={activityDetail ? formatDateTime(activityDetail.occurred_at) : ""}
        width="760px"
        footer={activityDetail ? (
          <button
            type="button"
            className="admin-clients__table-btn admin-clients__table-btn--danger"
            onClick={() => handleDeleteActivity(activityDetail)}
            disabled={deletingActivityId === String(activityRowId(activityDetail))}
          >
            {deletingActivityId === String(activityRowId(activityDetail)) ? "Deleting…" : "Delete Activity"}
          </button>
        ) : null}
      >
        {activityDetail ? (
          <div className="admin-clients__activity-detail">
            {isSiteNoteActivity(activityDetail) ? (
              <>
                <div className="admin-clients__activity-detail-grid admin-clients__activity-detail-grid--note">
                  <div><strong>Sent:</strong> {formatDateTime(activityDetail.occurred_at) || "—"}</div>
                </div>
                <div className="admin-clients__activity-detail-section">
                  <div className="admin-clients__activity-detail-label">Message</div>
                  <div className="admin-clients__activity-detail-body">
                    <pre>{siteNoteMessage(activityDetail) || "No message text."}</pre>
                  </div>
                </div>
              </>
            ) : (
              <>
                <div className="admin-clients__activity-detail-grid">
                  <div><strong>Type:</strong> {activityDetail.activity_type}</div>
                  <div><strong>When:</strong> {formatDateTime(activityDetail.occurred_at)}</div>
                  {activityDetail.email?.from_email ? <div><strong>From:</strong> {activityDetail.email.from_email}</div> : null}
                  <div><strong>To:</strong> {activityDetail.email?.to_email || "—"}</div>
                  <div><strong>Subject:</strong> {activityDetail.email?.subject || "—"}</div>
                  {activityDetail.email?.cc_emails ? <div><strong>Cc:</strong> {activityDetail.email.cc_emails}</div> : null}
                  {activityDetail.email?.bcc_emails ? <div><strong>Bcc:</strong> {activityDetail.email.bcc_emails}</div> : null}
                </div>
                {activityDetail.email?.html_body ? (
              <div className="admin-clients__activity-detail-section">
                <div className="admin-clients__activity-detail-label">
                  Exact sent email
                </div>
                <div
                  className="admin-clients__activity-detail-body"
                  dangerouslySetInnerHTML={{ __html: activityDetail.email.html_body }}
                />
              </div>
                ) : null}
                {activityDetail.email?.text_body ? (
              <div className="admin-clients__activity-detail-section">
                <div className="admin-clients__activity-detail-label">Plain text record</div>
                <div className="admin-clients__activity-detail-body">
                  <pre>{activityDetail.email.text_body}</pre>
                </div>
              </div>
                ) : activityDetail.details ? (
                  <div className="admin-clients__activity-detail-body">{activityDetail.details}</div>
                ) : null}
              </>
            )}
          </div>
        ) : null}
      </ModalDialog>
    </>
  );
}
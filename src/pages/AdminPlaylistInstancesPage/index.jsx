import { useEffect, useMemo, useRef, useState } from "react";
import { useNavigate } from "react-router-dom";
import { API_FOLDER, SHARE_FOLDER } from "@helpers/config";
import KickerDropdown from "@components/KickerDropdown";
import EmailShareModal from "@components/EmailShareModal/EmailShareModal";
import "./admin-playlist-instances.css";

const LIST_URL = `${API_FOLDER}/v2/admin/playlist-instances/list.php`;
const GET_URL = `${API_FOLDER}/v2/admin/playlist-instances/get.php`;
const SAVE_URL = `${API_FOLDER}/v2/admin/playlist-instances/save.php`;
const PLAYLISTS_URL = `${API_FOLDER}/v2/admin/playlists/list.php`;
const CTAS_LIST_URL = `${API_FOLDER}/v2/admin/ctas/list.php`;
const EMAIL_TEMPLATES_URL = `${API_FOLDER}/v2/admin/email-templates.php`;
const SEND_EMAIL_URL = `${API_FOLDER}/v2/admin/playlist-instances/send-email.php`;

const AUDIENCE_OPTIONS = [
  { value: "any", label: "Any" },
  { value: "hoa", label: "HOA" },
  { value: "homeowner", label: "Homeowner" },
  { value: "contractor", label: "Contractor" },
  { value: "pinterest", label: "Pinterest" },
  { value: "admin", label: "Admin" },
];

const emptyInstance = {
  playlist_instance_id: null,
  playlist_id: "",
  instance_name: "",
  display_title: "",
  display_subtitle: "",
  instance_notes: "",
  intro_layout: "default",
  intro_title: "",
  intro_subtitle: "",
  intro_body: "",
  intro_image_url: "",
  demo_enabled: false,
  audience: "any",
  cta_context_key: "default",
  cta_overrides: {},
  share_enabled: true,
  share_title: "",
  share_description: "",
  share_image_url: "",
  skip_intro_on_replay: true,
  hide_stars: false,
  is_active: true,
  created_from_instance: "",
  kicker_id: "",
};

const DRAFT_KEY = "admin:playlist-instance-draft";

function coerceBoolean(value) {
  return Boolean(value);
}

export default function AdminPlaylistInstancesPage() {
  const navigate = useNavigate();
  const [query, setQuery] = useState("");
  const [audienceFilter, setAudienceFilter] = useState("all");
  const [items, setItems] = useState([]);
  const [playlists, setPlaylists] = useState([]);
  const [ctaLibrary, setCtaLibrary] = useState([]);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState("");
  const [activeId, setActiveId] = useState(null);
  const [form, setForm] = useState(emptyInstance);
  const [saving, setSaving] = useState(false);
  const [saveError, setSaveError] = useState("");
  const [saveStatus, setSaveStatus] = useState("");
  const [emailTemplates, setEmailTemplates] = useState([]);
  const [emailModal, setEmailModal] = useState({
    open: false,
    templateKey: "",
    toEmail: "",
    subject: "",
    message: "",
    htmlBody: "",
    status: { loading: false, error: "", success: "" },
  });
  const [sendFormat, setSendFormat] = useState("html");
  const [ctaPickerOpen, setCtaPickerOpen] = useState(false);
  const [dragCtaId, setDragCtaId] = useState(null);
  const didLoadDraft = useRef(false);

  useEffect(() => {
    if (didLoadDraft.current) return;
    if (form.playlist_instance_id) return;
    try {
      const raw = window.sessionStorage.getItem(DRAFT_KEY);
      if (!raw) return;
      const parsed = JSON.parse(raw);
      if (parsed && typeof parsed === "object") {
        setForm((prev) => ({ ...prev, ...parsed, playlist_instance_id: null }));
        didLoadDraft.current = true;
      }
    } catch {
      /* ignore */
    }
  }, [form.playlist_instance_id]);

  useEffect(() => {
    if (form.playlist_instance_id) return;
    try {
      window.sessionStorage.setItem(DRAFT_KEY, JSON.stringify(form));
    } catch {
      /* ignore */
    }
  }, [form]);

  useEffect(() => {
    fetchPlaylists();
    fetchCtas();
    fetchEmailTemplates();
  }, []);

  useEffect(() => {
    fetchInstances();
  }, []);

  useEffect(() => {
    if (!activeId) return;
    fetchInstance(activeId);
  }, [activeId]);

  // CTA groups no longer used for instances


  async function fetchPlaylists() {
    try {
      const res = await fetch(`${PLAYLISTS_URL}?_=${Date.now()}`, {
        credentials: "include",
      });
      const data = await res.json();
      if (!res.ok || !data?.ok) throw new Error(data?.error || "Failed to load playlists");
      setPlaylists(data.items || []);
    } catch (err) {
      // playlist list is optional for now
    }
  }

  async function fetchCtas() {
    try {
      const res = await fetch(`${CTAS_LIST_URL}?_=${Date.now()}`, {
        credentials: "include",
      });
      const data = await res.json();
      if (!res.ok || !data?.ok) throw new Error(data?.error || "Failed to load CTAs");
      setCtaLibrary(data.items || []);
    } catch (err) {
      setError(err?.message || "Failed to load CTAs");
    }
  }


  function parseOverrides(raw) {
    if (!raw) return {};
    if (typeof raw === "object") return raw;
    try {
      const decoded = JSON.parse(raw);
      return decoded && typeof decoded === "object" ? decoded : {};
    } catch {
      return {};
    }
  }

  async function fetchInstances() {
    setLoading(true);
    setError("");
    try {
      const qs = new URLSearchParams();
      if (query.trim()) qs.set("q", query.trim());
      qs.set("_", Date.now().toString());
      const res = await fetch(`${LIST_URL}?${qs.toString()}`, {
        credentials: "include",
      });
      const text = await res.text();
      let data;
      try {
        data = JSON.parse(text);
      } catch (parseError) {
        throw new Error(`Unexpected response: ${text.slice(0, 200)}`);
      }
      if (!res.ok || !data?.ok) throw new Error(data?.error || "Failed to load instances");
      setItems(data.items || []);
    } catch (err) {
      setError(err?.message || "Failed to load instances");
    } finally {
      setLoading(false);
    }
  }

  async function fetchInstance(id) {
    setError("");
    try {
      const res = await fetch(`${GET_URL}?id=${id}&_=${Date.now()}`, {
        credentials: "include",
      });
      const data = await res.json();
      if (!res.ok || !data?.ok) throw new Error(data?.error || "Failed to load instance");
      const next = {
        ...emptyInstance,
        ...data.item,
        audience: data.item?.audience || "any",
        cta_context_key: data.item?.cta_context_key || "default",
        share_enabled: coerceBoolean(data.item?.share_enabled),
        skip_intro_on_replay: coerceBoolean(data.item?.skip_intro_on_replay),
        hide_stars: coerceBoolean(data.item?.hide_stars),
        demo_enabled: coerceBoolean(data.item?.demo_enabled),
        is_active: coerceBoolean(data.item?.is_active),
        kicker_id: data.item?.kicker_id ? String(data.item.kicker_id) : "",
      };
      setForm({
        ...next,
        cta_overrides: parseOverrides(data.item?.cta_overrides),
      });
      setSaveStatus("");
      setSaveError("");
    } catch (err) {
      setError(err?.message || "Failed to load instance");
    }
  }

  function updateForm(field, value) {
    setForm((prev) => ({ ...prev, [field]: value }));
    setSaveStatus("");
    setSaveError("");
  }

  function updateCtaOverride(ctaId, key, value) {
    setForm((prev) => {
      const next = { ...(prev.cta_overrides || {}) };
      const base = next[ctaId] && typeof next[ctaId] === "object" ? { ...next[ctaId] } : {};
      if (value === "" || value === null || value === undefined) {
        delete base[key];
      } else {
        base[key] = value;
      }
      if (Object.keys(base).length === 0) {
        delete next[ctaId];
      } else {
        next[ctaId] = base;
      }
      return { ...prev, cta_overrides: next };
    });
    setSaveStatus("");
    setSaveError("");
  }

  function handleNew() {
    setActiveId(null);
    try {
      const raw = window.sessionStorage.getItem(DRAFT_KEY);
      if (raw) {
        const parsed = JSON.parse(raw);
        setForm((prev) => ({ ...prev, ...parsed, playlist_instance_id: null }));
      } else {
        setForm(emptyInstance);
      }
    } catch {
      setForm(emptyInstance);
    }
    setSaveStatus("");
    setSaveError("");
  }

  function handleDuplicate() {
    const sourceId = form.playlist_instance_id;
    if (!sourceId) return;
    setActiveId(null);
    setForm({
      ...emptyInstance,
      ...form,
      playlist_instance_id: null,
      created_from_instance: sourceId,
      cta_overrides: JSON.parse(JSON.stringify(form.cta_overrides || {})),
    });
    setSaveStatus("");
    setSaveError("");
  }

  function buildShareUrl(id, audience = "") {
    if (!id) return "";
    const params = new URLSearchParams();
    params.set("id", String(id));
    if (audience && audience !== "any") params.set("aud", audience);
    return `${SHARE_FOLDER}/playlist.php?${params.toString()}`;
  }

  function hydrateTemplate(text, { title, link }) {
    return String(text || "")
      .replace(/\{title\}/gi, title || "")
      .replace(/\{link\}/gi, link || "");
  }

  function hydrateHtmlTemplate(html, { title, link }) {
    return String(html || "")
      .replace(/\{\{\s*title\s*\}\}/gi, title || "")
      .replace(/\{\{\s*link\s*\}\}/gi, link || "")
      .replace(/\{title\}/gi, title || "")
      .replace(/\{link\}/gi, link || "");
  }

  async function fetchEmailTemplates() {
    try {
      const res = await fetch(`${EMAIL_TEMPLATES_URL}?_=${Date.now()}`, {
        credentials: "include",
      });
      const data = await res.json();
      if (!res.ok || !data?.ok) throw new Error(data?.error || "Failed to load templates");
      setEmailTemplates(data.templates || []);
    } catch (err) {
      setEmailTemplates([]);
    }
  }

  function handleShare() {
    const id = activeId;
    if (!id) return;
    const url = buildShareUrl(id);
    if (navigator.share) {
      navigator.share({ title: "ColorFix Playlist", url }).catch(() => {});
      return;
    }
    if (navigator.clipboard?.writeText) {
      navigator.clipboard.writeText(url).catch(() => {});
    }
    const body = encodeURIComponent(url);
    window.location.href = `sms:&body=${body}`;
  }

  function handleCopyLink() {
    const id = activeId || form.playlist_instance_id;
    if (!id || !navigator.clipboard?.writeText) return;
    navigator.clipboard.writeText(buildShareUrl(id)).catch(() => {});
  }

  function handleCopyPinterestLink() {
    const id = activeId || form.playlist_instance_id;
    if (!id || !navigator.clipboard?.writeText) return;
    navigator.clipboard.writeText(buildShareUrl(id, "pinterest")).catch(() => {});
  }

  function openEmailModal() {
    if (!activeId) return;
    const shareUrl = buildShareUrl(activeId);
    const instanceLabel = form.instance_name || form.display_title || `Instance #${activeId}`;
    const fallbackTemplate = emailTemplates[0] || null;
    const templateKey = fallbackTemplate?.key || "";
    const subject = hydrateTemplate(fallbackTemplate?.subject || "Your ColorFix playlist", {
      title: instanceLabel,
      link: shareUrl,
    });
    const message = hydrateTemplate(fallbackTemplate?.message || "I wanted to share this ColorFix playlist with you.", {
      title: instanceLabel,
      link: shareUrl,
    });
    const htmlBody = hydrateHtmlTemplate(fallbackTemplate?.html || "", {
      title: instanceLabel,
      link: shareUrl,
    });
    setEmailModal({
      open: true,
      templateKey,
      toEmail: "",
      subject,
      message,
      htmlBody,
      status: { loading: false, error: "", success: "" },
    });
    setSendFormat(htmlBody ? "html" : "text");
  }

  function closeEmailModal() {
    setEmailModal((prev) => ({ ...prev, open: false }));
  }

  function handleTemplateChange(nextKey) {
    const shareUrl = buildShareUrl(activeId);
    const instanceLabel = form.instance_name || form.display_title || `Instance #${activeId}`;
    const template = emailTemplates.find((item) => item.key === nextKey);
    setEmailModal((prev) => ({
      ...prev,
      templateKey: nextKey,
      subject: hydrateTemplate(template?.subject || prev.subject, { title: instanceLabel, link: shareUrl }),
      message: hydrateTemplate(template?.message || prev.message, { title: instanceLabel, link: shareUrl }),
      htmlBody: hydrateHtmlTemplate(template?.html || prev.htmlBody, { title: instanceLabel, link: shareUrl }),
      status: { loading: false, error: "", success: "" },
    }));
    const nextHtml = hydrateHtmlTemplate(template?.html || "", { title: instanceLabel, link: shareUrl });
    setSendFormat(nextHtml ? "html" : "text");
  }

  async function handleSendEmail() {
    if (!activeId) return;
    const toEmail = emailModal.toEmail.trim();
    if (!toEmail) {
      setEmailModal((prev) => ({
        ...prev,
        status: { loading: false, error: "Recipient email required.", success: "" },
      }));
      return;
    }
    setEmailModal((prev) => ({
      ...prev,
      status: { loading: true, error: "", success: "" },
    }));
    try {
      const shareUrl = buildShareUrl(activeId);
      const res = await fetch(SEND_EMAIL_URL, {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          playlist_instance_id: activeId,
          to_email: toEmail,
          subject: emailModal.subject,
          message: emailModal.message,
          html_body: sendFormat === "html" ? emailModal.htmlBody : "",
          title: form.instance_name || form.display_title || `Instance #${activeId}`,
          share_url: shareUrl,
          template_key: emailModal.templateKey,
        }),
      });
      const data = await res.json();
      if (!res.ok || !data?.ok) throw new Error(data?.error || "Failed to send email");
      setEmailModal((prev) => ({
        ...prev,
        status: { loading: false, error: "", success: "Email sent." },
      }));
    } catch (err) {
      setEmailModal((prev) => ({
        ...prev,
        status: { loading: false, error: err?.message || "Failed to send email", success: "" },
      }));
    }
  }

  async function handleSave() {
    setSaving(true);
    setSaveError("");
    setSaveStatus("");
    try {
      if (missingArticleIds.length > 0) {
        throw new Error("Article CTA requires an Article ID.");
      }
      const payload = {
        ...form,
        playlist_id: Number(form.playlist_id) || 0,
        demo_enabled: Boolean(form.demo_enabled),
        cta_overrides: form.cta_overrides || {},
        created_from_instance: form.created_from_instance === "" ? null : Number(form.created_from_instance),
        kicker_id: form.kicker_id === "" ? null : Number(form.kicker_id),
      };
      const res = await fetch(SAVE_URL, {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(payload),
      });
      const data = await res.json();
      if (!res.ok || !data?.ok) throw new Error(data?.error || "Save failed");
      setSaveStatus("Saved");
      try {
        window.sessionStorage.removeItem(DRAFT_KEY);
      } catch {
        /* ignore */
      }
      const newId = data.playlist_instance_id;
      setActiveId(newId);
      setForm((prev) => ({ ...prev, playlist_instance_id: newId }));
      await fetchInstances();
      if (newId) {
        await fetchInstance(newId);
      }
    } catch (err) {
      setSaveError(err?.message || "Save failed");
    } finally {
      setSaving(false);
    }
  }

  const filteredItems = useMemo(() => {
    const sorted = [...(items || [])].sort((a, b) => {
      const aLabel = String(a?.instance_name || a?.display_title || a?.playlist_instance_id || "").toLowerCase();
      const bLabel = String(b?.instance_name || b?.display_title || b?.playlist_instance_id || "").toLowerCase();
      return aLabel.localeCompare(bLabel);
    });
    return sorted.filter((item) => {
      const itemAudience = String(item?.audience || "any").toLowerCase();
      if (audienceFilter !== "all" && itemAudience !== audienceFilter) {
        return false;
      }
      if (!query.trim()) return true;
      const needle = query.trim().toLowerCase();
      const name = (item?.instance_name || "").toLowerCase();
      const displayTitle = (item?.display_title || "").toLowerCase();
      const id = String(item?.playlist_instance_id || "");
      return name.includes(needle) || displayTitle.includes(needle) || id.includes(needle);
    });
  }, [audienceFilter, items, query]);

  const audienceLabelMap = useMemo(() => {
    return AUDIENCE_OPTIONS.reduce((acc, option) => {
      acc[option.value] = option.label;
      return acc;
    }, {});
  }, []);

  const playlistOptions = useMemo(() => {
    return playlists.map((row) => ({
      id: row.playlist_id,
      label: `${row.playlist_id} — ${row.title}`,
    }));
  }, [playlists]);


  const overrideMeta = useMemo(() => {
    const raw = form.cta_overrides || {};
    const extra = Array.isArray(raw._cta_ids) ? raw._cta_ids.map(String) : [];
    return {
      extraIds: extra,
    };
  }, [form.cta_overrides]);

  const groupItemIds = useMemo(() => new Set(), []);

  const selectedCtas = useMemo(() => {
    const map = new Map();
    ctaLibrary.forEach((cta) => {
      map.set(String(cta.cta_id), cta);
    });
    const selected = [];
    overrideMeta.extraIds.forEach((id) => {
      if (groupItemIds.has(id)) return;
      const cta = map.get(id);
      if (cta) selected.push(cta);
    });
    return selected;
  }, [ctaLibrary, groupItemIds, overrideMeta]);

  const availableCtas = useMemo(() => {
    const selectedIds = new Set(selectedCtas.map((cta) => String(cta.cta_id)));
    return ctaLibrary.filter((cta) => !selectedIds.has(String(cta.cta_id)));
  }, [ctaLibrary, selectedCtas]);

  const missingArticleIds = useMemo(() => {
    return selectedCtas
      .filter((cta) => cta?.type_action_key === "article_link")
      .filter((cta) => {
        const overrides = (form.cta_overrides || {})[cta.cta_id] || {};
        let baseParams = {};
        if (cta?.params) {
          try {
            baseParams = typeof cta.params === "string" ? JSON.parse(cta.params) : cta.params;
          } catch {
            baseParams = {};
          }
        }
        const articleId = overrides.article_id || baseParams.article_id;
        return !articleId;
      })
      .map((cta) => cta.cta_id);
  }, [selectedCtas, form.cta_overrides]);

  function updateOverrideMeta(next) {
    updateForm("cta_overrides", {
      ...(form.cta_overrides || {}),
      ...next,
    });
  }

  function addCtaToInstance(ctaId) {
    const id = String(ctaId);
    const next = overrideMeta.extraIds.slice();
    if (!next.includes(id)) next.push(id);
    updateOverrideMeta({ _cta_ids: next });
  }

  function removeCtaFromInstance(ctaId) {
    const id = String(ctaId);
    const next = overrideMeta.extraIds.filter((value) => value !== id);
    updateOverrideMeta({ _cta_ids: next });
  }

  function reorderSelectedCtas(fromId, toId) {
    if (!fromId || !toId || fromId === toId) return;
    const ids = overrideMeta.extraIds.slice();
    const fromIndex = ids.indexOf(fromId);
    const toIndex = ids.indexOf(toId);
    if (fromIndex < 0 || toIndex < 0) return;
    ids.splice(fromIndex, 1);
    ids.splice(toIndex, 0, fromId);
    updateOverrideMeta({ _cta_ids: ids });
  }


  return (
    <div className="admin-playlist-instances">
      <div className="playlist-panel list-panel">
        <div className="panel-header">
          <div className="panel-title">Playlist Instances</div>
          <div className="header-actions">
            <select value={audienceFilter} onChange={(e) => setAudienceFilter(e.target.value)}>
              <option value="all">All audiences</option>
              {AUDIENCE_OPTIONS.map((opt) => (
                <option key={opt.value} value={opt.value}>
                  {opt.label}
                </option>
              ))}
            </select>
            <button type="button" className="primary-btn" onClick={handleNew}>
              New Instance
            </button>
          </div>
        </div>

        <div className="panel-controls">
          <input
            type="text"
            placeholder="Search by id or name"
            value={query}
            onChange={(e) => setQuery(e.target.value)}
          />
        </div>

        {loading && <div className="panel-status">Loading…</div>}
        {error && <div className="panel-status error">{error}</div>}

        <div className="panel-list">
          {filteredItems.map((item) => (
            <button
              type="button"
              key={item.playlist_instance_id}
              className={`list-row ${activeId === item.playlist_instance_id ? "active" : ""}`}
              onClick={() => setActiveId(item.playlist_instance_id)}
            >
              <div className="row-title">
                #{item.playlist_instance_id} {item.instance_name || "Untitled"}
              </div>
              <div className="row-audience">
                {audienceLabelMap[item.audience] || item.audience || "Any"}
              </div>
            </button>
          ))}
        </div>

        <div className="mobile-share">
          <div className="mobile-share__title">Share Playlist Instance</div>
          {activeId ? (
            <div className="mobile-share__selected">
              {items.find((item) => item.playlist_instance_id === activeId)?.instance_name || "Untitled"}
            </div>
          ) : (
            <div className="mobile-share__selected">Select an instance to share</div>
          )}
          <div className="mobile-share__actions">
            <button type="button" onClick={handleCopyLink} disabled={!activeId}>
              Copy Link
            </button>
            <button type="button" className="primary-btn" onClick={handleShare} disabled={!activeId}>
              Share via Text
            </button>
            <button type="button" onClick={openEmailModal} disabled={!activeId}>
              Email Link
            </button>
          </div>
          {activeId && (
            <div className="mobile-share__hint">
              {buildShareUrl(activeId)}
            </div>
          )}
        </div>
      </div>

      <div className="playlist-panel editor-panel">
        <div className="panel-header">
          <div className="panel-title">
            {form.playlist_instance_id ? `Instance #${form.playlist_instance_id}` : "New Instance"}
          </div>
          <div className="panel-actions">
            <button
              type="button"
              className="primary-btn"
              onClick={() => navigate("/admin/playlists/new")}
            >
              New Playlist
            </button>
            <button
              type="button"
              onClick={handleDuplicate}
              disabled={!form.playlist_instance_id}
            >
              Copy Instance
            </button>
            <button
              type="button"
              onClick={handleCopyLink}
              disabled={!form.playlist_instance_id}
            >
              Copy Link
            </button>
            <button
              type="button"
              onClick={handleCopyPinterestLink}
              disabled={!form.playlist_instance_id}
            >
              Copy Pinterest URL
            </button>
            <button
              type="button"
              onClick={() => {
                if (!form.playlist_instance_id) return;
                const params = new URLSearchParams();
                if (form.audience && form.audience !== "any") params.set("aud", form.audience);
                if (form.demo_enabled) params.set("demo", "1");
                const qs = params.toString();
                const url = `${window.location.origin}/playlist/${form.playlist_instance_id}${qs ? `?${qs}` : ""}`;
                window.open(url, "_blank", "noopener");
              }}
              disabled={!form.playlist_instance_id}
            >
              Open Live URL
            </button>
            <button
              type="button"
              onClick={() => {
                if (!form.playlist_instance_id) return;
                const params = new URLSearchParams();
                if (form.audience && form.audience !== "any") params.set("aud", form.audience);
                if (form.demo_enabled) params.set("demo", "1");
                const suffix = params.toString();
                navigate(`/admin/player-preview/${form.playlist_instance_id || ""}${suffix ? `?${suffix}` : ""}`);
              }}
              disabled={!form.playlist_instance_id}
            >
              Preview
            </button>
            <button
              type="button"
              className="primary-btn"
              onClick={handleSave}
              disabled={saving || (!form.playlist_instance_id && !form.playlist_id)}
            >
              {saving ? "Saving..." : "Save"}
            </button>
          </div>
        </div>

    
        <div className="instance-section">
          <div className="section-title">Playlist</div>
          <div className="form-grid">
            <label>
              Instance name
              <input
                type="text"
                value={form.instance_name}
                onChange={(e) => updateForm("instance_name", e.target.value)}
              />
            </label>

            <label>
              Display title
              <input
                type="text"
                value={form.display_title || ""}
                onChange={(e) => updateForm("display_title", e.target.value)}
                placeholder="Shown to viewers (thumbnail title)"
              />
            </label>

            <label>
              Display subtitle
              <input
                type="text"
                value={form.display_subtitle || ""}
                onChange={(e) => updateForm("display_subtitle", e.target.value)}
                placeholder="Shown under the title on playlist set pages"
              />
            </label>

            <label>
              Kicker (optional)
              <KickerDropdown
                value={form.kicker_id}
                onChange={(next) => updateForm("kicker_id", next || "")}
              />
            </label>

            <label>
              Playlist
              <select
                value={form.playlist_id}
                onChange={(e) => updateForm("playlist_id", e.target.value)}
              >
                <option value="">Select playlist</option>
                {playlistOptions.map((opt) => (
                  <option key={opt.id} value={opt.id}>
                    {opt.label}
                  </option>
                ))}
              </select>
              {form.playlist_id && (
                <button
                  type="button"
                  className="link-btn"
                  onClick={() => navigate(`/admin/playlists/${form.playlist_id}`)}
                >
                  Edit playlist
                </button>
              )}
            </label>

            <label>
              Instance notes
              <textarea
                rows={3}
                value={form.instance_notes || ""}
                onChange={(e) => updateForm("instance_notes", e.target.value)}
              />
            </label>
          </div>

          <div className="form-grid">
            <label className="checkbox-row">
              <input
                type="checkbox"
                checked={form.skip_intro_on_replay}
                onChange={(e) => updateForm("skip_intro_on_replay", e.target.checked)}
              />
              Skip intro on replay
            </label>

            <label className="checkbox-row">
              <input
                type="checkbox"
                checked={form.demo_enabled}
                onChange={(e) => updateForm("demo_enabled", e.target.checked)}
              />
              Demo flow (adds demo=1)
            </label>

            <label className="checkbox-row">
              <input
                type="checkbox"
                checked={form.hide_stars}
                onChange={(e) => updateForm("hide_stars", e.target.checked)}
              />
              Hide stars
            </label>

            <label className="checkbox-row">
              <input
                type="checkbox"
                checked={form.is_active}
                onChange={(e) => updateForm("is_active", e.target.checked)}
              />
              Active
            </label>

            <label>
              Created from instance (optional)
              <input
                type="number"
                value={form.created_from_instance}
                onChange={(e) => updateForm("created_from_instance", e.target.value)}
              />
            </label>
          </div>
        </div>

        <div className="instance-section cta-section">
          <div className="section-title">CTA Settings</div>
          <div className="cta-controls">
            <label className="cta-context">
              Audience
              <select
                value={form.audience || "any"}
                onChange={(e) => updateForm("audience", e.target.value)}
              >
                {AUDIENCE_OPTIONS.map((opt) => (
                  <option key={opt.value} value={opt.value}>
                    {opt.label}
                  </option>
                ))}
              </select>
            </label>
          </div>

          <div className="cta-picker-trigger">
            <button type="button" className="primary-btn" onClick={() => setCtaPickerOpen(true)}>
              CTAs
            </button>
            <span className="cta-picker-note">Add/remove CTAs for this instance</span>
          </div>

          <div className="cta-group-list">
            {selectedCtas.length === 0 && (
              <div className="cta-empty">No CTAs selected for this instance.</div>
            )}
            {selectedCtas.map((cta) => (
              <div key={cta.cta_id} className="cta-item">
                <div className="cta-item-head">
                  <div className="cta-item-title">{cta.label}</div>
                  <div className="cta-item-meta">{cta.type_label}</div>
                </div>
              </div>
            ))}
          </div>
        </div>

            <div className="instance-section">
          <div className="section-title">Share Metadata</div>
          <div className="form-grid">
            <label className="checkbox-row">
              <input
                type="checkbox"
                checked={form.share_enabled}
                onChange={(e) => updateForm("share_enabled", e.target.checked)}
              />
              Share enabled
            </label>

            <label>
              Share title
              <input
                type="text"
                value={form.share_title || ""}
                onChange={(e) => updateForm("share_title", e.target.value)}
              />
            </label>

            <label>
              Share description
              <textarea
                rows={2}
                value={form.share_description || ""}
                onChange={(e) => updateForm("share_description", e.target.value)}
              />
            </label>

            <label>
              Share image URL
              <input
                type="text"
                value={form.share_image_url || ""}
                onChange={(e) => updateForm("share_image_url", e.target.value)}
              />
            </label>
          </div>
        </div>


      {saveStatus && <div className="panel-status success">{saveStatus}</div>}
      {saveError && <div className="panel-status error">{saveError}</div>}
      </div>

      {ctaPickerOpen && (
        <div className="cta-picker-modal" role="dialog" aria-modal="true">
          <div className="cta-picker-backdrop" onClick={() => setCtaPickerOpen(false)} />
          <div className="cta-picker-panel">
            <div className="cta-picker-header">
              <div className="cta-picker-title">Pick CTAs for This Instance</div>
              <div className="cta-picker-actions">
                <button
                  type="button"
                  className="cta-picker-save"
                  onClick={handleSave}
                  disabled={(!form.playlist_instance_id && !form.playlist_id) || missingArticleIds.length > 0}
                >
                  Save CTAs
                </button>
                <button type="button" className="cta-picker-close" onClick={() => setCtaPickerOpen(false)}>
                  Close
                </button>
              </div>
            </div>
            <div className="cta-dual-list">
              <div className="cta-dual-column">
                <div className="cta-dual-title">All CTAs</div>
                <div className="cta-dual-listbox">
                  {availableCtas.length === 0 && (
                    <div className="cta-empty">No more CTAs to add.</div>
                  )}
                  {availableCtas.map((cta) => (
                    <button
                      key={cta.cta_id}
                      type="button"
                      className="cta-dual-row"
                      onDoubleClick={() => addCtaToInstance(cta.cta_id)}
                    >
                      <div className="row-title">{cta.label}</div>
                      <div className="row-meta">{cta.type_label}</div>
                    </button>
                  ))}
                </div>
                <div className="cta-dual-hint">Double click to add</div>
              </div>
              <div className="cta-dual-column">
                <div className="cta-dual-title">Selected for This Instance</div>
                <div className="cta-dual-listbox">
                  {selectedCtas.length === 0 && (
                    <div className="cta-empty">No CTAs selected.</div>
                  )}
                  {selectedCtas.map((cta) => (
                    <button
                      key={cta.cta_id}
                      type="button"
                      className="cta-dual-row"
                      onDoubleClick={() => removeCtaFromInstance(cta.cta_id)}
                      draggable
                      onDragStart={() => setDragCtaId(String(cta.cta_id))}
                      onDragOver={(e) => e.preventDefault()}
                      onDrop={() => {
                        if (!dragCtaId) return;
                        reorderSelectedCtas(dragCtaId, String(cta.cta_id));
                        setDragCtaId(null);
                      }}
                    >
                      <div className="row-title">{cta.label}</div>
                      <div className="row-meta">{cta.type_label}</div>
                    </button>
                  ))}
                </div>
                <div className="cta-dual-hint">Double click to remove</div>
                {selectedCtas.map((cta) => {
                  const isArticle = cta.type_action_key === "article_link";
                  if (!isArticle) return null;
                  const overrides = (form.cta_overrides || {})[cta.cta_id] || {};
                  let baseParams = {};
                  if (cta?.params) {
                    try {
                      baseParams = typeof cta.params === "string" ? JSON.parse(cta.params) : cta.params;
                    } catch {
                      baseParams = {};
                    }
                  }
                  const displayArticleId = overrides.article_id || baseParams.article_id || "";
                  const displayTitle = overrides.title || baseParams.title || "";
                  const displayDek = overrides.dek || baseParams.dek || "";
                  return (
                    <div key={`ov-${cta.cta_id}`} className="cta-override-card">
                      <div className="cta-override-title">Article Link Overrides</div>
                      <label>
                        Article ID
                        <input
                          type="number"
                          value={displayArticleId}
                          onChange={(e) => updateCtaOverride(cta.cta_id, "article_id", e.target.value)}
                          required
                        />
                      </label>
                      <label>
                        Title (non-button text)
                        <input
                          type="text"
                          value={displayTitle}
                          onChange={(e) => updateCtaOverride(cta.cta_id, "title", e.target.value)}
                        />
                      </label>
                      <label>
                        Subtitle (non-button text)
                        <input
                          type="text"
                          value={displayDek}
                          onChange={(e) => updateCtaOverride(cta.cta_id, "dek", e.target.value)}
                        />
                      </label>
                    </div>
                  );
                })}
                {selectedCtas.map((cta) => {
                  if (cta.type_action_key !== "watch_next") return null;
                  const overrides = (form.cta_overrides || {})[cta.cta_id] || {};
                  let baseParams = {};
                  if (cta?.params) {
                    try {
                      baseParams = typeof cta.params === "string" ? JSON.parse(cta.params) : cta.params;
                    } catch {
                      baseParams = {};
                    }
                  }
                  const displaySetId =
                    overrides.playlist_instance_set_id ||
                    overrides.set_id ||
                    baseParams.playlist_instance_set_id ||
                    baseParams.set_id ||
                    "";
                  const displaySubtitle = overrides.subtitle || baseParams.subtitle || baseParams.dek || "";
                  return (
                    <div key={`ov-watch-${cta.cta_id}`} className="cta-override-card">
                      <div className="cta-override-title">Watch Next Overrides</div>
                      <label>
                        Playlist Set ID (optional)
                        <input
                          type="number"
                          value={displaySetId}
                          onChange={(e) => updateCtaOverride(cta.cta_id, "playlist_instance_set_id", e.target.value)}
                        />
                      </label>
                      <label>
                        Subtitle (optional)
                        <input
                          type="text"
                          value={displaySubtitle}
                          onChange={(e) => updateCtaOverride(cta.cta_id, "subtitle", e.target.value)}
                        />
                      </label>
                    </div>
                  );
                })}
              </div>
            </div>
          </div>
        </div>
      )}
      <EmailShareModal
        open={emailModal.open}
        title="Send Playlist Link"
        templates={emailTemplates}
        templateKey={emailModal.templateKey}
        onTemplateChange={handleTemplateChange}
        toEmail={emailModal.toEmail}
        onToEmailChange={(value) =>
          setEmailModal((prev) => ({ ...prev, toEmail: value, status: { ...prev.status, error: "", success: "" } }))
        }
        subject={emailModal.subject}
        onSubjectChange={(value) =>
          setEmailModal((prev) => ({ ...prev, subject: value, status: { ...prev.status, error: "", success: "" } }))
        }
        htmlBody={emailModal.htmlBody}
        onHtmlBodyChange={(value) =>
          setEmailModal((prev) => ({ ...prev, htmlBody: value, status: { ...prev.status, error: "", success: "" } }))
        }
        message={emailModal.message}
        onMessageChange={(value) =>
          setEmailModal((prev) => ({ ...prev, message: value, status: { ...prev.status, error: "", success: "" } }))
        }
        sendFormat={sendFormat}
        onSendFormatChange={setSendFormat}
        shareLink={buildShareUrl(activeId)}
        status={emailModal.status}
        onSend={handleSendEmail}
        onClose={closeEmailModal}
      />
    </div>
  );
}

import { useEffect, useMemo, useRef, useState } from "react";
import { API_FOLDER } from "@helpers/config";
import "./admin-email-templates.css";

const EMAIL_TEMPLATES_URL = `${API_FOLDER}/v2/admin/email-templates.php`;

const EMPTY_TEMPLATE = {
  key: "",
  label: "",
  description: "",
  subject: "",
  message: "",
  html: "",
  is_active: true,
};

const TEMPLATE_FIELDS = [
  { token: "{client_name}", label: "Client Name", description: "Client first name fallback used in greetings." },
  { token: "{client_first_name}", label: "Client First Name", description: "Same first-name value as client_name." },
  { token: "{site_url}", label: "Site URL", description: "Your ColorFix site URL." },
];

function slugify(value) {
  return String(value || "")
    .toLowerCase()
    .trim()
    .replace(/[^a-z0-9]+/g, "-")
    .replace(/^-+|-+$/g, "");
}

function normalizeTemplate(template) {
  return {
    key: String(template?.key || ""),
    label: String(template?.label || ""),
    description: String(template?.description || ""),
    subject: String(template?.subject || ""),
    message: String(template?.message || ""),
    html: String(template?.html || ""),
    is_active: template?.is_active !== false && template?.is_active !== 0,
  };
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

export default function AdminEmailTemplatesPage() {
  const [items, setItems] = useState([]);
  const [selectedKey, setSelectedKey] = useState("");
  const [form, setForm] = useState(EMPTY_TEMPLATE);
  const [insertToken, setInsertToken] = useState(TEMPLATE_FIELDS[0]?.token || "");
  const [insertTarget, setInsertTarget] = useState("message");
  const [htmlManuallyEdited, setHtmlManuallyEdited] = useState(false);
  const [query, setQuery] = useState("");
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState("");
  const [notice, setNotice] = useState("");
  const subjectInputRef = useRef(null);
  const messageTextareaRef = useRef(null);
  const htmlTextareaRef = useRef(null);
  const selectionRef = useRef({
    subject: { start: 0, end: 0 },
    message: { start: 0, end: 0 },
    html: { start: 0, end: 0 },
  });

  function getFieldRef(field) {
    if (field === "subject") return subjectInputRef;
    if (field === "html") return htmlTextareaRef;
    return messageTextareaRef;
  }

  function syncSelection(field) {
    const element = getFieldRef(field).current;
    if (!element) return;
    selectionRef.current[field] = {
      start: element.selectionStart ?? 0,
      end: element.selectionEnd ?? element.selectionStart ?? 0,
    };
    setInsertTarget(field);
  }

  async function loadTemplates(preferredKey = "") {
    setLoading(true);
    setError("");
    try {
      const res = await fetch(`${EMAIL_TEMPLATES_URL}?_=${Date.now()}`, { credentials: "include" });
      const data = await res.json();
      if (!res.ok || !data?.ok) throw new Error(data?.error || "Failed to load templates");
      const nextItems = (Array.isArray(data.templates) ? data.templates : []).map(normalizeTemplate);
      setItems(nextItems);

      const targetKey = preferredKey || selectedKey;
      const target = nextItems.find((item) => item.key === targetKey);
      if (target) {
        selectTemplate(target);
      } else if (!targetKey && nextItems[0]) {
        selectTemplate(nextItems[0]);
      } else if (!target) {
        setSelectedKey("");
        setForm(EMPTY_TEMPLATE);
      }
    } catch (err) {
      setError(err?.message || "Failed to load templates");
    } finally {
      setLoading(false);
    }
  }

  useEffect(() => {
    let active = true;
    async function fetchTemplates() {
      setLoading(true);
      setError("");
      try {
        const res = await fetch(`${EMAIL_TEMPLATES_URL}?_=${Date.now()}`, { credentials: "include" });
        const data = await res.json();
        if (!res.ok || !data?.ok) throw new Error(data?.error || "Failed to load templates");
        if (!active) return;
        const nextItems = (Array.isArray(data.templates) ? data.templates : []).map(normalizeTemplate);
        setItems(nextItems);
        if (nextItems[0]) {
          selectTemplate(nextItems[0]);
        }
      } catch (err) {
        if (!active) return;
        setError(err?.message || "Failed to load templates");
      } finally {
        if (active) setLoading(false);
      }
    }

    void fetchTemplates();
    return () => {
      active = false;
    };
  }, []);

  function selectTemplate(template) {
    const next = normalizeTemplate(template);
    setSelectedKey(next.key);
    setForm(next);
    setHtmlManuallyEdited(false);
    setError("");
    setNotice("");
  }

  function startNew() {
    setSelectedKey("");
    setForm(EMPTY_TEMPLATE);
    setHtmlManuallyEdited(false);
    setError("");
    setNotice("");
  }

  function handleInsertField() {
    if (!insertToken || !insertTarget) return;
    const targetField = insertTarget;
    const targetRef = getFieldRef(targetField);
    const { start, end } = selectionRef.current[targetField] || { start: 0, end: 0 };

    setForm((prev) => ({
      ...prev,
      [targetField]: (() => {
        const currentValue = String(prev[targetField] || "");
        const safeStart = Math.max(0, Math.min(start, currentValue.length));
        const safeEnd = Math.max(safeStart, Math.min(end, currentValue.length));
        return `${currentValue.slice(0, safeStart)}${insertToken}${currentValue.slice(safeEnd)}`;
      })(),
      ...(targetField === "message" && !htmlManuallyEdited
        ? {
          html: plainTextToHtml((() => {
            const currentValue = String(prev.message || "");
            const safeStart = Math.max(0, Math.min(start, currentValue.length));
            const safeEnd = Math.max(safeStart, Math.min(end, currentValue.length));
            return `${currentValue.slice(0, safeStart)}${insertToken}${currentValue.slice(safeEnd)}`;
          })()),
        }
        : {}),
    }));

    if (targetField === "html") {
      setHtmlManuallyEdited(true);
    }

    const nextCaret = start + insertToken.length;
    selectionRef.current[targetField] = { start: nextCaret, end: nextCaret };
    window.requestAnimationFrame(() => {
      const element = targetRef.current;
      if (!element) return;
      element.focus();
      element.setSelectionRange(nextCaret, nextCaret);
    });
  }

  function handleGenerateHtmlFromMessage() {
    setForm((prev) => ({
      ...prev,
      html: plainTextToHtml(prev.message),
    }));
    setHtmlManuallyEdited(false);
  }

  async function handleSave(event) {
    event.preventDefault();
    setSaving(true);
    setError("");
    setNotice("");
    try {
      const key = slugify(form.key || form.label);
      if (!key) throw new Error("Template key or label required");
      if (!form.label.trim()) throw new Error("Template label required");
      const htmlToSave = htmlManuallyEdited ? form.html : plainTextToHtml(form.message);

      const res = await fetch(EMAIL_TEMPLATES_URL, {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          key,
          label: form.label,
          description: form.description,
          subject: form.subject,
          message: form.message,
          html: htmlToSave,
          is_active: form.is_active,
        }),
      });
      const data = await res.json();
      if (!res.ok || !data?.ok) throw new Error(data?.error || "Failed to save template");
      setNotice(selectedKey ? "Template updated." : "Template created.");
      await loadTemplates(key);
    } catch (err) {
      setError(err?.message || "Failed to save template");
    } finally {
      setSaving(false);
    }
  }

  const filteredItems = useMemo(() => {
    const needle = query.trim().toLowerCase();
    if (!needle) return items;
    return items.filter((item) =>
      item.label.toLowerCase().includes(needle)
      || item.key.toLowerCase().includes(needle)
      || item.description.toLowerCase().includes(needle)
    );
  }, [items, query]);

  return (
    <div className="admin-email-templates">
      <aside className="admin-email-templates__sidebar">
        <div className="admin-email-templates__sidebar-header">
          <div>
            <h1>Email Templates</h1>
            <p>Manage reusable starting drafts for the Outreach email tab.</p>
          </div>
          <button type="button" className="admin-email-templates__primary" onClick={startNew}>
            New Template
          </button>
        </div>

        <input
          className="admin-email-templates__search"
          type="search"
          placeholder="Search templates"
          value={query}
          onChange={(e) => setQuery(e.target.value)}
        />

        <div className="admin-email-templates__list">
          {loading ? <div className="admin-email-templates__empty">Loading templates…</div> : null}
          {!loading && filteredItems.length === 0 ? <div className="admin-email-templates__empty">No templates found.</div> : null}
          {!loading && filteredItems.length > 0 ? (
            <ul className="admin-email-templates__list-items" aria-label="Email templates">
              {filteredItems.map((item) => {
                const optionId = `admin-email-template-${item.key}`;
                const isActive = selectedKey === item.key;

                return (
                  <li key={item.key} className={`admin-email-templates__item ${isActive ? "is-active" : ""}`}>
                    <label className="admin-email-templates__item-option" htmlFor={optionId}>
                      <input
                        id={optionId}
                        className="admin-email-templates__item-input"
                        type="radio"
                        name="admin-email-template"
                        checked={isActive}
                        onChange={() => selectTemplate(item)}
                      />
                      <span className="admin-email-templates__item-body">
                        <span className="admin-email-templates__item-title">{item.label}</span>
                        <span className="admin-email-templates__item-meta">{item.key}</span>
                        {item.description ? <span className="admin-email-templates__item-desc">{item.description}</span> : null}
                      </span>
                    </label>
                  </li>
                );
              })}
            </ul>
          ) : null}
        </div>
      </aside>

      <main className="admin-email-templates__main">
        <section className="admin-email-templates__panel">
          <div className="admin-email-templates__panel-header">
            <div>
              <h2>{selectedKey ? form.label || form.key : "New Template"}</h2>
              <div className="admin-email-templates__panel-sub">
                Templates are starting points only. Activity stores the final sent message instead.
              </div>
            </div>
          </div>

          {error ? <div className="admin-email-templates__message admin-email-templates__message--error">{error}</div> : null}
          {notice ? <div className="admin-email-templates__message admin-email-templates__message--ok">{notice}</div> : null}

          <form className="admin-email-templates__form" onSubmit={handleSave}>
            <label>
              Label
              <input
                type="text"
                value={form.label}
                onChange={(e) => setForm((prev) => ({ ...prev, label: e.target.value }))}
              />
            </label>
            <label>
              Key
              <input
                type="text"
                value={form.key}
                onChange={(e) => setForm((prev) => ({ ...prev, key: e.target.value }))}
                placeholder="auto-generated from label if left blank"
              />
            </label>
            <label>
              Description
              <input
                type="text"
                value={form.description}
                onChange={(e) => setForm((prev) => ({ ...prev, description: e.target.value }))}
              />
            </label>
            <label className="admin-email-templates__checkbox">
              <input
                type="checkbox"
                checked={form.is_active}
                onChange={(e) => setForm((prev) => ({ ...prev, is_active: e.target.checked }))}
              />
              Active in compose
            </label>
            <div className="admin-email-templates__insert-helper">
              <div className="admin-email-templates__insert-helper-head">
                <strong>Insertion Fields</strong>
                <span>Use these placeholders in subject, plain text, or HTML.</span>
              </div>
              <div className="admin-email-templates__insert-controls">
                <label>
                  Field
                  <select
                    value={insertToken}
                    onChange={(e) => setInsertToken(e.target.value)}
                  >
                    {TEMPLATE_FIELDS.map((field) => (
                      <option key={field.token} value={field.token}>
                        {field.label} · {field.token}
                      </option>
                    ))}
                  </select>
                </label>
                <label>
                  Insert into
                  <select
                    value={insertTarget}
                    onChange={(e) => setInsertTarget(e.target.value)}
                  >
                    <option value="subject">Subject</option>
                    <option value="message">Plain Text</option>
                    <option value="html">HTML</option>
                  </select>
                </label>
                <button
                  type="button"
                  className="admin-email-templates__secondary"
                  onClick={handleInsertField}
                >
                  Insert Field
                </button>
              </div>
              <div className="admin-email-templates__insert-note">
                {TEMPLATE_FIELDS.find((field) => field.token === insertToken)?.description || ""}
              </div>
            </div>
            <label>
              Default subject
              <input
                ref={subjectInputRef}
                type="text"
                value={form.subject}
                onChange={(e) => setForm((prev) => ({ ...prev, subject: e.target.value }))}
                onFocus={() => syncSelection("subject")}
                onClick={() => syncSelection("subject")}
                onKeyUp={() => syncSelection("subject")}
                onSelect={() => syncSelection("subject")}
              />
            </label>
            <label>
              Default plain-text body
              <textarea
                ref={messageTextareaRef}
                rows={10}
                value={form.message}
                onChange={(e) => setForm((prev) => ({
                  ...prev,
                  message: e.target.value,
                  html: htmlManuallyEdited ? prev.html : plainTextToHtml(e.target.value),
                }))}
                onFocus={() => syncSelection("message")}
                onClick={() => syncSelection("message")}
                onKeyUp={() => syncSelection("message")}
                onSelect={() => syncSelection("message")}
              />
            </label>
            <div className="admin-email-templates__stack">
              <div className="admin-email-templates__field-head">
                <label htmlFor="admin-email-template-html">Default HTML body</label>
                <button
                  type="button"
                  className="admin-email-templates__secondary"
                  onClick={handleGenerateHtmlFromMessage}
                >
                  Generate HTML From Plain Text
                </button>
              </div>
              <textarea
                ref={htmlTextareaRef}
                id="admin-email-template-html"
                rows={12}
                value={form.html}
                onChange={(e) => {
                  const nextHtml = e.target.value;
                  setHtmlManuallyEdited(true);
                  setForm((prev) => ({
                    ...prev,
                    html: nextHtml,
                    message: htmlToPlainText(nextHtml),
                  }));
                }}
                onFocus={() => syncSelection("html")}
                onClick={() => syncSelection("html")}
                onKeyUp={() => syncSelection("html")}
                onSelect={() => syncSelection("html")}
              />
            </div>
            <div className="admin-email-templates__actions">
              <button type="submit" className="admin-email-templates__primary" disabled={saving}>
                {saving ? "Saving…" : selectedKey ? "Save Template" : "Create Template"}
              </button>
            </div>
          </form>
        </section>
      </main>
    </div>
  );
}

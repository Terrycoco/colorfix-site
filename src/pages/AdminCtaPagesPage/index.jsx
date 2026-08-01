import { useCallback, useEffect, useMemo, useState } from "react";
import { Link } from "react-router-dom";
import { API_FOLDER } from "@helpers/config";
import "./admin-cta-pages.css";

const CTA_PAGES_LIST_URL = `${API_FOLDER}/v2/admin/cta-groups/list.php`;
const CTA_PAGES_SAVE_URL = `${API_FOLDER}/v2/admin/cta-groups/save.php`;
const CTA_PAGE_ITEMS_LIST_URL = `${API_FOLDER}/v2/admin/cta-group-items/list.php`;
const CTA_PAGE_ITEMS_SAVE_URL = `${API_FOLDER}/v2/admin/cta-group-items/save.php`;
const CTAS_LIST_URL = `${API_FOLDER}/v2/admin/ctas/list.php`;

const emptyPage = {
  id: null,
  key: "",
  label: "",
  description: "",
};

function normalizeCtas(items) {
  const raw = Array.isArray(items) ? items : Object.values(items || {});
  return raw
    .map((item) => ({
      ...item,
      cta_id: Number(item.cta_id) || 0,
      is_active: Number(item.is_active) === 1 || item.is_active === true,
    }))
    .filter((item) => item.cta_id > 0);
}

function normalizePage(row) {
  return {
    id: row?.id ? Number(row.id) : null,
    key: row?.key || "",
    label: row?.label || "",
    description: row?.description || "",
    created_at: row?.created_at || "",
  };
}

function slugify(value) {
  return String(value || "")
    .trim()
    .toLowerCase()
    .replace(/&/g, " and ")
    .replace(/[^a-z0-9]+/g, "-")
    .replace(/^-+|-+$/g, "");
}

function ctaMeta(cta) {
  const parts = [];
  if (cta.type_label) parts.push(cta.type_label);
  if (cta.type_action_key) parts.push(cta.type_action_key);
  if (cta.onclick) parts.push(`event: ${cta.onclick}`);
  return parts.join(" - ");
}

export default function AdminCtaPagesPage() {
  const [pages, setPages] = useState([]);
  const [ctas, setCtas] = useState([]);
  const [form, setForm] = useState(emptyPage);
  const [items, setItems] = useState([]);
  const [selectedAvailableId, setSelectedAvailableId] = useState("");
  const [selectedPageItemId, setSelectedPageItemId] = useState("");
  const [query, setQuery] = useState("");
  const [status, setStatus] = useState("");
  const [error, setError] = useState("");
  const [loadingItems, setLoadingItems] = useState(false);

  const fetchPages = useCallback(async () => {
    try {
      const res = await fetch(`${CTA_PAGES_LIST_URL}?_=${Date.now()}`, {
        credentials: "include",
      });
      const data = await res.json();
      if (!res.ok || !data?.ok) throw new Error(data?.error || "Failed to load CTA pages");
      const nextPages = (Array.isArray(data.items) ? data.items : []).map(normalizePage);
      setPages(nextPages);
    } catch (err) {
      setError(err?.message || "Failed to load CTA pages");
    }
  }, []);

  const fetchCtas = useCallback(async () => {
    try {
      const res = await fetch(`${CTAS_LIST_URL}?_=${Date.now()}`, {
        credentials: "include",
      });
      const data = await res.json();
      if (!res.ok || !data?.ok) throw new Error(data?.error || "Failed to load CTAs");
      setCtas(normalizeCtas(data.items).sort((a, b) => String(a.label || "").localeCompare(String(b.label || ""))));
    } catch (err) {
      setError(err?.message || "Failed to load CTAs");
    }
  }, []);

  useEffect(() => {
    fetchPages();
    fetchCtas();
  }, [fetchCtas, fetchPages]);

  async function fetchPageItems(pageId) {
    if (!pageId) {
      setItems([]);
      return;
    }
    setLoadingItems(true);
    try {
      const res = await fetch(`${CTA_PAGE_ITEMS_LIST_URL}?group_id=${encodeURIComponent(pageId)}&_=${Date.now()}`, {
        credentials: "include",
      });
      const data = await res.json();
      if (!res.ok || !data?.ok) throw new Error(data?.error || "Failed to load page CTAs");
      setItems(normalizeCtas(data.items));
      setSelectedPageItemId("");
    } catch (err) {
      setError(err?.message || "Failed to load page CTAs");
    } finally {
      setLoadingItems(false);
    }
  }

  function selectPage(page) {
    const normalized = normalizePage(page);
    setForm(normalized);
    setStatus("");
    setError("");
    setSelectedAvailableId("");
    fetchPageItems(normalized.id);
  }

  function updateForm(field, value) {
    setForm((prev) => {
      const next = { ...prev, [field]: value };
      if (field === "label" && !prev.id && (!prev.key || prev.key === slugify(prev.label))) {
        next.key = slugify(value);
      }
      return next;
    });
    setStatus("");
    setError("");
  }

  async function persistPageItems(pageId, nextItems = items) {
    const payload = {
      group_id: Number(pageId),
      items: nextItems.map((item, index) => ({
        cta_id: Number(item.cta_id),
        order_index: index + 1,
      })),
    };
    const res = await fetch(CTA_PAGE_ITEMS_SAVE_URL, {
      method: "POST",
      credentials: "include",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(payload),
    });
    const data = await res.json();
    if (!res.ok || !data?.ok) throw new Error(data?.error || "Failed to save page CTAs");
  }

  async function savePage() {
    setStatus("");
    setError("");
    try {
      const payload = {
        ...form,
        id: form.id ? Number(form.id) : null,
        key: form.key.trim(),
        label: form.label.trim(),
      };
      if (!payload.key) throw new Error("Page key is required.");
      if (!payload.label) throw new Error("Page name is required.");
      const res = await fetch(CTA_PAGES_SAVE_URL, {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(payload),
      });
      const data = await res.json();
      if (!res.ok || !data?.ok) throw new Error(data?.error || "Failed to save CTA page");
      const id = Number(data.id) || form.id;
      await persistPageItems(id);
      setForm((prev) => ({ ...prev, id }));
      setStatus(`CTA page and buttons saved (#${id})`);
      await fetchPages();
      await fetchPageItems(id);
    } catch (err) {
      setError(err?.message || "Failed to save CTA page");
    }
  }

  async function savePageItems() {
    setStatus("");
    setError("");
    if (!form.id) {
      setError("Use Save Page + Buttons to create the page and save its CTAs.");
      return;
    }
    try {
      await persistPageItems(form.id);
      setStatus("CTA page order saved");
      await fetchPageItems(form.id);
    } catch (err) {
      setError(err?.message || "Failed to save page CTAs");
    }
  }

  function addSelectedCta() {
    const ctaId = Number(selectedAvailableId) || 0;
    if (!ctaId) return;
    const cta = ctas.find((item) => Number(item.cta_id) === ctaId);
    if (!cta || items.some((item) => Number(item.cta_id) === ctaId)) return;
    setItems((prev) => [...prev, { ...cta, order_index: prev.length + 1 }]);
    setSelectedAvailableId("");
  }

  function removeSelectedCta() {
    const ctaId = Number(selectedPageItemId) || 0;
    if (!ctaId) return;
    setItems((prev) => prev.filter((item) => Number(item.cta_id) !== ctaId));
    setSelectedPageItemId("");
  }

  function moveSelected(delta) {
    const ctaId = Number(selectedPageItemId) || 0;
    if (!ctaId) return;
    setItems((prev) => {
      const index = prev.findIndex((item) => Number(item.cta_id) === ctaId);
      const nextIndex = index + delta;
      if (index < 0 || nextIndex < 0 || nextIndex >= prev.length) return prev;
      const next = [...prev];
      const [row] = next.splice(index, 1);
      next.splice(nextIndex, 0, row);
      return next;
    });
  }

  const selectedCtaIds = useMemo(() => new Set(items.map((item) => Number(item.cta_id))), [items]);

  const availableCtas = useMemo(() => {
    const term = query.trim().toLowerCase();
    return ctas.filter((cta) => {
      if (selectedCtaIds.has(Number(cta.cta_id))) return false;
      if (!term) return true;
      const haystack = `${cta.label || ""} ${cta.type_label || ""} ${cta.type_action_key || ""} ${cta.onclick || ""}`.toLowerCase();
      return haystack.includes(term);
    });
  }, [ctas, query, selectedCtaIds]);

  const selectedPageIndex = items.findIndex((item) => Number(item.cta_id) === Number(selectedPageItemId));

  return (
    <main className="admin-cta-pages">
      <header className="cta-pages-header">
        <div>
          <h1>CTA Pages</h1>
          <p>Reusable CTA groups for publisher instances and player experiences.</p>
        </div>
        <div className="cta-pages-header-actions">
          <Link className="cta-page-btn" to="/admin/ctas">
            CTAs
          </Link>
          <button
            type="button"
            className="cta-page-btn cta-page-btn--primary"
            onClick={() => {
              setForm(emptyPage);
              setItems([]);
              setSelectedAvailableId("");
              setSelectedPageItemId("");
              setStatus("");
              setError("");
            }}
          >
            New CTA Page
          </button>
        </div>
      </header>

      <section className="cta-pages-layout">
        <aside className="cta-pages-panel cta-pages-list-panel">
          <div className="cta-pages-panel-title">Pages</div>
          <div className="cta-pages-list">
            {pages.length === 0 && <div className="cta-pages-empty">No CTA pages yet.</div>}
            {pages.map((page) => (
              <button
                key={page.id}
                type="button"
                className={`cta-page-row ${Number(form.id) === Number(page.id) ? "is-active" : ""}`}
                onClick={() => selectPage(page)}
              >
                <span className="cta-page-row-title">{page.label}</span>
                <span className="cta-page-row-meta">#{page.id} - {page.key}</span>
              </button>
            ))}
          </div>
        </aside>

        <section className="cta-pages-panel">
          <div className="cta-pages-panel-header">
            <div className="cta-pages-panel-title">Page Setup</div>
            <div className="cta-pages-actions">
              <button type="button" className="cta-page-btn cta-page-btn--primary" onClick={savePage}>
                Save Page + Buttons
              </button>
              <button type="button" className="cta-page-btn" onClick={savePageItems} disabled={!form.id}>
                Save CTAs
              </button>
            </div>
          </div>

          <div className="cta-page-form">
            <label>
              CTA Page ID
              <input type="text" value={form.id || ""} readOnly />
            </label>
            <label>
              Page Key
              <input
                type="text"
                value={form.key}
                onChange={(event) => updateForm("key", slugify(event.target.value))}
                placeholder="pinterest"
              />
            </label>
            <label>
              Page Name / Channel Label
              <input
                type="text"
                value={form.label}
                onChange={(event) => updateForm("label", event.target.value)}
                placeholder="Pinterest"
              />
            </label>
            <label className="cta-page-form-wide">
              Description
              <textarea
                rows={3}
                value={form.description || ""}
                onChange={(event) => updateForm("description", event.target.value)}
                placeholder="Reusable Pinterest CTA page for published playlist instances."
              />
            </label>
          </div>

          <div className="cta-page-builder">
            <div className="cta-picker">
              <div className="cta-picker-header">
                <span>Available CTAs</span>
                <input
                  type="search"
                  value={query}
                  onChange={(event) => setQuery(event.target.value)}
                  placeholder="Filter CTAs"
                />
              </div>
              <div className="cta-picker-list">
                {availableCtas.length === 0 && <div className="cta-pages-empty">No matching CTAs.</div>}
                {availableCtas.map((cta) => (
                  <button
                    key={cta.cta_id}
                    type="button"
                    className={`cta-picker-row ${Number(selectedAvailableId) === Number(cta.cta_id) ? "is-active" : ""}`}
                    onClick={() => setSelectedAvailableId(String(cta.cta_id))}
                    onDoubleClick={() => {
                      setSelectedAvailableId(String(cta.cta_id));
                      setItems((prev) => [...prev, { ...cta, order_index: prev.length + 1 }]);
                    }}
                  >
                    <span className="cta-picker-title">{cta.label}</span>
                    <span className="cta-picker-meta">#{cta.cta_id} - {ctaMeta(cta)}</span>
                  </button>
                ))}
              </div>
            </div>

            <div className="cta-page-builder-actions">
              <button type="button" className="cta-page-btn cta-page-btn--primary" onClick={addSelectedCta} disabled={!selectedAvailableId}>
                Add
              </button>
              <button type="button" className="cta-page-btn" onClick={removeSelectedCta} disabled={!selectedPageItemId}>
                Remove
              </button>
              <button type="button" className="cta-page-btn" onClick={() => moveSelected(-1)} disabled={selectedPageIndex <= 0}>
                Up
              </button>
              <button type="button" className="cta-page-btn" onClick={() => moveSelected(1)} disabled={selectedPageIndex < 0 || selectedPageIndex >= items.length - 1}>
                Down
              </button>
            </div>

            <div className="cta-picker">
              <div className="cta-picker-header">
                <span>CTAs On This Page</span>
                <span>{items.length} total</span>
              </div>
              <div className="cta-picker-list">
                {loadingItems && <div className="cta-pages-empty">Loading page CTAs...</div>}
                {!loadingItems && items.length === 0 && <div className="cta-pages-empty">No CTAs assigned yet.</div>}
                {!loadingItems && items.map((cta, index) => (
                  <button
                    key={`${cta.cta_id}-${index}`}
                    type="button"
                    className={`cta-picker-row ${Number(selectedPageItemId) === Number(cta.cta_id) ? "is-active" : ""}`}
                    onClick={() => setSelectedPageItemId(String(cta.cta_id))}
                  >
                    <span className="cta-picker-title">{index + 1}. {cta.label}</span>
                    <span className="cta-picker-meta">#{cta.cta_id} - {ctaMeta(cta)}</span>
                  </button>
                ))}
              </div>
            </div>
          </div>

          <div className="cta-page-note">
            Publisher can attach this stable CTA Page ID to a playlist instance. The player resolves the current CTA list from that ID each time it serves the experience.
          </div>
          {status && <div className="cta-page-status cta-page-status--success">{status}</div>}
          {error && <div className="cta-page-status cta-page-status--error">{error}</div>}
        </section>
      </section>
    </main>
  );
}

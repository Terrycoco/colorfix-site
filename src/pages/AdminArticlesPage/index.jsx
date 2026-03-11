import { useEffect, useMemo, useState } from "react";
import { API_FOLDER } from "@helpers/config";
import "./admin-articles.css";

const LIST_URL = `${API_FOLDER}/v2/admin/articles/list.php`;
const GET_URL = `${API_FOLDER}/v2/admin/articles/get.php`;
const SAVE_URL = `${API_FOLDER}/v2/admin/articles/save.php`;
const TAGS_URL = `${API_FOLDER}/v2/admin/articles/tags.php`;
const DELETE_URL = `${API_FOLDER}/v2/admin/articles/delete.php`;
const CTAS_LIST_URL = `${API_FOLDER}/v2/admin/ctas/list.php`;

const emptyArticle = {
  id: null,
  type: "colorfix",
  status: "draft",
  title: "",
  dek: "",
  slug: "",
  meta_description: "",
  hero_asset_id: "",
  cta_overrides: "",
  featured: false,
  published_at: "",
  tags: [],
  sections: [],
};

const sectionKinds = ["text", "image", "palette_link", "cta", "embed", "list", "header"];
const articleTypes = ["colorfix", "theme", "guide"];

function slugify(value = "") {
  return (
    value
      .toLowerCase()
      .trim()
      .replace(/[^a-z0-9]+/g, "-")
      .replace(/^-+|-+$/g, "") || `article-${Date.now()}`
  );
}

function formatTimestamp(value) {
  if (!value) return "";
  const date = new Date(value.replace(" ", "T"));
  if (Number.isNaN(date.getTime())) return value;
  return date.toLocaleString();
}

export default function AdminArticlesPage() {
  const [articles, setArticles] = useState([]);
  const [tags, setTags] = useState([]);
  const [filters, setFilters] = useState({ q: "", tags: "", type: "", status: "" });
  const [selectedId, setSelectedId] = useState(null);
  const [form, setForm] = useState(emptyArticle);
  const [ctas, setCtas] = useState([]);
  const [showCtaPicker, setShowCtaPicker] = useState(false);
  const [ctaDraft, setCtaDraft] = useState({ ids: [], overrides: {} });
  const [showEditor, setShowEditor] = useState(false);
  const [showList, setShowList] = useState(true);
  const [loading, setLoading] = useState(true);
  const [status, setStatus] = useState("");
  const [error, setError] = useState("");
  const [saving, setSaving] = useState(false);
  const [deleting, setDeleting] = useState(false);

  const tagOptions = useMemo(() => {
    return tags.map((tag) => tag.slug).filter(Boolean);
  }, [tags]);

  useEffect(() => {
    loadTags();
    loadArticles();
    loadCtas();
  }, []);

  async function loadTags() {
    try {
      const res = await fetch(`${TAGS_URL}?_=${Date.now()}`, { credentials: "include" });
      const text = await res.text();
      if (!res.ok) throw new Error(`HTTP ${res.status}: ${text.slice(0, 200)}`);
      const data = JSON.parse(text);
      if (!data?.ok) throw new Error(data?.error || "Failed to load tags");
      setTags(Array.isArray(data.items) ? data.items : []);
    } catch (err) {
      setError(err?.message || "Failed to load tags");
    }
  }

  async function loadArticles() {
    setLoading(true);
    setError("");
    try {
      const params = new URLSearchParams();
      if (filters.q) params.set("q", filters.q);
      if (filters.tags) params.set("tags", filters.tags);
      if (filters.type) params.set("type", filters.type);
      if (filters.status) params.set("status", filters.status);
      params.set("_", Date.now().toString());

      const res = await fetch(`${LIST_URL}?${params.toString()}`, { credentials: "include" });
      const text = await res.text();
      if (!res.ok) throw new Error(`HTTP ${res.status}: ${text.slice(0, 200)}`);
      const data = JSON.parse(text);
      if (!data?.ok) throw new Error(data?.error || "Failed to load articles");
      setArticles(Array.isArray(data.items) ? data.items : []);
    } catch (err) {
      setError(err?.message || "Failed to load articles");
    } finally {
      setLoading(false);
    }
  }

  async function loadCtas() {
    try {
      const res = await fetch(`${CTAS_LIST_URL}?_=${Date.now()}`, { credentials: "include" });
      const data = await res.json();
      if (!res.ok || !data?.ok) throw new Error(data?.error || "Failed to load CTAs");
      const items = Array.isArray(data.items) ? data.items : Object.values(data.items || {});
      items.sort((a, b) => (Number(b.cta_id) || 0) - (Number(a.cta_id) || 0));
      setCtas(items);
    } catch {
      setCtas([]);
    }
  }

  async function loadArticle(id) {
    setError("");
    try {
      const res = await fetch(`${GET_URL}?id=${id}&_=${Date.now()}`, { credentials: "include" });
      const text = await res.text();
      if (!res.ok) throw new Error(`HTTP ${res.status}: ${text.slice(0, 200)}`);
      const data = JSON.parse(text);
      if (!data?.ok) throw new Error(data?.error || "Failed to load article");
      const item = data.item || {};
      setSelectedId(item.id || null);
      setShowEditor(true);
      setForm({
        id: item.id || null,
        type: item.type || "colorfix",
        status: item.status || "draft",
        title: item.title || "",
        dek: item.dek || "",
        slug: item.slug || "",
        meta_description: item.meta_description || "",
        hero_asset_id: item.hero_asset_id || "",
        cta_overrides: item.cta_overrides || "",
        featured: !!item.featured,
        published_at: item.published_at || "",
        tags: Array.isArray(item.tags) ? item.tags : [],
        tagsText: Array.isArray(item.tags)
          ? item.tags.map((tag) => tag.slug || tag.name).filter(Boolean).join(", ")
          : "",
        sections: Array.isArray(item.sections) ? item.sections : [],
      });
    } catch (err) {
      setError(err?.message || "Failed to load article");
    }
  }

  function handleNewArticle() {
    setSelectedId(null);
    setForm({ ...emptyArticle, tagsText: "" });
    setShowEditor(true);
  }

  function parseCtaOverrides(raw) {
    if (!raw) return { ids: [], overrides: {} };
    let obj = raw;
    if (typeof raw === "string") {
      try {
        obj = JSON.parse(raw);
      } catch {
        obj = {};
      }
    }
    if (!obj || typeof obj !== "object") return { ids: [], overrides: {} };
    const ids = Array.isArray(obj._cta_ids)
      ? obj._cta_ids.map((id) => Number(id)).filter((id) => Number.isFinite(id) && id > 0)
      : [];
    const overrides = { ...obj };
    delete overrides._cta_ids;
    return { ids, overrides };
  }

  function openCtaPicker() {
    const parsed = parseCtaOverrides(form.cta_overrides);
    setCtaDraft(parsed);
    setShowCtaPicker(true);
  }

  function saveCtaPicker() {
    const payload = { ...ctaDraft.overrides, _cta_ids: ctaDraft.ids };
    setForm((prev) => ({ ...prev, cta_overrides: JSON.stringify(payload) }));
    setShowCtaPicker(false);
  }

  function addCtaToArticle(ctaId) {
    setCtaDraft((prev) => {
      if (prev.ids.includes(ctaId)) return prev;
      return { ...prev, ids: [...prev.ids, ctaId] };
    });
  }

  function removeCtaFromArticle(ctaId) {
    setCtaDraft((prev) => ({
      ...prev,
      ids: prev.ids.filter((id) => id !== ctaId),
      overrides: Object.fromEntries(
        Object.entries(prev.overrides).filter(([key]) => String(key) !== String(ctaId))
      ),
    }));
  }

  function updateCtaOverride(ctaId, key, value) {
    setCtaDraft((prev) => {
      const next = { ...prev.overrides };
      const current = { ...(next[ctaId] || {}) };
      if (value === "" || value === null || value === undefined) {
        delete current[key];
      } else {
        current[key] = value;
      }
      if (Object.keys(current).length === 0) {
        delete next[ctaId];
      } else {
        next[ctaId] = current;
      }
      return { ...prev, overrides: next };
    });
  }

  async function handleSave() {
    setStatus("");
    setError("");
    if (!form.title.trim()) {
      setError("Title is required.");
      return;
    }
    if (!form.slug.trim()) {
      setError("Slug is required.");
      return;
    }
    setSaving(true);
    try {
      const payload = buildPayload(form);
      const res = await fetch(SAVE_URL, {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(payload),
      });
      const text = await res.text();
      if (!res.ok) throw new Error(`HTTP ${res.status}: ${text.slice(0, 200)}`);
      const data = JSON.parse(text);
      if (!data?.ok) throw new Error(data?.error || "Save failed");
      const newId = data.article_id || form.id;
      setStatus("Article saved.");
      if (newId) {
        await loadArticles();
        await loadArticle(newId);
      }
    } catch (err) {
      setError(err?.message || "Save failed");
    } finally {
      setSaving(false);
    }
  }

  async function handleDelete() {
    if (!form.id) return;
    if (form.status === "published") {
      setError("Unpublish before deleting.");
      return;
    }
    if (!window.confirm("Delete this article?")) return;
    setStatus("");
    setError("");
    setDeleting(true);
    try {
      const res = await fetch(DELETE_URL, {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ id: form.id }),
      });
      const text = await res.text();
      if (!res.ok) throw new Error(`HTTP ${res.status}: ${text.slice(0, 200)}`);
      const data = JSON.parse(text);
      if (!data?.ok) throw new Error(data?.error || "Delete failed");
      setStatus("Article deleted.");
      setForm({ ...emptyArticle, tagsText: "" });
      setSelectedId(null);
      setShowEditor(false);
      await loadArticles();
    } catch (err) {
      setError(err?.message || "Delete failed");
    } finally {
      setDeleting(false);
    }
  }

  async function handleTogglePublished(item, isPublished) {
    setStatus("");
    setError("");
    try {
      const payload = {
        id: item.id,
        title: item.title,
        slug: item.slug,
        type: item.type,
        status: isPublished ? "published" : "draft",
        published_at: isPublished ? new Date().toISOString().slice(0, 19).replace("T", " ") : null,
      };
      const res = await fetch(SAVE_URL, {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(payload),
      });
      const text = await res.text();
      if (!res.ok) throw new Error(`HTTP ${res.status}: ${text.slice(0, 200)}`);
      const data = JSON.parse(text);
      if (!data?.ok) throw new Error(data?.error || "Update failed");
      setStatus(isPublished ? "Published." : "Unpublished.");
      await loadArticles();
    } catch (err) {
      setError(err?.message || "Update failed");
    }
  }

  async function handleDuplicateById(articleId) {
    setStatus("");
    setError("");
    try {
      const res = await fetch(`${GET_URL}?id=${articleId}&_=${Date.now()}`, {
        credentials: "include",
      });
      const text = await res.text();
      if (!res.ok) throw new Error(`HTTP ${res.status}: ${text.slice(0, 200)}`);
      const data = JSON.parse(text);
      if (!data?.ok) throw new Error(data?.error || "Failed to load article");
      const item = data.item || {};
      const copySlug = `${item.slug || "article"}-copy-${Date.now()}`;
      const payload = buildPayload({
        ...item,
        id: null,
        status: "draft",
        slug: copySlug,
        published_at: null,
      });
      const saveRes = await fetch(SAVE_URL, {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(payload),
      });
      const saveText = await saveRes.text();
      if (!saveRes.ok) throw new Error(`HTTP ${saveRes.status}: ${saveText.slice(0, 200)}`);
      const saveData = JSON.parse(saveText);
      if (!saveData?.ok) throw new Error(saveData?.error || "Duplicate failed");
      const newId = saveData.article_id;
      setStatus("Article duplicated.");
      await loadArticles();
      if (newId) await loadArticle(newId);
    } catch (err) {
      setError(err?.message || "Duplicate failed");
    }
  }

  function handleDuplicateCurrent() {
    if (form.id) {
      handleDuplicateById(form.id);
    } else {
      const copySlug = `${form.slug || "article"}-copy-${Date.now()}`;
      setForm((prev) => ({
        ...prev,
        id: null,
        status: "draft",
        slug: copySlug,
        published_at: "",
      }));
      setShowEditor(true);
      setStatus("Duplicate ready. Review and save.");
    }
  }

  function handleAddSection() {
    const nextSort =
      form.sections.length > 0
        ? Math.max(...form.sections.map((section) => Number(section.sort_order) || 0)) + 1
        : 0;
    const newSection = {
      id: `tmp-${Date.now()}`,
      sort_order: `${nextSort}.0`,
      kind: "text",
      heading: "",
      heading_level: "h2",
      body: "",
      caption: "",
      asset_id: "",
      palette_id: "",
    };
    setForm((prev) => ({ ...prev, sections: [...prev.sections, newSection] }));
  }

  function handleRemoveSection(id) {
    setForm((prev) => ({
      ...prev,
      sections: prev.sections.filter((section) => section.id !== id),
    }));
  }

  function handleSectionChange(id, changes) {
    setForm((prev) => ({
      ...prev,
      sections: prev.sections.map((section) =>
        section.id === id ? { ...section, ...changes } : section
      ),
    }));
  }

  function buildPayload(article) {
    const tagsText = article.tagsText || "";
    const tagSlugs = tagsText
      .split(",")
      .map((tag) => tag.trim())
      .filter(Boolean);
    const tags = tagSlugs.map((slug) => ({
      slug: slugify(slug),
      name: slug
        .split(/[-\s]+/)
        .map((word) => word.charAt(0).toUpperCase() + word.slice(1))
        .join(" "),
    }));

    let publishedAt = article.published_at || null;
    if (article.status === "published" && !publishedAt) {
      publishedAt = new Date().toISOString().slice(0, 19).replace("T", " ");
    }
    if (article.status !== "published") {
      publishedAt = null;
    }

    return {
      id: article.id || null,
      type: article.type || "colorfix",
      status: article.status || "draft",
      title: article.title || "",
      dek: article.dek || "",
      slug: article.slug || "",
      meta_description: article.meta_description || "",
      hero_asset_id: article.hero_asset_id ? Number(article.hero_asset_id) : null,
      cta_overrides: article.cta_overrides || "",
      featured: !!article.featured,
      published_at: publishedAt,
      tags,
      sections: (() => {
        const rawSections = Array.isArray(article.sections) ? article.sections : [];
        const ordered = rawSections
          .map((section, idx) => ({
            idx,
            sort: Number(section.sort_order),
            section,
          }))
          .sort((a, b) => {
            if (Number.isNaN(a.sort) && Number.isNaN(b.sort)) return a.idx - b.idx;
            if (Number.isNaN(a.sort)) return 1;
            if (Number.isNaN(b.sort)) return -1;
            if (a.sort === b.sort) return a.idx - b.idx;
            return a.sort - b.sort;
          })
          .map((entry) => entry.section);
        return ordered;
      })().map((section, idx) => {
        const sectionPayload = {
          sort_order: idx,
          kind: section.kind || "text",
          heading: section.heading || null,
          heading_level: section.heading_level || null,
          body: section.body || null,
          caption: section.caption || null,
          asset_id: section.asset_id ? Number(section.asset_id) : null,
          palette_id: section.palette_id ? Number(section.palette_id) : null,
        };
        if (typeof section.id === "number") {
          sectionPayload.id = section.id;
        }
        return sectionPayload;
      }),
    };
  }

  return (
    <div className="admin-articles">
      {error && <div className="admin-articles__error">{error}</div>}
      {status && <div className="admin-articles__status">{status}</div>}

      <div className={`admin-articles__workspace ${showList ? "" : "admin-articles__workspace--collapsed"}`}>
        <aside className={`admin-articles__drawer ${showList ? "" : "admin-articles__drawer--collapsed"}`}>
          <div className="admin-articles__drawer-header">
            <h2>Articles</h2>
            <button type="button" onClick={() => setShowList((prev) => !prev)}>
              {showList ? "<" : ">"}
            </button>
          </div>
          {showList && (
            <>
              <section className="admin-articles__filters">
                <label>
                  Search
                  <input
                    type="text"
                    value={filters.q}
                    onChange={(e) => setFilters((prev) => ({ ...prev, q: e.target.value }))}
                    placeholder="title or slug"
                  />
                </label>
                <label>
                  Tags
                  <input
                    type="text"
                    list="article-tag-options"
                    value={filters.tags}
                    onChange={(e) => setFilters((prev) => ({ ...prev, tags: e.target.value }))}
                    placeholder="door, exterior"
                  />
                </label>
                <label>
                  Type
                  <input
                    type="text"
                    value={filters.type}
                    onChange={(e) => setFilters((prev) => ({ ...prev, type: e.target.value }))}
                    placeholder="colorfix"
                  />
                </label>
                <label>
                  Status
                  <select
                    value={filters.status}
                    onChange={(e) => setFilters((prev) => ({ ...prev, status: e.target.value }))}
                  >
                    <option value="">Any</option>
                    <option value="draft">Draft</option>
                    <option value="published">Published</option>
                  </select>
                </label>
                <div className="admin-articles__filter-actions">
                  <button type="button" onClick={loadArticles}>
                    Refresh
                  </button>
                </div>
                <datalist id="article-tag-options">
                  {tagOptions.map((slug) => (
                    <option key={slug} value={slug} />
                  ))}
                </datalist>
              </section>
              {loading ? (
                <div className="admin-articles__loading">Loading…</div>
              ) : (
                <div className="admin-articles__table-wrap">
                <table className="admin-articles__table">
                  <thead>
                    <tr>
                      <th>Title</th>
                      <th />
                    </tr>
                  </thead>
                  <tbody>
                    {articles.map((item) => (
                      <tr key={item.id}>
                        <td>
                          <button
                            type="button"
                            className="admin-articles__title-btn"
                            title={item.title || ""}
                            onClick={() => loadArticle(item.id)}
                          >
                            {item.title}
                          </button>
                          <div className="admin-articles__subtitle">
                            {item.type || "—"} · {item.status === "published" ? "Published" : "Draft"}
                          </div>
                        </td>
                        <td className="admin-articles__actions">
                        </td>
                      </tr>
                      ))}
                    {!articles.length && (
                      <tr>
                        <td colSpan={2} className="admin-articles__empty">
                          No articles found.
                        </td>
                      </tr>
                    )}
                    </tbody>
                  </table>
                </div>
              )}
              <div className="admin-articles__drawer-footer">
                <button type="button" onClick={handleNewArticle}>
                  New Article
                </button>
              </div>
            </>
          )}
        </aside>

        <section className={`admin-articles__editor ${showEditor ? "" : "admin-articles__editor--empty"}`}>
          {!showEditor ? (
            <div className="admin-articles__empty">Select an article to edit.</div>
          ) : (
            <>
          <div className="admin-articles__editor-header">
            <h2>{form.id ? `Edit Article #${form.id}` : "New Article"}</h2>
            <div className="admin-articles__editor-actions">
              <button type="button" onClick={handleDuplicateCurrent}>
                Duplicate
              </button>
              {form.id && (
                <a
                  className="admin-articles__preview"
                  href={`/articles/${form.id}?admin=1`}
                  target="_blank"
                  rel="noreferrer"
                >
                  Preview
                </a>
              )}
              <button type="button" onClick={handleSave} disabled={saving}>
                {saving ? "Saving…" : "Save"}
              </button>
              <button
                type="button"
                className="admin-articles__delete"
                onClick={handleDelete}
                disabled={!form.id || form.status === "published" || deleting}
              >
                {deleting ? "Deleting…" : "Delete"}
              </button>
            </div>
          </div>

          <div className="admin-articles__grid">
            <label>
              Title
            <input
              type="text"
              value={form.title}
              onChange={(e) => setForm((prev) => ({ ...prev, title: e.target.value }))}
            />
          </label>
          <label>
            Dek
            <input
              type="text"
              value={form.dek || ""}
              onChange={(e) => setForm((prev) => ({ ...prev, dek: e.target.value }))}
              placeholder="Short subtitle"
            />
          </label>
            <label>
              Slug
              <div className="admin-articles__slug">
                <input
                  type="text"
                  value={form.slug}
                  onChange={(e) => setForm((prev) => ({ ...prev, slug: e.target.value }))}
                />
                <button
                  type="button"
                  onClick={() => setForm((prev) => ({ ...prev, slug: slugify(prev.title) }))}
                >
                  Generate
                </button>
              </div>
            </label>
            <label>
              Type
              <select
                value={form.type}
                onChange={(e) => setForm((prev) => ({ ...prev, type: e.target.value }))}
              >
                {[...new Set([form.type, ...articleTypes].filter(Boolean))].map((type) => (
                  <option key={type} value={type}>
                    {type}
                  </option>
                ))}
              </select>
            </label>
            <label>
              Published
              <input
                type="checkbox"
                checked={form.status === "published"}
                onChange={(e) =>
                  setForm((prev) => ({
                    ...prev,
                    status: e.target.checked ? "published" : "draft",
                  }))
                }
              />
            </label>
            <label className="admin-articles__checkbox">
              <input
                type="checkbox"
                checked={!!form.featured}
                onChange={(e) => setForm((prev) => ({ ...prev, featured: e.target.checked }))}
              />
              Featured article
            </label>
            <label>
              Published At
              <input
                type="text"
                value={form.published_at || ""}
                onChange={(e) => setForm((prev) => ({ ...prev, published_at: e.target.value }))}
                placeholder="YYYY-MM-DD HH:MM:SS"
              />
            </label>
            <label>
              Photo Library ID
              <div className="admin-articles__asset-row">
                <input
                  type="number"
                  value={form.hero_asset_id || ""}
                  onChange={(e) =>
                    setForm((prev) => ({ ...prev, hero_asset_id: e.target.value }))
                  }
                />
                <a href="/admin/photo-library" target="_blank" rel="noreferrer">
                  Find
                </a>
              </div>
            </label>
          </div>

          <label className="admin-articles__field">
            Meta Description
            <textarea
              rows={3}
              value={form.meta_description || ""}
              onChange={(e) => setForm((prev) => ({ ...prev, meta_description: e.target.value }))}
            />
          </label>

          <div className="admin-articles__cta">
            <div className="admin-articles__cta-header">
              <h3>Article CTAs</h3>
              <button type="button" onClick={openCtaPicker}>
                Pick CTAs
              </button>
            </div>
            <div className="admin-articles__cta-list">
              {(() => {
                const parsed = parseCtaOverrides(form.cta_overrides);
                const selected = parsed.ids
                  .map((id) => ctas.find((row) => Number(row.cta_id) === Number(id)))
                  .filter(Boolean);
                if (selected.length === 0) {
                  return <div className="admin-articles__cta-empty">No CTAs selected.</div>;
                }
                return selected.map((cta) => (
                  <div key={cta.cta_id} className="admin-articles__cta-item">
                    <div className="admin-articles__cta-title">{cta.label}</div>
                    <div className="admin-articles__cta-meta">{cta.type_label}</div>
                  </div>
                ));
              })()}
            </div>
          </div>

          <label className="admin-articles__field">
            Tags (comma separated)
            <input
              type="text"
              value={form.tagsText || ""}
            onChange={(e) => setForm((prev) => ({ ...prev, tagsText: e.target.value }))}
            placeholder="door, exterior, adobe"
          />
        </label>

          <div className="admin-articles__sections">
            <div className="admin-articles__sections-header">
              <h3>Sections</h3>
              <button type="button" onClick={handleAddSection}>
                Add Section
              </button>
            </div>

            {form.sections.map((section) => (
              <div key={section.id} className="admin-articles__section-card">
                <div className="admin-articles__section-row">
                  <label>
                    Sort
                    <input
                      type="number"
                      step="0.1"
                      value={section.sort_order ?? 0}
                      onChange={(e) =>
                        handleSectionChange(section.id, { sort_order: e.target.value })
                      }
                    />
                  </label>
                  <label>
                    Kind
                    <select
                      value={section.kind || "text"}
                      onChange={(e) =>
                        handleSectionChange(section.id, { kind: e.target.value })
                      }
                    >
                      {sectionKinds.map((kind) => (
                        <option key={kind} value={kind}>
                          {kind}
                        </option>
                      ))}
                    </select>
                  </label>
                  <label>
                    Photo Library ID
                    <div className="admin-articles__asset-row">
                      <input
                        type="number"
                        value={section.asset_id || ""}
                        onChange={(e) =>
                          handleSectionChange(section.id, { asset_id: e.target.value })
                        }
                      />
                      <a href="/admin/photo-library" target="_blank" rel="noreferrer">
                        Find
                      </a>
                    </div>
                  </label>
                  <label>
                    Palette ID
                    <input
                      type="number"
                      value={section.palette_id || ""}
                      onChange={(e) =>
                        handleSectionChange(section.id, { palette_id: e.target.value })
                      }
                    />
                  </label>
                </div>
                <div className="admin-articles__heading-row">
                  <label className="admin-articles__heading-field">
                    Heading
                    <input
                      type="text"
                      value={section.heading || ""}
                      onChange={(e) =>
                        handleSectionChange(section.id, { heading: e.target.value })
                      }
                    />
                  </label>
                  <label className="admin-articles__heading-level">
                    Heading Level
                    <select
                      value={section.heading_level || "h2"}
                      onChange={(e) =>
                        handleSectionChange(section.id, { heading_level: e.target.value })
                      }
                    >
                      {["h1", "h2", "h3", "h4", "h5", "h6"].map((level) => (
                        <option key={level} value={level}>
                          {level.toUpperCase()}
                        </option>
                      ))}
                    </select>
                  </label>
                </div>
                <label>
                  Body
                  <textarea
                    rows={6}
                    value={section.body || ""}
                    onChange={(e) =>
                      handleSectionChange(section.id, { body: e.target.value })
                    }
                  />
                </label>
                {section.kind === "image" && (
                  <label>
                    Caption
                    <input
                      type="text"
                      value={section.caption || ""}
                      onChange={(e) =>
                        handleSectionChange(section.id, { caption: e.target.value })
                      }
                    />
                  </label>
                )}
                <div className="admin-articles__section-actions">
                  <button
                    type="button"
                    className="admin-articles__danger"
                    onClick={() => handleRemoveSection(section.id)}
                  >
                    Remove
                  </button>
                  <button type="button" className="admin-articles__save-inline" onClick={handleSave}>
                    Save
                  </button>
                </div>
              </div>
            ))}

            {!form.sections.length && (
              <div className="admin-articles__empty">No sections yet.</div>
            )}
            <div className="admin-articles__sections-footer">
              <button type="button" onClick={handleAddSection}>
                Add Section
              </button>
            </div>
          </div>
            </>
          )}
        </section>
      </div>

      {showCtaPicker && (
        <div className="admin-articles__cta-modal" role="dialog" aria-modal="true">
          <div className="admin-articles__cta-backdrop" onClick={() => setShowCtaPicker(false)} />
          <div className="admin-articles__cta-panel">
            <div className="admin-articles__cta-panel-header">
              <div className="admin-articles__cta-title">Pick CTAs for This Article</div>
              <div className="admin-articles__cta-actions">
                <button type="button" onClick={saveCtaPicker}>
                  Save CTAs
                </button>
                <button type="button" onClick={() => setShowCtaPicker(false)}>
                  Close
                </button>
              </div>
            </div>
            <div className="admin-articles__cta-grid">
              <div className="admin-articles__cta-col">
                <div className="admin-articles__cta-col-title">All CTAs</div>
                <div className="admin-articles__cta-listbox">
                  {ctas
                    .filter((cta) => !ctaDraft.ids.includes(Number(cta.cta_id)))
                    .map((cta) => (
                      <button
                        key={cta.cta_id}
                        type="button"
                        className="admin-articles__cta-row"
                        onDoubleClick={() => addCtaToArticle(Number(cta.cta_id))}
                      >
                        <div className="row-title">{cta.label}</div>
                        <div className="row-meta">{cta.type_label}</div>
                      </button>
                    ))}
                </div>
                <div className="admin-articles__cta-hint">Double click to add</div>
              </div>
              <div className="admin-articles__cta-col">
                <div className="admin-articles__cta-col-title">Selected</div>
                <div className="admin-articles__cta-listbox">
                  {ctaDraft.ids.length === 0 && (
                    <div className="admin-articles__cta-empty">No CTAs selected.</div>
                  )}
                  {ctaDraft.ids.map((id) => {
                    const cta = ctas.find((row) => Number(row.cta_id) === Number(id));
                    if (!cta) return null;
                    return (
                      <button
                        key={cta.cta_id}
                        type="button"
                        className="admin-articles__cta-row"
                        onDoubleClick={() => removeCtaFromArticle(Number(cta.cta_id))}
                      >
                        <div className="row-title">{cta.label}</div>
                        <div className="row-meta">{cta.type_label}</div>
                      </button>
                    );
                  })}
                </div>
                <div className="admin-articles__cta-hint">Double click to remove</div>

                {ctaDraft.ids.map((id) => {
                  const cta = ctas.find((row) => Number(row.cta_id) === Number(id));
                  if (!cta) return null;
                  const actionKey = cta.type_action_key || cta.action_key || "";
                  if (actionKey !== "playlist_link") return null;
                  const overrides = ctaDraft.overrides[id] || {};
                  const baseParams = (() => {
                    try {
                      return typeof cta.params === "string" ? JSON.parse(cta.params) : (cta.params || {});
                    } catch {
                      return {};
                    }
                  })();
                  const labelValue = overrides.label || cta.label || "";
                  const titleValue = overrides.title || baseParams.title || "";
                  const subtitleValue = overrides.subtitle || baseParams.subtitle || baseParams.dek || "";
                  const playlistValue = overrides.playlist_instance_id || baseParams.playlist_instance_id || "";
                  return (
                    <div key={`ov-${cta.cta_id}`} className="admin-articles__cta-override">
                      <div className="admin-articles__cta-override-title">Playlist Link Overrides</div>
                      <label>
                        Label (button text)
                        <input
                          type="text"
                          value={labelValue}
                          onChange={(e) => updateCtaOverride(cta.cta_id, "label", e.target.value)}
                        />
                      </label>
                      <label>
                        Title (non-button text)
                        <input
                          type="text"
                          value={titleValue}
                          onChange={(e) => updateCtaOverride(cta.cta_id, "title", e.target.value)}
                        />
                      </label>
                      <label>
                        Subtitle (non-button text)
                        <input
                          type="text"
                          value={subtitleValue}
                          onChange={(e) => updateCtaOverride(cta.cta_id, "subtitle", e.target.value)}
                        />
                      </label>
                      <label>
                        Playlist Instance ID
                        <input
                          type="number"
                          value={playlistValue}
                          onChange={(e) => updateCtaOverride(cta.cta_id, "playlist_instance_id", e.target.value)}
                          required
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
    </div>
  );
}

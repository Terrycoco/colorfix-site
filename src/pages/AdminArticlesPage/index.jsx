import { useEffect, useMemo, useState } from "react";
import { API_FOLDER } from "@helpers/config";
import "./admin-articles.css";

const LIST_URL = `${API_FOLDER}/v2/admin/articles/list.php`;
const GET_URL = `${API_FOLDER}/v2/admin/articles/get.php`;
const SAVE_URL = `${API_FOLDER}/v2/admin/articles/save.php`;
const TAGS_URL = `${API_FOLDER}/v2/admin/articles/tags.php`;
const DELETE_URL = `${API_FOLDER}/v2/admin/articles/delete.php`;

const emptyArticle = {
  id: null,
  type: "colorfix",
  status: "draft",
  title: "",
  dek: "",
  slug: "",
  meta_description: "",
  hero_asset_id: "",
  published_at: "",
  tags: [],
  sections: [],
};

const sectionKinds = ["text", "image", "palette_link", "cta", "embed", "list"];
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
    const sortValues = (form.sections || [])
      .map((section) => Number(section.sort_order || 0).toFixed(2))
      .filter(Boolean);
    const sortSet = new Set();
    const dupes = sortValues.filter((value) => {
      if (sortSet.has(value)) return true;
      sortSet.add(value);
      return false;
    });
    if (dupes.length) {
      setError("Section sort order must be unique. Adjust duplicates (use decimals like 2.1).");
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
      body: "",
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
      published_at: publishedAt,
      tags,
      sections: (article.sections || []).map((section) => {
        const sectionPayload = {
          sort_order: Number(section.sort_order) || 0,
          kind: section.kind || "text",
          heading: section.heading || null,
          body: section.body || null,
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
                <label>
                  Heading
                  <input
                    type="text"
                    value={section.heading || ""}
                    onChange={(e) =>
                      handleSectionChange(section.id, { heading: e.target.value })
                    }
                  />
                </label>
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
    </div>
  );
}

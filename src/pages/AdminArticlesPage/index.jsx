import PhotoPreviewImage from "@components/PhotoPreview/PhotoPreviewImage";
import { useEffect, useMemo, useRef, useState } from "react";
import { API_FOLDER } from "@helpers/config";
import { buildImageUrl } from "@helpers/assetImage";
import PhotoPickerModal from "@components/PhotoPickerModal";
import {
  AdminMasterDetail, AdminListPane, AdminObjectList, AdminObjectListItem,
  AdminDetailPane, AdminPanel, AdminButton, AdminNotice,
} from "@components/AdminLayout";
import "./admin-articles.css";

const LIST_URL = `${API_FOLDER}/v2/admin/articles/list.php`;
const GET_URL = `${API_FOLDER}/v2/admin/articles/get.php`;
const AI_EXPORT_URL = `${API_FOLDER}/v2/admin/articles/ai-export.php`;
const SAVE_URL = `${API_FOLDER}/v2/admin/articles/save.php`;
const DELETE_URL = `${API_FOLDER}/v2/admin/articles/delete.php`;
const CTA_PAGES_LIST_URL = `${API_FOLDER}/v2/admin/cta-groups/list.php`;

const emptyArticle = {
  id: null,
  type: "colorfix",
  status: "draft",
  title: "",
  dek: "",
  kicker: "",
  hero_inset_asset_id: "",
  hero_inset_positions: { desktop: "bottom-right", tablet: "bottom-right", phone: "bottom-right" },
  slug: "",
  meta_description: "",
  hero_asset_id: "",
  hero_mobile_asset_id: "",
  cta_overrides: "",
  featured: false,
  published_at: "",
  tags: [],
  sections: [],
};

/** AI returns the same article.json produced by Export for AI. Import prose only.
 * Never accept AI-supplied section identities, images, palettes, ordering, CTAs or status. */
function mergeAiEditorial(current, imported) {
  if (!imported || typeof imported !== "object" || Array.isArray(imported)) {
    throw new Error("Expected an article.json object.");
  }
  if (Number(imported.id) !== Number(current.id)) {
    throw new Error(`Wrong article: this editor is #${current.id}, but the file is #${imported.id}.`);
  }
  if (!Array.isArray(imported.sections) || !Array.isArray(current.sections)) {
    throw new Error("The import must contain the full sections array.");
  }
  const existing = new Map(current.sections.map((s) => [Number(s.id), s]));
  if (existing.size !== current.sections.length || imported.sections.length !== current.sections.length) {
    throw new Error("Section count differs. Re-export the current draft before requesting edits.");
  }
  const incoming = new Map();
  for (const section of imported.sections) {
    const id = Number(section?.id);
    if (!Number.isSafeInteger(id) || id <= 0 || incoming.has(id) || !existing.has(id)) {
      throw new Error(`Unexpected or duplicate section ID: ${section?.id ?? "missing"}.`);
    }
    const original = existing.get(id);
    if (section.kind !== original.kind ||
        Number(section.asset_id || 0) !== Number(original.asset_id || 0) ||
        Number(section.palette_id || 0) !== Number(original.palette_id || 0)) {
      throw new Error(`Section #${id} changed kind, photo, or palette. Import rejected.`);
    }
    for (const key of ["heading", "body", "caption"]) {
      if (!(key in section) || (section[key] !== null && typeof section[key] !== "string")) {
        throw new Error(`Section #${id} has an invalid ${key} field.`);
      }
    }
    incoming.set(id, section);
  }
  for (const field of ["title", "dek", "meta_description"]) {
    if (typeof imported[field] !== "string") throw new Error(`Invalid ${field} field.`);
  }
  if (!imported.title.trim()) throw new Error("Import title cannot be empty.");
  return {
    ...current,
    title: imported.title,
    dek: imported.dek,
    meta_description: imported.meta_description,
    sections: current.sections.map((original) => {
      const edited = incoming.get(Number(original.id));
      return {
        ...original,
        heading: edited.heading ?? "",
        body: edited.body ?? "",
        caption: edited.caption ?? "",
      };
    }),
  };
}

const sectionKindOptions = [
  { value: "text", label: "Text", help: "Text only" },
  { value: "image", label: "Image", help: "Image only" },
  { value: "image_text", label: "Image + Text", help: "Image and text" },
  { value: "header", label: "Header", help: "Heading only" },
  { value: "list", label: "List", help: "Bullet list from body lines" },
  { value: "embed", label: "Embed", help: "Raw embed HTML" },
  { value: "cta", label: "CTA", help: "Callout text" },
  { value: "palette_link", label: "Palette Link", help: "Palette reference" },
];
const articleTypes = ["colorfix", "theme", "guide"];

function normalizeSectionForEditor(section = {}) {
  const hasImage = Number(section.asset_id) > 0;
  const hasBody = String(section.body || "").trim() !== "";
  const rawKind = String(section.kind || "text");
  const kind = rawKind === "image" && hasImage && hasBody ? "image_text" : rawKind;
  return {
    ...section,
    editor_key: section.editor_key || `section-${section.id ?? "new"}-${Math.random().toString(36).slice(2, 10)}`,
    kind,
    asset_id: section.asset_id || "",
    palette_id: section.palette_id || "",
    caption: section.caption || "",
    body: section.body || "",
    heading: section.heading || "",
    heading_level: section.heading_level || "h2",
  };
}

const insetCornerOptions = ["top-left", "top-right", "bottom-left", "bottom-right"];
function normalizeInsetPositions(value) {
  let parsed = value;
  if (typeof parsed === "string") {
    try { parsed = JSON.parse(parsed); } catch { parsed = {}; }
  }
  if (!parsed || typeof parsed !== "object" || Array.isArray(parsed)) parsed = {};
  return Object.fromEntries(["desktop", "tablet", "phone"].map((device) => [
    device, insetCornerOptions.includes(parsed[device]) ? parsed[device] : "bottom-right"
  ]));
}

function kindUsesImage(kind) {
  return kind === "image" || kind === "image_text";
}

function kindUsesBody(kind) {
  return kind === "text" || kind === "image_text" || kind === "embed" || kind === "cta" || kind === "list" || kind === "palette_link";
}

function slugify(value = "") {
  return (
    value
      .toLowerCase()
      .trim()
      .replace(/[^a-z0-9]+/g, "-")
      .replace(/^-+|-+$/g, "") || `article-${Date.now()}`
  );
}

export default function AdminArticlesPage() {
  const [articles, setArticles] = useState([]);
  const [searchText, setSearchText] = useState("");
  const [form, setForm] = useState(emptyArticle);
  const [ctaPages, setCtaPages] = useState([]);
  const [ctaPagesError, setCtaPagesError] = useState("");
  const [photoPickerTarget, setPhotoPickerTarget] = useState(null);
  const [showEditor, setShowEditor] = useState(false);
  const [loading, setLoading] = useState(true);
  const [status, setStatus] = useState("");
  const [error, setError] = useState("");
  const [saving, setSaving] = useState(false);
  const [deleting, setDeleting] = useState(false);
  const savedSnapshotRef = useRef(JSON.stringify(emptyArticle));
  const aiImportFileRef = useRef(null);

  function confirmDiscard() {
    return JSON.stringify(form) === savedSnapshotRef.current
      || window.confirm("Discard unsaved article changes?");
  }

  const visibleArticles = useMemo(() => {
    const normalize = (value) => String(value || "").normalize("NFD")
      .replace(/[\u0300-\u036f]/g, "").toLocaleLowerCase().trim();
    const query = normalize(searchText);
    return query ? articles.filter((item) =>
      normalize(`${item.title || ""} ${item.slug || ""}`).includes(query)
    ) : articles;
  }, [articles, searchText]);

  useEffect(() => {
    loadArticles();
    loadCtaPages();
  }, []);

  async function loadArticles() {
    setLoading(true);
    setError("");
    try {
      const params = new URLSearchParams();
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

  async function loadCtaPages() {
    try {
      const res = await fetch(`${CTA_PAGES_LIST_URL}?_=${Date.now()}`, { credentials: "include" });
      const data = await res.json();
      if (!res.ok || !data?.ok) throw new Error(data?.error || "Failed to load CTA pages");
      const items = Array.isArray(data.items) ? data.items : Object.values(data.items || {});
      items.sort((a, b) => (Number(a.id) || 0) - (Number(b.id) || 0));
      setCtaPages(items);
      setCtaPagesError("");
    } catch (err) {
      setCtaPagesError(err.message || "Failed to load CTA pages");
    }
  }

  async function loadArticle(id, skipGuard = false) {
    if (!skipGuard && !confirmDiscard()) return;
    setError("");
    try {
      const res = await fetch(`${GET_URL}?id=${id}&_=${Date.now()}`, { credentials: "include" });
      const text = await res.text();
      if (!res.ok) throw new Error(`HTTP ${res.status}: ${text.slice(0, 200)}`);
      const data = JSON.parse(text);
      if (!data?.ok) throw new Error(data?.error || "Failed to load article");
      const item = data.item || {};
      setShowEditor(true);
      const nextForm = {
        id: item.id || null,
        type: item.type || "colorfix",
        status: item.status || "draft",
        title: item.title || "",
        dek: item.dek || "",
        kicker: item.kicker || "",
        hero_inset_asset_id: item.hero_inset_asset_id || "",
        hero_inset_positions: normalizeInsetPositions(item.hero_inset_positions),
        slug: item.slug || "",
        meta_description: item.meta_description || "",
        hero_asset_id: item.hero_asset_id || "",
        hero_mobile_asset_id: item.hero_mobile_asset_id || "",
        cta_overrides: item.cta_overrides || "",
        featured: !!item.featured,
        published_at: item.published_at || "",
        tags: Array.isArray(item.tags) ? item.tags : [],
        tagsText: Array.isArray(item.tags)
          ? item.tags.map((tag) => tag.slug || tag.name).filter(Boolean).join(", ")
          : "",
        sections: Array.isArray(item.sections) ? item.sections.map(normalizeSectionForEditor) : [],
      };
      savedSnapshotRef.current = JSON.stringify(nextForm);
      setForm(nextForm);
    } catch (err) {
      setError(err?.message || "Failed to load article");
    }
  }

  function handleNewArticle() {
    if (!confirmDiscard()) return;
    const nextForm = { ...emptyArticle, tagsText: "" };
    savedSnapshotRef.current = JSON.stringify(nextForm);
    setForm(nextForm);
    setShowEditor(true);
  }

  function parseCtaOverrides(raw) {
    try {
      const value = typeof raw === "string" ? JSON.parse(raw || "{}") : raw;
      return value && typeof value === "object" && !Array.isArray(value) ? value : {};
    } catch { return {}; }
  }

  const ctaSettings = parseCtaOverrides(form.cta_overrides);
  const ctaPageValue = Object.hasOwn(ctaSettings, "_cta_group_id")
    ? String(ctaSettings._cta_group_id || 0)
    : Object.hasOwn(ctaSettings, "_cta_ids") ? "legacy" : "auto";

  function selectCtaPage(value) {
    setForm((prev) => {
      const settings = { ...parseCtaOverrides(prev.cta_overrides) };
      delete settings._cta_ids;
      delete settings._cta_group_id;
      if (value !== "auto") settings._cta_group_id = Number(value);
      return { ...prev, cta_overrides: JSON.stringify(settings) };
    });
  }

  async function handleAiImport(event) {
    const file = event.target.files?.[0];
    event.target.value = "";
    if (!file) return;
    setError("");
    setStatus("");
    if (!form.id || form.status !== "draft") {
      setError("AI import is available only for a saved draft.");
      return;
    }
    if (JSON.stringify(form) !== savedSnapshotRef.current) {
      setError("Save or discard your current edits before importing AI copy.");
      return;
    }
    try {
      if (file.size > 1024 * 1024) throw new Error("article.json is unexpectedly large (over 1 MB).");
      const imported = JSON.parse(await file.text());
      const merged = mergeAiEditorial(form, imported);
      const fieldsChanged = ["title", "dek", "meta_description"].filter(
        (field) => merged[field] !== form[field]
      ).length;
      const sectionsChanged = merged.sections.filter((section, i) =>
        ["heading", "body", "caption"].some((field) =>
          section[field] !== form.sections[i][field]
        )
      ).length;
      if (!fieldsChanged && !sectionsChanged) {
        setStatus("The imported article contains no editorial changes.");
        return;
      }
      if (!window.confirm(
        `Import AI edits for article #${form.id}?\n\n${fieldsChanged} article fields and ${sectionsChanged} sections differ.\n\n` +
        "This changes only the editor draft, not the database. Review the copy and click Save when ready."
      )) return;
      setForm(merged);
      setStatus(`AI edits loaded: ${fieldsChanged} article fields, ${sectionsChanged} sections. Review and Save to persist.`);
    } catch (err) {
      setError(err instanceof SyntaxError ? "Invalid JSON. Select the revised article.json file." : (err?.message || "Import failed."));
    }
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
        await loadArticle(newId, true);
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
      const nextForm = { ...emptyArticle, tagsText: "" };
      savedSnapshotRef.current = JSON.stringify(nextForm);
      setForm(nextForm);
      setShowEditor(false);
      await loadArticles();
    } catch (err) {
      setError(err?.message || "Delete failed");
    } finally {
      setDeleting(false);
    }
  }

  async function handleDuplicateById(articleId) {
    if (!confirmDiscard()) return;
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
      if (newId) await loadArticle(newId, true);
    } catch (err) {
      setError(err?.message || "Duplicate failed");
    }
  }

  function handleDuplicateCurrent() {
    if (!form.id && !confirmDiscard()) return;
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
      editor_key: `section-tmp-${Date.now()}-${Math.random().toString(36).slice(2, 10)}`,
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

  function handleRemoveSection(editorKey) {
    setForm((prev) => ({
      ...prev,
      sections: prev.sections.filter((section) => section.editor_key !== editorKey),
    }));
  }

  function handleSectionChange(editorKey, changes) {
    setForm((prev) => ({
      ...prev,
      sections: prev.sections.map((section) =>
        section.editor_key === editorKey ? normalizeSectionForEditor({ ...section, ...changes }) : section
      ),
    }));
  }

  function openHeroPhotoPicker() {
    setPhotoPickerTarget({ type: "hero" });
  }

  function openHeroInsetPhotoPicker() {
    setPhotoPickerTarget({ type: "hero_inset" });
  }

  function openHeroMobilePhotoPicker() {
    setPhotoPickerTarget({ type: "hero_mobile" });
  }

  function openSectionPhotoPicker(editorKey) {
    setPhotoPickerTarget({ type: "section", sectionId: editorKey });
  }

  function handlePickPhoto(picked) {
    if (!picked?.photo_library_id) return;
    const nextId = String(picked.photo_library_id);
    if (photoPickerTarget?.type === "hero") {
      setForm((prev) => ({ ...prev, hero_asset_id: nextId }));
    } else if (photoPickerTarget?.type === "hero_inset") {
      setForm((prev) => ({ ...prev, hero_inset_asset_id: nextId }));
    } else if (photoPickerTarget?.type === "hero_mobile") {
      setForm((prev) => ({ ...prev, hero_mobile_asset_id: nextId }));
    } else if (photoPickerTarget?.type === "section" && photoPickerTarget?.sectionId != null) {
      handleSectionChange(photoPickerTarget.sectionId, { asset_id: nextId });
    }
    setPhotoPickerTarget(null);
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
      kicker: article.kicker || "",
      hero_inset_asset_id: article.hero_inset_asset_id ? Number(article.hero_inset_asset_id) : null,
      hero_inset_positions: normalizeInsetPositions(article.hero_inset_positions),
      slug: article.slug || "",
      meta_description: article.meta_description || "",
      hero_asset_id: article.hero_asset_id ? Number(article.hero_asset_id) : null,
      hero_mobile_asset_id: article.hero_mobile_asset_id ? Number(article.hero_mobile_asset_id) : null,
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
        const kind = section.kind || "text";
        const sectionPayload = {
          sort_order: idx,
          kind,
          heading: section.heading || null,
          heading_level: section.heading_level || null,
          body: kindUsesBody(kind) ? section.body || null : null,
          caption: kindUsesImage(kind) ? section.caption || null : null,
          asset_id: kindUsesImage(kind) && section.asset_id ? Number(section.asset_id) : null,
          palette_id: kind === "palette_link" && section.palette_id ? Number(section.palette_id) : null,
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
      {error && <AdminNotice variant="danger">{error}</AdminNotice>}
      {status && <AdminNotice variant="success">{status}</AdminNotice>}

      <AdminMasterDetail
        storageKey="admin-articles-list-width"
        selectedId={showEditor ? form.id : null}
        list={
          <AdminListPane
            title="Articles"
            searchValue={searchText}
            onSearchChange={setSearchText}
            searchPlaceholder="Search articles"
          >
            {loading ? <div className="admin-articles__loading">Loading…</div> : (
              <AdminObjectList>
                {visibleArticles.map((item) => (
                  <AdminObjectListItem
                    key={item.id}
                    id={item.id}
                    title={item.title || "Untitled article"}
                    meta={[`${item.type || "—"} · ${item.status === "published" ? "Published" : "Draft"}`]}
                    selected={showEditor && String(form.id) === String(item.id)}
                    onSelect={() => loadArticle(item.id)}
                  />
                ))}
                {!visibleArticles.length && <div className="admin-articles__empty">No articles found.</div>}
              </AdminObjectList>
            )}
          </AdminListPane>
        }
        detail={
          <AdminDetailPane
            ariaLabel="Article editor"
            title={showEditor ? (form.id ? `Edit Article #${form.id}` : "New Article") : "Article Editor"}
            actions={<div className="admin-articles__editor-actions">
              <AdminButton type="button" onClick={handleNewArticle}>New</AdminButton>
              {showEditor && <>
                <AdminButton type="button" variant="secondary" onClick={handleDuplicateCurrent}>Duplicate</AdminButton>
                {form.id && <AdminButton type="button" variant="secondary" onClick={() => window.open(`/articles/${form.id}?admin=1`, "_blank", "noopener,noreferrer")}>Preview</AdminButton>}
                {form.id && form.status === "draft" && <AdminButton type="button" variant="secondary" onClick={() => { window.location.href = `${AI_EXPORT_URL}?id=${encodeURIComponent(form.id)}`; }}>Export for AI</AdminButton>}
                {form.id && form.status === "draft" && <>
                  <input type="file" accept=".json,application/json" ref={aiImportFileRef} onChange={handleAiImport} style={{ display: "none" }} aria-label="Select revised article.json" />
                  <AdminButton type="button" variant="secondary" disabled={saving} onClick={() => aiImportFileRef.current?.click()}>Import AI Copy</AdminButton>
                </>}
                <AdminButton type="button" onClick={handleSave} disabled={saving}>{saving ? "Saving…" : "Save"}</AdminButton>
                <AdminButton type="button" variant="danger" onClick={handleDelete}
                  disabled={!form.id || form.status === "published" || deleting}>
                  {deleting ? "Deleting…" : "Delete"}
                </AdminButton>
              </>}
            </div>}
          >
            {!showEditor ? (
              <div className="admin-articles__empty">Select an article to edit, or create a new one.</div>
            ) : (
              <div className="admin-articles__editor-content">
                <AdminPanel title="Article details">
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
            Kicker
            <input type="text" value={form.kicker || ""}
              onChange={(e) => setForm((prev) => ({ ...prev, kicker: e.target.value }))}
              placeholder="Editorial series label" />
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
              Hero Image ID
              <div className="admin-articles__asset-row">
                <input
                  type="number"
                  value={form.hero_asset_id || ""}
                  onChange={(e) =>
                    setForm((prev) => ({ ...prev, hero_asset_id: e.target.value }))
                  }
                />
                <button type="button" onClick={openHeroPhotoPicker}>
                  Find
                </button>
              </div>
            </label>
            <label>
              Inset Photo ID (optional)
              <div className="admin-articles__asset-row">
                <input type="number" value={form.hero_inset_asset_id || ""}
                  onChange={(e) => setForm((prev) => ({ ...prev, hero_inset_asset_id: e.target.value }))} />
                <button type="button" onClick={openHeroInsetPhotoPicker}>Find</button>
                {form.hero_inset_asset_id && <button type="button" onClick={() => setForm((prev) => ({ ...prev, hero_inset_asset_id: "" }))}>Clear</button>}
              </div>
            </label>
            {form.hero_inset_asset_id && ["desktop", "tablet", "phone"].map((device) => (
              <label key={device}>
                {device.charAt(0).toUpperCase() + device.slice(1)} Inset Corner
                <select value={normalizeInsetPositions(form.hero_inset_positions)[device]}
                  onChange={(e) => setForm((prev) => ({ ...prev, hero_inset_positions: {
                    ...normalizeInsetPositions(prev.hero_inset_positions), [device]: e.target.value
                  } }))}>
                  {insetCornerOptions.map((corner) => <option key={corner} value={corner}>{corner.replace("-", " ")}</option>)}
                </select>
              </label>
            ))}
            <label>
              Mobile Hero Image ID
              <div className="admin-articles__asset-row">
                <input
                  type="number"
                  value={form.hero_mobile_asset_id || ""}
                  onChange={(e) =>
                    setForm((prev) => ({ ...prev, hero_mobile_asset_id: e.target.value }))
                  }
                />
                <button type="button" onClick={openHeroMobilePhotoPicker}>
                  Find
                </button>
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

                </AdminPanel>
                <AdminPanel title="Calls to action">
                  <label className="admin-articles__field">
                    CTA Page
                    <select aria-label="CTA Page" value={ctaPageValue} onChange={(event) => selectCtaPage(event.target.value)}>
                      <option value="auto">Automatic (article default)</option>
                      <option value="0">None</option>
                      {ctaPageValue === "legacy" && <option value="legacy" disabled>Existing individual selection — choose a CTA Page to replace</option>}
                      {Number(ctaPageValue) > 0 && !ctaPages.some((page) => Number(page.id) === Number(ctaPageValue)) && (
                        <option value={ctaPageValue}>CTA Page #{ctaPageValue} (unavailable)</option>
                      )}
                      {ctaPages.map((page) => (
                        <option key={page.id} value={String(page.id)}>{page.label} — #{page.id}</option>
                      ))}
                    </select>
                  </label>
                  {ctaPagesError && <AdminNotice variant="danger">{ctaPagesError}</AdminNotice>}
                  <p>The selected page supplies its current group of CTAs. Save the article to apply changes.</p>

                </AdminPanel>
                <AdminPanel title="Classification">
          <label className="admin-articles__field">
            Tags (comma separated)
            <input
              type="text"
              value={form.tagsText || ""}
            onChange={(e) => setForm((prev) => ({ ...prev, tagsText: e.target.value }))}
            placeholder="door, exterior, adobe"
          />
        </label>

                </AdminPanel>
                <AdminPanel title="Sections">
          <div className="admin-articles__sections">
            <div className="admin-articles__sections-header">
              <h3>Sections</h3>
              <button type="button" onClick={handleAddSection}>
                Add Section
              </button>
            </div>

            {form.sections.map((section) => (
              <div key={section.editor_key} className="admin-articles__section-card">
                {(() => {
                  const usesImage = kindUsesImage(section.kind);
                  const usesBody = kindUsesBody(section.kind);
                  const usesPalette = section.kind === "palette_link";
                  return (
                    <>
                <div className="admin-articles__section-row">
                  <label>
                    Sort
                    <input
                      type="number"
                      step="0.1"
                      value={section.sort_order ?? 0}
                      onChange={(e) =>
                        handleSectionChange(section.editor_key, { sort_order: e.target.value })
                      }
                    />
                  </label>
                  <label>
                    Kind
                    <select
                      value={section.kind || "text"}
                      onChange={(e) =>
                        handleSectionChange(section.editor_key, { kind: e.target.value })
                      }
                    >
                      {sectionKindOptions.map((kind) => (
                        <option key={kind.value} value={kind.value}>
                          {kind.label} ({kind.help})
                        </option>
                      ))}
                    </select>
                  </label>
                  {usesImage && (
                    <label>
                      Photo Library ID
                      <div className="admin-articles__asset-row">
                        <input
                          type="number"
                          value={section.asset_id || ""}
                          onChange={(e) =>
                            handleSectionChange(section.editor_key, { asset_id: e.target.value })
                          }
                        />
                        <button type="button" onClick={() => openSectionPhotoPicker(section.editor_key)}>
                          Find
                        </button>
                      </div>
                    </label>
                  )}
                  {usesPalette && (
                    <label>
                      Palette ID
                      <input
                        type="number"
                        value={section.palette_id || ""}
                        onChange={(e) =>
                          handleSectionChange(section.editor_key, { palette_id: e.target.value })
                        }
                      />
                    </label>
                  )}
                </div>
                <div className="admin-articles__heading-row">
                  <label className="admin-articles__heading-field">
                    Heading
                    <input
                      type="text"
                      value={section.heading || ""}
                      onChange={(e) =>
                        handleSectionChange(section.editor_key, { heading: e.target.value })
                      }
                    />
                  </label>
                  <label className="admin-articles__heading-level">
                    Heading Level
                    <select
                      value={section.heading_level || "h2"}
                      onChange={(e) =>
                        handleSectionChange(section.editor_key, { heading_level: e.target.value })
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
                {usesBody && (
                  <label>
                    {section.kind === "list" ? "List Items" : "Body"}
                    <textarea
                      rows={6}
                      value={section.body || ""}
                      onChange={(e) =>
                        handleSectionChange(section.editor_key, { body: e.target.value })
                      }
                    />
                  </label>
                )}
                {usesImage && section.asset?.rel_path && (
                  <div className="admin-articles__cta-item">
                    <div className="admin-articles__cta-title">Current image</div>
                    <div className="admin-articles__cta-meta">
                      {section.asset.photo_library_id || section.asset_id}
                    </div>
                    <PhotoPreviewImage
                      src={buildImageUrl(section.asset.rel_path, section.asset.updated_at || null)}
                      alt={section.asset.alt_text || section.heading || ""}
                      style={{ maxWidth: "220px", borderRadius: "12px", marginTop: "10px" }}
                    />
                  </div>
                )}
                {usesImage && (
                  <label>
                    Caption
                    <input
                      type="text"
                      value={section.caption || ""}
                      onChange={(e) =>
                        handleSectionChange(section.editor_key, { caption: e.target.value })
                      }
                    />
                  </label>
                )}
                <div className="admin-articles__section-actions">
                  <button
                    type="button"
                    className="admin-articles__danger"
                    onClick={() => handleRemoveSection(section.editor_key)}
                  >
                    Remove
                  </button>
                  <button type="button" className="admin-articles__save-inline" onClick={handleSave}>
                    Save
                  </button>
                </div>
                    </>
                  );
                })()}
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
                </AdminPanel>
              </div>
            )}
          </AdminDetailPane>
        }
      />

      <PhotoPickerModal
        open={photoPickerTarget != null}
        title="Pick Article Photo"
        onClose={() => setPhotoPickerTarget(null)}
        onPick={handlePickPhoto}
      />
    </div>
  );
}

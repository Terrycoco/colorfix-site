import { useCallback, useEffect, useMemo, useState } from "react";
import { API_FOLDER } from "@helpers/config";
import PhotoPickerModal from "@components/PhotoPickerModal";
import "./admin-projects.css";

const LIST_URL = `${API_FOLDER}/v2/admin/projects/list.php`;
const GET_URL = `${API_FOLDER}/v2/admin/projects/get.php`;
const SAVE_URL = `${API_FOLDER}/v2/admin/projects/save.php`;
const DELETE_URL = `${API_FOLDER}/v2/admin/projects/delete.php`;
const LINK_SAVE_URL = `${API_FOLDER}/v2/admin/projects/link-save.php`;
const LINK_DELETE_URL = `${API_FOLDER}/v2/admin/projects/link-delete.php`;
const PHOTO_LIBRARY_LIST_URL = `${API_FOLDER}/v2/admin/photo-library/list.php`;
const PLAYLISTS_LIST_URL = `${API_FOLDER}/v2/admin/playlists/list.php`;
const SAVED_LIST_URL = `${API_FOLDER}/v2/admin/saved-palettes.php`;

const projectTypes = ["interior", "exterior", "hoa", "case-study", ];
const projectStatuses = ["draft", "published", "archived"];
const assetTypes = ["photo", "playlist", "article", "pin", "palette"];

const emptyProject = {
  id: null,
  slug: "",
  title: "",
  project_type: "interior",
  status: "draft",
  summary: "",
  notes: "",
  client_name: "",
};

const emptyLink = {
  id: null,
  project_id: null,
  asset_type: "photo",
  asset_id: "",
  role: "",
  sort_order: 0,
  notes: "",
};

function slugify(value = "") {
  return String(value)
    .toLowerCase()
    .trim()
    .replace(/[^a-z0-9]+/g, "-")
    .replace(/^-+|-+$/g, "");
}

export default function AdminProjectsPage() {
  const [projects, setProjects] = useState([]);
  const [selectedProjectId, setSelectedProjectId] = useState(null);
  const [projectForm, setProjectForm] = useState(emptyProject);
  const [links, setLinks] = useState([]);
  const [newLink, setNewLink] = useState(emptyLink);
  const [photoThumbs, setPhotoThumbs] = useState({});
  const [filters, setFilters] = useState({ q: "", status: "", project_type: "" });
  const [loadingList, setLoadingList] = useState(true);
  const [loadingDetail, setLoadingDetail] = useState(false);
  const [status, setStatus] = useState("");
  const [error, setError] = useState("");
  const [photoPickerOpen, setPhotoPickerOpen] = useState(false);
  const [playlistOptions, setPlaylistOptions] = useState([]);
  const [paletteOptions, setPaletteOptions] = useState([]);

  const groupedLinks = useMemo(() => {
    const groups = {};
    for (const link of links) {
      const key = link.asset_type || "other";
      if (!groups[key]) groups[key] = [];
      groups[key].push(link);
    }
    return groups;
  }, [links]);

  const newLinkAssetMeta = useMemo(() => {
    switch (newLink.asset_type) {
      case "photo":
        return {
          idLabel: "Photo Library ID",
          idPlaceholder: "Pick a photo or enter its photo library id",
          rolePlaceholder: "hero, living-room-before, detail…",
          notesPlaceholder: "Internal note about how this photo is used",
        };
      case "playlist":
        return {
          idLabel: "Playlist ID",
          idPlaceholder: "e.g. 13",
          rolePlaceholder: "main, teaser, alternate…",
          notesPlaceholder: "Internal note about this playlist link",
        };
      case "article":
        return {
          idLabel: "Article ID",
          idPlaceholder: "e.g. 22",
          rolePlaceholder: "case-study, supporting-read…",
          notesPlaceholder: "Internal note about this article link",
        };
      case "pin":
        return {
          idLabel: "Pin ID",
          idPlaceholder: "e.g. 501",
          rolePlaceholder: "before-after, detail, palette…",
          notesPlaceholder: "Internal note about this pin link",
        };
      case "palette":
        return {
          idLabel: "Palette ID",
          idPlaceholder: "e.g. 91",
          rolePlaceholder: "living-room, alternate-option…",
          notesPlaceholder: "Internal note about this palette link",
        };
      default:
        return {
          idLabel: "Asset ID",
          idPlaceholder: "Enter asset id",
          rolePlaceholder: "role",
          notesPlaceholder: "Internal note",
        };
    }
  }, [newLink.asset_type]);

  const newLinkPhotoThumb = useMemo(() => {
    if (!["photo", "pin"].includes(newLink.asset_type)) return "";
    return photoThumbs[Number(newLink.asset_id)] || "";
  }, [newLink.asset_id, newLink.asset_type, photoThumbs]);

  useEffect(() => {
    const ids = Array.from(new Set(
      links
        .filter((link) => ["photo", "pin"].includes(link.asset_type) && Number(link.asset_id) > 0)
        .map((link) => Number(link.asset_id))
        .filter((id) => !photoThumbs[id])
    ));
    if (!ids.length) return;

    let cancelled = false;
    (async () => {
      try {
        const params = new URLSearchParams();
        params.set("photo_library_ids", ids.join(","));
        params.set("limit", String(ids.length));
        params.set("_", String(Date.now()));
        const res = await fetch(`${PHOTO_LIBRARY_LIST_URL}?${params.toString()}`, {
          credentials: "include",
        });
        const data = await res.json();
        if (!res.ok || !data?.ok || cancelled) return;
        setPhotoThumbs((prev) => {
          const next = { ...prev };
          for (const item of data.items || []) {
            if (item?.photo_library_id) {
              next[Number(item.photo_library_id)] = item.rel_path || "";
            }
          }
          return next;
        });
      } catch {
        // ignore thumb lookup failures
      }
    })();

    return () => {
      cancelled = true;
    };
  }, [links, photoThumbs]);

  useEffect(() => {
    let cancelled = false;
    (async () => {
      try {
        const res = await fetch(`${PLAYLISTS_LIST_URL}?_=${Date.now()}`, { credentials: "include" });
        const data = await res.json();
        if (!res.ok || !data?.ok || cancelled) return;
        setPlaylistOptions(Array.isArray(data.items) ? data.items : []);
      } catch {
        // ignore optional playlist convenience list failures
      }
    })();

    return () => {
      cancelled = true;
    };
  }, []);

  useEffect(() => {
    let cancelled = false;
    (async () => {
      try {
        const params = new URLSearchParams();
        params.set("limit", "500");
        params.set("_", String(Date.now()));
        const res = await fetch(`${SAVED_LIST_URL}?${params.toString()}`, { credentials: "include" });
        const data = await res.json();
        if (!res.ok || !data?.ok || cancelled) return;
        setPaletteOptions(Array.isArray(data.items) ? data.items : []);
      } catch {
        // ignore optional palette convenience list failures
      }
    })();

    return () => {
      cancelled = true;
    };
  }, []);

  const loadProject = useCallback(async (id) => {
    if (!id) return;
    setLoadingDetail(true);
    setError("");
    try {
      const res = await fetch(`${GET_URL}?id=${id}&_=${Date.now()}`, { credentials: "include" });
      const text = await res.text();
      if (!res.ok) throw new Error(`HTTP ${res.status}: ${text.slice(0, 200)}`);
      const data = JSON.parse(text);
      if (!data?.ok || !data?.project) throw new Error(data?.error || "Failed to load project");
      setProjectForm({
        id: data.project.id,
        slug: data.project.slug || "",
        title: data.project.title || "",
        project_type: data.project.project_type || "interior",
        status: data.project.status || "draft",
        summary: data.project.summary || "",
        notes: data.project.notes || "",
        client_name: data.project.client_name || "",
      });
      setLinks(Array.isArray(data.links) ? data.links : []);
      setNewLink((prev) => ({ ...prev, project_id: data.project.id }));
    } catch (err) {
      setError(err?.message || "Failed to load project");
    } finally {
      setLoadingDetail(false);
    }
  }, []);

  const loadProjects = useCallback(async (preferredId = null) => {
    setLoadingList(true);
    setError("");
    try {
      const params = new URLSearchParams();
      if (filters.project_type) params.set("project_type", filters.project_type);
      if (filters.status) params.set("status", filters.status);
      params.set("_", String(Date.now()));
      const res = await fetch(`${LIST_URL}?${params.toString()}`, { credentials: "include" });
      const text = await res.text();
      if (!res.ok) throw new Error(`HTTP ${res.status}: ${text.slice(0, 200)}`);
      const data = JSON.parse(text);
      if (!data?.ok) throw new Error(data?.error || "Failed to load projects");
      const items = Array.isArray(data.items) ? data.items : [];
      setProjects(items);

      const targetId =
        preferredId ||
        selectedProjectId ||
        (items[0] ? Number(items[0].id) : null);

      if (targetId) {
        setSelectedProjectId(targetId);
        await loadProject(targetId);
      } else {
        setSelectedProjectId(null);
        setProjectForm(emptyProject);
        setLinks([]);
        setNewLink(emptyLink);
      }
    } catch (err) {
      setError(err?.message || "Failed to load projects");
    } finally {
      setLoadingList(false);
    }
  }, [filters.project_type, filters.status, loadProject, selectedProjectId]);

  useEffect(() => {
    void loadProjects();
  }, [loadProjects]);

  function resetProjectForm() {
    setSelectedProjectId(null);
    setProjectForm(emptyProject);
    setLinks([]);
    setNewLink(emptyLink);
    setStatus("");
    setError("");
  }

  async function handleSaveProject() {
    setStatus("");
    setError("");
    try {
      const payload = {
        ...projectForm,
        slug: projectForm.slug.trim(),
        title: projectForm.title.trim(),
        client_name: projectForm.client_name.trim(),
      };
      if (!payload.slug && payload.title) {
        payload.slug = slugify(payload.title);
      }
      const res = await fetch(SAVE_URL, {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(payload),
      });
      const text = await res.text();
      if (!res.ok) throw new Error(`HTTP ${res.status}: ${text.slice(0, 200)}`);
      const data = JSON.parse(text);
      if (!data?.ok) throw new Error(data?.error || "Failed to save project");
      setStatus("Project saved.");
      await loadProjects(Number(data.id));
    } catch (err) {
      setError(err?.message || "Failed to save project");
    }
  }

  async function handleDeleteProject() {
    if (!projectForm.id) return;
    if (!window.confirm(`Delete project "${projectForm.title}"?`)) return;
    setStatus("");
    setError("");
    try {
      const res = await fetch(DELETE_URL, {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ id: projectForm.id }),
      });
      const text = await res.text();
      if (!res.ok) throw new Error(`HTTP ${res.status}: ${text.slice(0, 200)}`);
      const data = JSON.parse(text);
      if (!data?.ok) throw new Error(data?.error || "Failed to delete project");
      setStatus("Project deleted.");
      resetProjectForm();
      await loadProjects();
    } catch (err) {
      setError(err?.message || "Failed to delete project");
    }
  }

  async function handleSaveLink(link) {
    if (!projectForm.id) {
      setError("Save the project before adding assets.");
      return;
    }
    setStatus("");
    setError("");
    try {
      const payload = {
        ...link,
        project_id: projectForm.id,
        asset_id: Number(link.asset_id),
        sort_order: Number(link.sort_order || 0),
      };
      const res = await fetch(LINK_SAVE_URL, {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(payload),
      });
      const text = await res.text();
      if (!res.ok) throw new Error(`HTTP ${res.status}: ${text.slice(0, 200)}`);
      const data = JSON.parse(text);
      if (!data?.ok) throw new Error(data?.error || "Failed to save asset link");
      setStatus(link.id ? "Asset link updated." : "Asset linked.");
      await loadProject(projectForm.id);
      if (!link.id) {
        setNewLink({ ...emptyLink, project_id: projectForm.id });
      }
    } catch (err) {
      setError(err?.message || "Failed to save asset link");
    }
  }

  async function handleDeleteLink(id) {
    if (!window.confirm("Remove this asset from the project?")) return;
    setStatus("");
    setError("");
    try {
      const res = await fetch(LINK_DELETE_URL, {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ id }),
      });
      const text = await res.text();
      if (!res.ok) throw new Error(`HTTP ${res.status}: ${text.slice(0, 200)}`);
      const data = JSON.parse(text);
      if (!data?.ok) throw new Error(data?.error || "Failed to delete asset link");
      setStatus("Asset removed.");
      await loadProject(projectForm.id);
    } catch (err) {
      setError(err?.message || "Failed to delete asset link");
    }
  }

  const visibleProjects = useMemo(() => {
    const query = filters.q.trim().toLowerCase();
    if (!query) return projects;
    return projects.filter((project) => {
      return [project.title, project.slug, project.client_name]
        .join(" ")
        .toLowerCase()
        .includes(query);
    });
  }, [projects, filters.q]);

  return (
    <div className="admin-projects">
      <div className="admin-projects__sidebar">
        <div className="admin-projects__sidebar-header">
          <div>
            <h1>Projects</h1>
            <p>Group playlists, photos, palettes, pins, and articles into one project.</p>
          </div>
          <button type="button" className="admin-projects__btn admin-projects__btn--primary" onClick={resetProjectForm}>
            New Project
          </button>
        </div>

        <div className="admin-projects__filters">
          <input
            type="text"
            placeholder="Search projects"
            value={filters.q}
            onChange={(e) => setFilters((prev) => ({ ...prev, q: e.target.value }))}
          />
          <select
            value={filters.project_type}
            onChange={(e) => setFilters((prev) => ({ ...prev, project_type: e.target.value }))}
          >
            <option value="">All types</option>
            {projectTypes.map((type) => (
              <option key={type} value={type}>{type}</option>
            ))}
          </select>
          <select
            value={filters.status}
            onChange={(e) => setFilters((prev) => ({ ...prev, status: e.target.value }))}
          >
            <option value="">All statuses</option>
            {projectStatuses.map((item) => (
              <option key={item} value={item}>{item}</option>
            ))}
          </select>
        </div>

        <div className="admin-projects__project-list">
          {loadingList ? (
            <div className="admin-projects__empty">Loading projects…</div>
          ) : visibleProjects.length === 0 ? (
            <div className="admin-projects__empty">No projects yet.</div>
          ) : visibleProjects.map((project) => (
            <button
              key={project.id}
              type="button"
              className={`admin-projects__project-card${Number(selectedProjectId) === Number(project.id) ? " is-active" : ""}`}
              onClick={() => {
                setSelectedProjectId(project.id);
                loadProject(project.id);
              }}
            >
              <div className="admin-projects__project-title">{project.title}</div>
              <div className="admin-projects__project-meta">
                <span>{project.project_type}</span>
                <span>{project.status}</span>
              </div>
              <div className="admin-projects__project-slug">{project.slug}</div>
              <div className="admin-projects__counts">
                <span>{project.asset_counts?.total || 0} assets</span>
                <span>{project.asset_counts?.photo || 0} photos</span>
                <span>{project.asset_counts?.playlist || 0} playlists</span>
              </div>
            </button>
          ))}
        </div>
      </div>

      <div className="admin-projects__main">
        {error && <div className="admin-projects__message admin-projects__message--error">{error}</div>}
        {status && <div className="admin-projects__message admin-projects__message--status">{status}</div>}

        <section className="admin-projects__panel">
          <div className="admin-projects__panel-header">
            <h2>{projectForm.id ? `Project #${projectForm.id}` : "New Project"}</h2>
            <div className="admin-projects__actions">
              <button type="button" className="admin-projects__btn" onClick={() => setProjectForm((prev) => ({ ...prev, slug: slugify(prev.title) }))}>
                Make Slug
              </button>
              <button type="button" className="admin-projects__btn admin-projects__btn--primary" onClick={handleSaveProject}>
                Save Project
              </button>
              <button type="button" className="admin-projects__btn admin-projects__btn--danger" onClick={handleDeleteProject} disabled={!projectForm.id}>
                Delete
              </button>
            </div>
          </div>

          <div className="admin-projects__form-grid">
            <label>
              <span>Title</span>
              <input
                type="text"
                value={projectForm.title}
                onChange={(e) => setProjectForm((prev) => ({ ...prev, title: e.target.value }))}
              />
            </label>
            <label>
              <span>Slug</span>
              <input
                type="text"
                value={projectForm.slug}
                onChange={(e) => setProjectForm((prev) => ({ ...prev, slug: e.target.value }))}
              />
            </label>
            <label>
              <span>Project Type</span>
              <select
                value={projectForm.project_type}
                onChange={(e) => setProjectForm((prev) => ({ ...prev, project_type: e.target.value }))}
              >
                {projectTypes.map((type) => (
                  <option key={type} value={type}>{type}</option>
                ))}
              </select>
            </label>
            <label>
              <span>Status</span>
              <select
                value={projectForm.status}
                onChange={(e) => setProjectForm((prev) => ({ ...prev, status: e.target.value }))}
              >
                {projectStatuses.map((item) => (
                  <option key={item} value={item}>{item}</option>
                ))}
              </select>
            </label>
            <label>
              <span>Client Name</span>
              <input
                type="text"
                value={projectForm.client_name}
                onChange={(e) => setProjectForm((prev) => ({ ...prev, client_name: e.target.value }))}
              />
            </label>
            <label className="admin-projects__wide">
              <span>Summary</span>
              <textarea
                rows={3}
                value={projectForm.summary}
                onChange={(e) => setProjectForm((prev) => ({ ...prev, summary: e.target.value }))}
              />
            </label>
            <label className="admin-projects__wide">
              <span>Internal Notes</span>
              <textarea
                rows={5}
                value={projectForm.notes}
                onChange={(e) => setProjectForm((prev) => ({ ...prev, notes: e.target.value }))}
              />
            </label>
          </div>
        </section>

        <section className="admin-projects__panel">
          <div className="admin-projects__panel-header">
            <h2>Link Asset</h2>
          </div>

          <div className="admin-projects__link-form">
            <label>
              <span>Type</span>
              <select
                value={newLink.asset_type}
                onChange={(e) => setNewLink((prev) => ({ ...prev, asset_type: e.target.value, asset_id: "", role: "", notes: "" }))}
              >
                {assetTypes.map((type) => (
                  <option key={type} value={type}>{type}</option>
                ))}
              </select>
            </label>
            {["photo", "pin"].includes(newLink.asset_type) ? (
              <div className="admin-projects__field admin-projects__field--asset admin-projects__wide">
                <span>{newLinkAssetMeta.idLabel}</span>
                <div className="admin-projects__asset-picker">
                  {newLinkPhotoThumb ? (
                    <img className="admin-projects__asset-thumb" src={newLinkPhotoThumb} alt="" loading="lazy" />
                  ) : (
                    <div className="admin-projects__asset-thumb admin-projects__asset-thumb--empty" aria-hidden="true" />
                  )}
                  <input
                    type="number"
                    value={newLink.asset_id}
                    onChange={(e) => setNewLink((prev) => ({ ...prev, asset_id: e.target.value }))}
                    placeholder={newLinkAssetMeta.idPlaceholder}
                  />
                  <button
                    type="button"
                    className="admin-projects__btn"
                    onClick={() => setPhotoPickerOpen(true)}
                    disabled={!projectForm.id}
                  >
                    {newLink.asset_type === "pin" ? "Open Pin Library" : "Open Photo Library"}
                  </button>
                </div>
              </div>
            ) : newLink.asset_type === "playlist" ? (
              <label>
                <span>{newLinkAssetMeta.idLabel}</span>
                <select
                  value={newLink.asset_id}
                  onChange={(e) => setNewLink((prev) => ({ ...prev, asset_id: e.target.value }))}
                >
                  <option value="">Select a playlist…</option>
                  {playlistOptions.map((playlist) => (
                    <option key={playlist.playlist_id} value={playlist.playlist_id}>
                      {playlist.title || `Playlist ${playlist.playlist_id}`}
                    </option>
                  ))}
                </select>
              </label>
            ) : newLink.asset_type === "palette" ? (
              <label>
                <span>{newLinkAssetMeta.idLabel}</span>
                <select
                  value={newLink.asset_id}
                  onChange={(e) => setNewLink((prev) => ({ ...prev, asset_id: e.target.value }))}
                >
                  <option value="">Select a palette…</option>
                  {paletteOptions.map((palette) => (
                    <option key={palette.id} value={palette.id}>
                      {(palette.nickname || "").trim() || `Saved #${palette.id}`}
                    </option>
                  ))}
                </select>
              </label>
            ) : (
              <label>
                <span>{newLinkAssetMeta.idLabel}</span>
                <input
                  type="number"
                  value={newLink.asset_id}
                  onChange={(e) => setNewLink((prev) => ({ ...prev, asset_id: e.target.value }))}
                  placeholder={newLinkAssetMeta.idPlaceholder}
                />
              </label>
            )}
            <label>
              <span>Role</span>
              <input
                type="text"
                value={newLink.role}
                onChange={(e) => setNewLink((prev) => ({ ...prev, role: e.target.value }))}
                placeholder={newLinkAssetMeta.rolePlaceholder}
              />
            </label>
            <label>
              <span>Sort</span>
              <input
                type="number"
                value={newLink.sort_order}
                onChange={(e) => setNewLink((prev) => ({ ...prev, sort_order: e.target.value }))}
              />
            </label>
            <label className="admin-projects__wide">
              <span>Notes</span>
              <input
                type="text"
                value={newLink.notes}
                onChange={(e) => setNewLink((prev) => ({ ...prev, notes: e.target.value }))}
                placeholder={newLinkAssetMeta.notesPlaceholder}
              />
            </label>
            <div className="admin-projects__actions">
              <button
                type="button"
                className="admin-projects__btn admin-projects__btn--primary"
                onClick={() => handleSaveLink(newLink)}
                disabled={!projectForm.id}
              >
                Add Asset
              </button>
            </div>
          </div>
        </section>

        <section className="admin-projects__panel">
          <div className="admin-projects__panel-header">
            <h2>Linked Assets</h2>
          </div>

          {loadingDetail ? (
            <div className="admin-projects__empty">Loading project assets…</div>
          ) : !projectForm.id ? (
            <div className="admin-projects__empty">Save a project first, then start linking assets.</div>
          ) : links.length === 0 ? (
            <div className="admin-projects__empty">No linked assets yet.</div>
          ) : (
            <div className="admin-projects__groups">
              {Object.entries(groupedLinks).map(([type, typeLinks]) => (
                <div className="admin-projects__group" key={type}>
                  <div className="admin-projects__group-title">{type}</div>
                  <div className="admin-projects__link-list">
                    {typeLinks.map((link) => (
                      <div className="admin-projects__link-row" key={link.id}>
                        <div className="admin-projects__thumb-wrap">
                          {["photo", "pin"].includes(link.asset_type) && photoThumbs[Number(link.asset_id)] ? (
                            <img
                              className="admin-projects__thumb"
                              src={photoThumbs[Number(link.asset_id)]}
                              alt=""
                              loading="lazy"
                            />
                          ) : (
                            <div className="admin-projects__thumb admin-projects__thumb--placeholder">
                              {link.asset_type}
                            </div>
                          )}
                        </div>
                        <label>
                          <span>Asset ID</span>
                          <input
                            type="number"
                            value={link.asset_id}
                            onChange={(e) => setLinks((prev) => prev.map((item) => (
                              item.id === link.id ? { ...item, asset_id: e.target.value } : item
                            )))}
                          />
                        </label>
                        <label>
                          <span>Role</span>
                          <input
                            type="text"
                            value={link.role || ""}
                            onChange={(e) => setLinks((prev) => prev.map((item) => (
                              item.id === link.id ? { ...item, role: e.target.value } : item
                            )))}
                          />
                        </label>
                        <label>
                          <span>Sort</span>
                          <input
                            type="number"
                            value={link.sort_order}
                            onChange={(e) => setLinks((prev) => prev.map((item) => (
                              item.id === link.id ? { ...item, sort_order: e.target.value } : item
                            )))}
                          />
                        </label>
                        <label className="admin-projects__notes-field">
                          <span>Notes</span>
                          <input
                            type="text"
                            value={link.notes || ""}
                            onChange={(e) => setLinks((prev) => prev.map((item) => (
                              item.id === link.id ? { ...item, notes: e.target.value } : item
                            )))}
                          />
                        </label>
                        <div className="admin-projects__actions">
                          <button type="button" className="admin-projects__btn" onClick={() => handleSaveLink(link)}>
                            Save
                          </button>
                          <button type="button" className="admin-projects__btn admin-projects__btn--danger" onClick={() => handleDeleteLink(link.id)}>
                            Remove
                          </button>
                        </div>
                      </div>
                    ))}
                  </div>
                </div>
              ))}
            </div>
          )}
        </section>
      </div>

      <PhotoPickerModal
        open={photoPickerOpen}
        title={newLink.asset_type === "pin" ? "Pick Project Pin" : "Pick Project Photo"}
        sourceType={newLink.asset_type === "pin" ? "pin" : ""}
        onClose={() => setPhotoPickerOpen(false)}
        onPick={(picked) => {
          if (!picked?.photo_library_id) return;
          if (picked?.image_url) {
            setPhotoThumbs((prev) => ({
              ...prev,
              [Number(picked.photo_library_id)]: picked.image_url,
            }));
          }
          setNewLink((prev) => ({
            ...prev,
            asset_type: prev.asset_type === "pin" ? "pin" : "photo",
            asset_id: String(picked.photo_library_id),
          }));
          setPhotoPickerOpen(false);
        }}
      />
    </div>
  );
}

import { useEffect, useMemo, useState } from "react";
import { Check, ChevronDown, ChevronRight, Edit2, Plus, RotateCcw, Trash2, X, ArrowUp, ArrowDown } from "lucide-react";
import ModalDialog from "@components/ModalDialog";
import { API_FOLDER } from "@helpers/config";
import "./admin-milestones.css";

const API_BASE = `${API_FOLDER}/v2/admin/milestones`;
const emptyMilestoneForm = {
  id: null,
  category_id: "",
  title: "",
  notes: "",
  position_mode: "end",
  position_ref_id: "",
};
const emptyCategoryForm = {
  id: null,
  name: "",
  preview_count: 3,
  is_archived: false,
};

function formatDate(value) {
  if (!value) return "—";
  const date = new Date(String(value).replace(" ", "T"));
  if (Number.isNaN(date.getTime())) return "—";
  return date.toLocaleDateString("en-US", { month: "short", day: "numeric", year: "numeric" });
}

function notesPreview(notes) {
  const text = String(notes || "").trim().replace(/\s+/g, " ");
  if (!text) return "";
  return text.length > 90 ? `${text.slice(0, 90)}...` : text;
}

async function parseJsonResponse(res) {
  const text = await res.text();
  if (!res.ok) throw new Error(`HTTP ${res.status}: ${text.slice(0, 200)}`);
  try {
    return JSON.parse(text);
  } catch {
    throw new Error("Invalid JSON response");
  }
}

export default function AdminMilestonesPage({ embedded = false }) {
  const [categories, setCategories] = useState([]);
  const [milestones, setMilestones] = useState([]);
  const [deletedCategories, setDeletedCategories] = useState([]);
  const [deletedMilestones, setDeletedMilestones] = useState([]);
  const [expanded, setExpanded] = useState({});
  const [showAll, setShowAll] = useState({});
  const [recentlyChecked, setRecentlyChecked] = useState({});
  const [activeView, setActiveView] = useState("category");
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState("");
  const [status, setStatus] = useState("");
  const [milestoneModalOpen, setMilestoneModalOpen] = useState(false);
  const [milestoneForm, setMilestoneForm] = useState(emptyMilestoneForm);
  const [categoryModalOpen, setCategoryModalOpen] = useState(false);
  const [categoryForm, setCategoryForm] = useState(emptyCategoryForm);

  useEffect(() => {
    loadAll();
  }, []);

  useEffect(() => {
    const timers = Object.entries(recentlyChecked).map(([id, timestamp]) => {
      const remaining = Math.max(0, 2500 - (Date.now() - timestamp));
      return window.setTimeout(() => {
        setRecentlyChecked((prev) => {
          const next = { ...prev };
          delete next[id];
          return next;
        });
      }, remaining);
    });
    return () => timers.forEach(window.clearTimeout);
  }, [recentlyChecked]);

  const milestonesByCategory = useMemo(() => {
    const grouped = new Map();
    for (const category of categories) grouped.set(category.id, []);
    for (const milestone of milestones) {
      if (!grouped.has(milestone.category_id)) grouped.set(milestone.category_id, []);
      grouped.get(milestone.category_id).push(milestone);
    }
    for (const items of grouped.values()) {
      items.sort((a, b) => a.sort_order - b.sort_order || a.id - b.id);
    }
    return grouped;
  }, [categories, milestones]);

  const allMilestoneOptions = useMemo(() => {
    return milestones.map((milestone) => {
      const category = categories.find((item) => item.id === milestone.category_id);
      return { ...milestone, category_name: category?.name || "" };
    });
  }, [categories, milestones]);

  const nextMilestones = useMemo(() => {
    return categories.flatMap((category) => {
      const items = milestonesByCategory.get(category.id) || [];
      const next = items.find((milestone) => !milestone.achieved_at);
      return next ? [{ ...next, category_name: category.name }] : [];
    });
  }, [categories, milestonesByCategory]);

  const achievedHistory = useMemo(() => {
    return allMilestoneOptions
      .filter((milestone) => milestone.achieved_at)
      .sort((a, b) => String(b.achieved_at).localeCompare(String(a.achieved_at)));
  }, [allMilestoneOptions]);

  async function loadAll({ quiet = false } = {}) {
    if (!quiet) setLoading(true);
    setError("");
    try {
      const data = await parseJsonResponse(await fetch(`${API_BASE}/list.php?_=${Date.now()}`, { credentials: "include" }));
      if (!data?.ok) throw new Error(data?.error || "Failed to load milestones");
      setCategories(Array.isArray(data.categories) ? data.categories : []);
      setMilestones(Array.isArray(data.milestones) ? data.milestones : []);
    } catch (err) {
      setError(err?.message || "Failed to load milestones");
    } finally {
      if (!quiet) setLoading(false);
    }
  }

  async function post(endpoint, payload, message = "") {
    setSaving(true);
    setError("");
    setStatus("");
    try {
      const data = await parseJsonResponse(await fetch(`${API_BASE}/${endpoint}`, {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(payload),
      }));
      if (!data?.ok) throw new Error(data?.error || "Request failed");
      if (message) setStatus(message);
      await loadAll({ quiet: true });
      return data;
    } catch (err) {
      setError(err?.message || "Request failed");
      return null;
    } finally {
      setSaving(false);
    }
  }

  async function loadDeleted() {
    try {
      const [categoryData, milestoneData] = await Promise.all([
        parseJsonResponse(await fetch(`${API_BASE}/deleted-categories.php?_=${Date.now()}`, { credentials: "include" })),
        parseJsonResponse(await fetch(`${API_BASE}/deleted-milestones.php?_=${Date.now()}`, { credentials: "include" })),
      ]);
      setDeletedCategories(categoryData?.ok && Array.isArray(categoryData.items) ? categoryData.items : []);
      setDeletedMilestones(milestoneData?.ok && Array.isArray(milestoneData.items) ? milestoneData.items : []);
    } catch (err) {
      setError(err?.message || "Failed to load deleted items");
    }
  }

  function visibleMilestones(category) {
    const items = milestonesByCategory.get(category.id) || [];
    if (showAll[category.id]) return items;
    const open = items.filter((item) => !item.achieved_at).slice(0, category.preview_count || 3);
    const recent = items.filter((item) => recentlyChecked[item.id]);
    const ids = new Set([...open, ...recent].map((item) => item.id));
    return items.filter((item) => ids.has(item.id));
  }

  function openAddMilestone(categoryId) {
    setMilestoneForm({ ...emptyMilestoneForm, category_id: categoryId || categories[0]?.id || "" });
    setMilestoneModalOpen(true);
  }

  function openEditMilestone(milestone) {
    setMilestoneForm({
      id: milestone.id,
      category_id: milestone.category_id,
      title: milestone.title || "",
      notes: milestone.notes || "",
      position_mode: "end",
      position_ref_id: "",
    });
    setMilestoneModalOpen(true);
  }

  function openAddCategory() {
    setCategoryForm(emptyCategoryForm);
  }

  function openEditCategory(category) {
    setCategoryForm({
      id: category.id,
      name: category.name || "",
      preview_count: category.preview_count || 3,
      is_archived: !!category.is_archived,
    });
  }

  async function saveMilestone() {
    const payload = {
      ...milestoneForm,
      category_id: Number(milestoneForm.category_id),
      position_ref_id: milestoneForm.position_ref_id ? Number(milestoneForm.position_ref_id) : null,
    };
    if (!payload.title.trim()) {
      setError("Milestone title is required.");
      return;
    }
    const data = await post("save-milestone.php", payload, "Milestone saved.");
    if (data?.ok) {
      setMilestoneModalOpen(false);
      setMilestoneForm(emptyMilestoneForm);
    }
  }

  async function saveCategory() {
    const payload = { ...categoryForm, preview_count: Number(categoryForm.preview_count) || 3 };
    if (!payload.name.trim()) {
      setError("Category name is required.");
      return;
    }
    const data = await post("save-category.php", payload, "Category saved.");
    if (data?.ok) openAddCategory();
  }

  async function toggleAchieved(milestone, checked) {
    if (!checked && !window.confirm("Mark this milestone as not achieved?\n\nIts achievement date will be removed.")) return;
    if (checked) setRecentlyChecked((prev) => ({ ...prev, [milestone.id]: Date.now() }));
    await post("toggle-achieved.php", { id: milestone.id, achieved: checked });
  }

  async function removeMilestone(milestone) {
    if (!window.confirm("Remove this milestone?")) return;
    await post("delete-milestone.php", { id: milestone.id }, "Milestone removed.");
    await loadDeleted();
  }

  async function removeCategory(category) {
    if (!window.confirm(`Remove ${category.name} and its milestones?`)) return;
    await post("delete-category.php", { id: category.id }, "Category removed.");
    await loadDeleted();
  }

  function renderMilestoneTable(items, category = null) {
    return (
      <div className="admin-milestones__table-wrap">
        <table className="admin-milestones__table">
          <thead>
            <tr>
              <th>Done</th>
              <th className="admin-milestones__number">#</th>
              {!category && <th>Category</th>}
              <th>Milestone</th>
              <th>Achieved</th>
              <th>Notes</th>
              <th>Controls</th>
            </tr>
          </thead>
          <tbody>
            {items.length === 0 ? (
              <tr><td colSpan={category ? 6 : 7} className="admin-milestones__empty">No milestones to show.</td></tr>
            ) : items.map((milestone, index) => {
              const categoryItems = milestonesByCategory.get(milestone.category_id) || [];
              const isFirst = categoryItems[0]?.id === milestone.id;
              const isLast = categoryItems[categoryItems.length - 1]?.id === milestone.id;
              const displayCategory = category || categories.find((item) => item.id === milestone.category_id);
              return (
                <tr key={milestone.id} className={recentlyChecked[milestone.id] ? "admin-milestones__row--pulse" : ""}>
                  <td>
                    <label className="admin-milestones__check">
                      <input
                        type="checkbox"
                        checked={!!milestone.achieved_at}
                        onChange={(event) => toggleAchieved(milestone, event.target.checked)}
                        aria-label={`Mark ${milestone.title} achieved`}
                      />
                      <span><Check size={14} /></span>
                    </label>
                  </td>
                  <td className="admin-milestones__number">{category ? milestone.sort_order : index + 1}</td>
                  {!category && <td>{displayCategory?.name || ""}</td>}
                  <td className="admin-milestones__title">{milestone.title}</td>
                  <td>{formatDate(milestone.achieved_at)}</td>
                  <td className="admin-milestones__notes">{notesPreview(milestone.notes)}</td>
                  <td>
                    <div className="admin-milestones__controls">
                      <button type="button" title="Edit" onClick={() => openEditMilestone(milestone)}><Edit2 size={15} /></button>
                      <button type="button" title="Move up" disabled={isFirst || saving} onClick={() => post("move-milestone.php", { id: milestone.id, direction: "up" })}><ArrowUp size={15} /></button>
                      <button type="button" title="Move down" disabled={isLast || saving} onClick={() => post("move-milestone.php", { id: milestone.id, direction: "down" })}><ArrowDown size={15} /></button>
                      <button type="button" title="Remove" onClick={() => removeMilestone(milestone)}><X size={15} /></button>
                    </div>
                  </td>
                </tr>
              );
            })}
          </tbody>
        </table>
      </div>
    );
  }

  return (
    <div className={embedded ? "admin-milestones admin-milestones--embedded" : "admin-milestones"}>
      <header className="admin-milestones__header">
        {!embedded && (
          <div>
            <h1>Milestones</h1>
          </div>
        )}
        <button type="button" className="admin-milestones__btn" onClick={() => { setCategoryModalOpen(true); loadDeleted(); openAddCategory(); }}>
          Manage Categories
        </button>
      </header>

      <div className="admin-milestones__tabs" role="tablist" aria-label="Milestone views">
        <button type="button" className={activeView === "category" ? "is-active" : ""} onClick={() => setActiveView("category")}>By Category</button>
        <button type="button" className={activeView === "next" ? "is-active" : ""} onClick={() => setActiveView("next")}>Next Milestones</button>
        <button type="button" className={activeView === "history" ? "is-active" : ""} onClick={() => setActiveView("history")}>Achievement History</button>
      </div>

      {error && <div className="admin-milestones__error">{error}</div>}
      {status && <div className="admin-milestones__status">{status}</div>}
      {loading ? <div className="admin-milestones__loading">Loading...</div> : null}

      {!loading && activeView === "category" && (
        <section className="admin-milestones__category-list">
          {categories.length === 0 ? (
            <div className="admin-milestones__empty">No milestone categories yet.</div>
          ) : categories.map((category) => {
            const isExpanded = !!expanded[category.id];
            const items = milestonesByCategory.get(category.id) || [];
            const visible = visibleMilestones(category);
            return (
              <div className="admin-milestones__category-block" key={category.id}>
                <button
                  type="button"
                  className="admin-milestones__category-row"
                  onClick={() => setExpanded((prev) => ({ ...prev, [category.id]: !prev[category.id] }))}
                >
                  {isExpanded ? <ChevronDown size={18} /> : <ChevronRight size={18} />}
                  <span className="admin-milestones__category-name">{category.name}</span>
                  <span>{category.achieved_count} achieved · {category.active_count} total</span>
                </button>
                {isExpanded && (
                  <div className="admin-milestones__category-body">
                    {renderMilestoneTable(visible, category)}
                    <div className="admin-milestones__preview-actions">
                      <button type="button" onClick={() => openAddMilestone(category.id)}><Plus size={15} /> Add milestone</button>
                      {showAll[category.id] ? (
                        <button type="button" onClick={() => setShowAll((prev) => ({ ...prev, [category.id]: false }))}>
                          Show next {category.preview_count || 3} only
                        </button>
                      ) : (
                        <button type="button" onClick={() => setShowAll((prev) => ({ ...prev, [category.id]: true }))}>
                          Show all {items.length} {category.name} milestones
                        </button>
                      )}
                    </div>
                  </div>
                )}
              </div>
            );
          })}
        </section>
      )}

      {!loading && activeView === "next" && renderMilestoneTable(nextMilestones)}
      {!loading && activeView === "history" && renderMilestoneTable(achievedHistory)}

      <MilestoneEditor
        open={milestoneModalOpen}
        form={milestoneForm}
        categories={categories}
        milestones={milestones}
        saving={saving}
        onClose={() => setMilestoneModalOpen(false)}
        onChange={setMilestoneForm}
        onSave={saveMilestone}
      />

      <ModalDialog open={categoryModalOpen} title="Manage Categories" onClose={() => setCategoryModalOpen(false)} width="780px">
        <div className="admin-milestones__manager">
          <div className="admin-milestones__manager-form">
            <label>
              <span>Name</span>
              <input value={categoryForm.name} onChange={(e) => setCategoryForm((prev) => ({ ...prev, name: e.target.value }))} />
            </label>
            <label>
              <span>Preview count</span>
              <input type="number" min="1" max="20" value={categoryForm.preview_count} onChange={(e) => setCategoryForm((prev) => ({ ...prev, preview_count: e.target.value }))} />
            </label>
            <label className="admin-milestones__inline">
              <input type="checkbox" checked={!!categoryForm.is_archived} onChange={(e) => setCategoryForm((prev) => ({ ...prev, is_archived: e.target.checked }))} />
              Archived
            </label>
            <button type="button" className="admin-milestones__btn admin-milestones__btn--primary" onClick={saveCategory} disabled={saving}>Save</button>
            <button type="button" className="admin-milestones__btn" onClick={openAddCategory}>New</button>
          </div>
          <div className="admin-milestones__manager-table">
            {categories.map((category, index) => (
              <div className="admin-milestones__manager-row" key={category.id}>
                <span>{category.name}</span>
                <span>{category.preview_count} preview</span>
                <button type="button" title="Edit" onClick={() => openEditCategory(category)}><Edit2 size={15} /></button>
                <button type="button" title="Move up" disabled={index === 0 || saving} onClick={() => post("move-category.php", { id: category.id, direction: "up" })}><ArrowUp size={15} /></button>
                <button type="button" title="Move down" disabled={index === categories.length - 1 || saving} onClick={() => post("move-category.php", { id: category.id, direction: "down" })}><ArrowDown size={15} /></button>
                <button type="button" title="Remove" onClick={() => removeCategory(category)}><Trash2 size={15} /></button>
              </div>
            ))}
          </div>
          <div className="admin-milestones__restore">
            <h2>Restore</h2>
            {[...deletedCategories.map((item) => ({ ...item, type: "category" })), ...deletedMilestones.map((item) => ({ ...item, type: "milestone" }))].map((item) => (
              <div className="admin-milestones__manager-row" key={`${item.type}-${item.id}`}>
                <span>{item.type === "category" ? item.name : `${item.title} (${item.category_name})`}</span>
                <span>{formatDate(item.deleted_at)}</span>
                <button
                  type="button"
                  title="Restore"
                  onClick={async () => {
                    await post(item.type === "category" ? "restore-category.php" : "restore-milestone.php", { id: item.id }, "Restored.");
                    await loadDeleted();
                  }}
                >
                  <RotateCcw size={15} />
                </button>
              </div>
            ))}
          </div>
        </div>
      </ModalDialog>
    </div>
  );
}

function MilestoneEditor({ open, form, categories, milestones, saving, onClose, onChange, onSave }) {
  const categoryMilestones = milestones.filter((item) => Number(item.category_id) === Number(form.category_id) && item.id !== form.id);
  const needsRef = form.position_mode === "before" || form.position_mode === "after";
  return (
    <ModalDialog open={open} title={form.id ? "Edit Milestone" : "Add Milestone"} onClose={onClose} width="680px">
      <div className="admin-milestones__form">
        <label>
          <span>Title</span>
          <input value={form.title} onChange={(e) => onChange((prev) => ({ ...prev, title: e.target.value }))} autoFocus />
        </label>
        <label>
          <span>Category</span>
          <select value={form.category_id} onChange={(e) => onChange((prev) => ({ ...prev, category_id: e.target.value, position_ref_id: "" }))}>
            <option value="">Choose category...</option>
            {categories.map((category) => <option key={category.id} value={category.id}>{category.name}</option>)}
          </select>
        </label>
        <label>
          <span>Notes</span>
          <textarea rows={5} value={form.notes} onChange={(e) => onChange((prev) => ({ ...prev, notes: e.target.value }))} />
        </label>
        <label>
          <span>Position</span>
          <select value={form.position_mode} onChange={(e) => onChange((prev) => ({ ...prev, position_mode: e.target.value, position_ref_id: "" }))}>
            <option value="beginning">At beginning</option>
            <option value="before">Before an existing milestone</option>
            <option value="after">After an existing milestone</option>
            <option value="end">At end</option>
          </select>
        </label>
        {needsRef && (
          <label>
            <span>Existing milestone</span>
            <select value={form.position_ref_id} onChange={(e) => onChange((prev) => ({ ...prev, position_ref_id: e.target.value }))}>
              <option value="">Choose milestone...</option>
              {categoryMilestones.map((milestone) => <option key={milestone.id} value={milestone.id}>{milestone.sort_order}. {milestone.title}</option>)}
            </select>
          </label>
        )}
        <div className="admin-milestones__modal-actions">
          <button type="button" className="admin-milestones__btn" onClick={onClose}>Cancel</button>
          <button type="button" className="admin-milestones__btn admin-milestones__btn--primary" onClick={onSave} disabled={saving}>Save</button>
        </div>
      </div>
    </ModalDialog>
  );
}

import { useEffect, useMemo, useState } from "react";
import { useNavigate } from "react-router-dom";
import { API_FOLDER } from "@helpers/config";
import "./supercats.css";

const SCOPE_OPTIONS = [
  { value: "exact", label: "Exact" },
  { value: "min", label: "Min or above" },
  { value: "max", label: "Max or below" },
  { value: "between", label: "Between" },
];

function slugify(str = "") {
  return str
    .toLowerCase()
    .trim()
    .replace(/[^a-z0-9]+/g, "-")
    .replace(/^-+|-+$/g, "") || `supercat-${Date.now()}`;
}

export default function AdminSupercatsPage() {
  const navigate = useNavigate();
  const [supercats, setSupercats] = useState([]);
  const [categories, setCategories] = useState({ neutral: [], hue: [], lightness: [], chroma: [] });
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [status, setStatus] = useState("");
  const [form, setForm] = useState({ name: "", notes: "" });
  const [saving, setSaving] = useState(false);
  const [recalcLoading, setRecalcLoading] = useState(false);

  useEffect(() => { loadData(); }, []);

  async function loadData() {
    setLoading(true);
    setError("");
    try {
      const res = await fetch(`${API_FOLDER}/v2/admin/supercats.php`, { credentials: "include" });
      const data = await res.json();
      if (!data.ok) throw new Error(data.error || "Failed to load supercats");
      setSupercats(data.supercats || []);
      setCategories(data.categories || { neutral: [], hue: [], lightness: [], chroma: [] });
    } catch (err) {
      setError(err?.message || "Failed to load");
    } finally {
      setLoading(false);
    }
  }

  async function apiPost(body) {
    const res = await fetch(`${API_FOLDER}/v2/admin/supercats.php`, {
      method: "POST",
      credentials: "include",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(body),
    });
    const data = await res.json();
    if (!data.ok) throw new Error(data.error || "Request failed");
    return data;
  }

  async function handleCreate(e) {
    e.preventDefault();
    if (!form.name.trim()) {
      setError("Name is required");
      return;
    }
    setSaving(true);
    try {
      const createRes = await apiPost({
        action: "create_supercat",
        display_name: form.name.trim(),
        slug: slugify(form.name),
        notes: form.notes.trim(),
      });
      try {
        await apiPost({ action: "add_clause", supercat_id: createRes.id });
      } catch {
        /* noop */
      }
      setForm({ name: "", notes: "" });
      await loadData();
      setStatus("Supercat created");
    } catch (err) {
      setError(err?.message || "Create failed");
    } finally {
      setSaving(false);
    }
  }

  async function handleUpdate(sc) {
    try {
      await apiPost({
        action: "update_supercat",
        id: sc.id,
        display_name: sc.display_name,
        slug: sc.slug,
        notes: sc.notes || "",
        is_active: sc.is_active ? 1 : 0,
      });
      setStatus(`Saved ${sc.display_name}`);
    } catch (err) {
      setError(err?.message || "Update failed");
    }
  }

  async function handleDelete(id) {
    if (!window.confirm("Delete this supercat?")) return;
    try {
      await apiPost({ action: "delete_supercat", id });
      await loadData();
      setStatus("Deleted");
    } catch (err) {
      setError(err?.message || "Delete failed");
    }
  }

  function updateSupercatLocal(supercatId, changes) {
    setSupercats((prev) =>
      prev.map((sc) => (sc.id === supercatId ? { ...sc, ...changes } : sc))
    );
  }

  function updateClauseLocal(supercatId, clauseId, changes) {
    setSupercats((prev) =>
      prev.map((sc) =>
        sc.id === supercatId
          ? { ...sc, clauses: sc.clauses.map((cl) => (cl.id === clauseId ? { ...cl, ...changes } : cl)) }
          : sc
      )
    );
  }

  function openTest(sc) {
    if (!sc?.slug) {
      setStatus("Save this supercat before running Test.");
      return;
    }
    navigate(`/adv-results?supercat=${encodeURIComponent(sc.slug)}`);
  }

  async function handleClauseChange(supercatId, clauseId, changes) {
    const supercat = supercats.find((sc) => sc.id === supercatId);
    const clause = supercat?.clauses?.find((cl) => cl.id === clauseId) || {};
    const nextClause = { ...clause, ...changes };

    updateClauseLocal(supercatId, clauseId, changes);
    try {
      await apiPost({
        action: "update_clause",
        id: clauseId,
        neutral_name: nextClause.neutral_name ?? null,
        hue_name: nextClause.hue_name ?? null,
        hue_scope: nextClause.hue_scope ?? "exact",
        hue_min_name: nextClause.hue_min_name ?? null,
        hue_max_name: nextClause.hue_max_name ?? null,
        light_name: nextClause.light_name ?? null,
        light_scope: nextClause.light_scope ?? "exact",
        light_min_name: nextClause.light_min_name ?? null,
        light_max_name: nextClause.light_max_name ?? null,
        chroma_name: nextClause.chroma_name ?? null,
        chroma_scope: nextClause.chroma_scope ?? "exact",
        chroma_min_name: nextClause.chroma_min_name ?? null,
        chroma_max_name: nextClause.chroma_max_name ?? null,
        notes: nextClause.notes ?? null,
      });
      setStatus("Clause saved");
    } catch (err) {
      setError(err?.message || "Clause update failed");
    }
  }

  async function handleClauseAdd(supercatId) {
    try {
      const data = await apiPost({ action: "add_clause", supercat_id: supercatId });
      setSupercats((prev) =>
        prev.map((sc) =>
          sc.id === supercatId
            ? {
                ...sc,
                clauses: [
                  ...sc.clauses,
                  {
                    id: data.id,
                    neutral_name: "",
                    hue_name: "",
                    hue_scope: "exact",
                    hue_min_name: "",
                    hue_max_name: "",
                    light_name: "",
                    light_scope: "exact",
                    light_min_name: "",
                    light_max_name: "",
                    chroma_name: "",
                    chroma_scope: "exact",
                    chroma_min_name: "",
                    chroma_max_name: "",
                    notes: "",
                  },
                ],
              }
            : sc
        )
      );
    } catch (err) {
      setError(err?.message || "Unable to add clause");
    }
  }

  async function handleClauseDelete(clauseId) {
    if (!window.confirm("Remove this clause?")) return;
    try {
      await apiPost({ action: "delete_clause", id: clauseId });
      await loadData();
    } catch (err) {
      setError(err?.message || "Delete failed");
    }
  }

  async function handleRecalc() {
    setRecalcLoading(true);
    setStatus("");
    setError("");
    try {
      const res = await fetch(`${API_FOLDER}/tools/recalc-supercats.php`, { credentials: "include" });
      const data = await res.json();
      if (!data.ok) throw new Error(data.error || "Recalc failed");
      setStatus(`Rebuilt assignments (${data.supercats_processed} supercats)`);
    } catch (err) {
      setError(err?.message || "Recalc failed");
    } finally {
      setRecalcLoading(false);
    }
  }

  const sortedSupercats = useMemo(
    () => supercats.slice().sort((a, b) => a.display_name.localeCompare(b.display_name)),
    [supercats]
  );

  const tableRows = useMemo(() => {
    const rows = [];
    sortedSupercats.forEach((sc) => {
      const clauses = sc.clauses && sc.clauses.length ? sc.clauses : [null];
      clauses.forEach((clause, idx) => {
        rows.push({
          supercat: sc,
          clause,
          isFirst: idx === 0,
          span: clauses.length,
        });
      });
    });
    return rows;
  }, [sortedSupercats]);

  return (
    <div className="supercats-page">
      <div className="supercats-header">
        <h1>Super Categories</h1>
        <div className="header-actions">
          <button type="button" className="btn btn-compact" onClick={handleRecalc} disabled={recalcLoading}>
            {recalcLoading ? "Rebuilding…" : "Rebuild Assignments"}
          </button>
          <form className="inline-form" onSubmit={handleCreate}>
            <input
              placeholder="New supercat name"
              value={form.name}
              onChange={(e) => setForm((prev) => ({ ...prev, name: e.target.value }))}
              required
            />
            <input
              placeholder="Notes"
              value={form.notes}
              onChange={(e) => setForm((prev) => ({ ...prev, notes: e.target.value }))}
            />
            <button className="btn primary" type="submit" disabled={saving}>
              {saving ? "Saving…" : "Add"}
            </button>
          </form>
        </div>
      </div>

      {error && <div className="supercats-error">{error}</div>}
      {status && <div className="supercats-status">{status}</div>}
      {loading && <div className="supercats-status">Loading…</div>}

      <div className="table-wrap">
        <table className="summary-table supercats-table">
          <thead>
            <tr>
              <th>Name</th>
              <th>Active</th>
              <th>Notes</th>
              <th>Neutral</th>
              <th>Hue</th>
              <th>Lightness</th>
              <th>Chroma</th>
              <th>Clause</th>
              <th>Supercat Actions</th>
            </tr>
          </thead>
          <tbody>
            {tableRows.map((row, idx) => {
              const sc = row.supercat;
              const clause = row.clause;
              return (
                <tr key={`${sc.id}-${clause ? clause.id : `blank-${idx}`}`}>
                  {row.isFirst && (
                    <>
                      <td rowSpan={row.span}>
                        <input
                          className="sc-name"
                          value={sc.display_name}
                          onChange={(e) => updateSupercatLocal(sc.id, { display_name: e.target.value })}
                        />
                      </td>
                      <td rowSpan={row.span} className="center-cell">
                        <input
                          type="checkbox"
                          checked={!!sc.is_active}
                          onChange={(e) => updateSupercatLocal(sc.id, { is_active: e.target.checked ? 1 : 0 })}
                        />
                      </td>
                      <td rowSpan={row.span}>
                        <input
                          value={sc.notes || ""}
                          onChange={(e) => updateSupercatLocal(sc.id, { notes: e.target.value })}
                        />
                      </td>
                    </>
                  )}
                  <td>
                    {clause ? (
                      <SelectField
                        value={clause.neutral_name}
                        options={categories.neutral}
                        onChange={(val) => handleClauseChange(sc.id, clause.id, { neutral_name: val })}
                      />
                    ) : (
                      <span className="muted">No clauses yet</span>
                    )}
                  </td>
                  <td>
                    {clause ? (
                      <ScopeField
                        clause={clause}
                        scopeKey="hue_scope"
                        valueKey="hue_name"
                        minKey="hue_min_name"
                        maxKey="hue_max_name"
                        options={categories.hue}
                        onChange={(changes) => handleClauseChange(sc.id, clause.id, changes)}
                      />
                    ) : (
                      "—"
                    )}
                  </td>
                  <td>
                    {clause ? (
                      <ScopeField
                        clause={clause}
                        scopeKey="light_scope"
                        valueKey="light_name"
                        minKey="light_min_name"
                        maxKey="light_max_name"
                        options={categories.lightness}
                        onChange={(changes) => handleClauseChange(sc.id, clause.id, changes)}
                      />
                    ) : (
                      "—"
                    )}
                  </td>
                  <td>
                    {clause ? (
                      <ScopeField
                        clause={clause}
                        scopeKey="chroma_scope"
                        valueKey="chroma_name"
                        minKey="chroma_min_name"
                        maxKey="chroma_max_name"
                        options={categories.chroma}
                        onChange={(changes) => handleClauseChange(sc.id, clause.id, changes)}
                      />
                    ) : (
                      "—"
                    )}
                  </td>
                  <td className="center-cell">
                    {clause ? (
                      <button className="btn btn-compact danger" type="button" onClick={() => handleClauseDelete(clause.id)}>
                        Remove
                      </button>
                    ) : (
                      <button className="btn btn-compact" type="button" onClick={() => handleClauseAdd(sc.id)}>
                        + Clause
                      </button>
                    )}
                  </td>
                  {row.isFirst && (
                    <td rowSpan={row.span} className="actions-cell">
                      <button className="btn btn-compact" type="button" onClick={() => handleUpdate(sc)}>
                        Save
                      </button>
                      <button className="btn btn-compact" type="button" onClick={() => handleClauseAdd(sc.id)}>
                        + Clause
                      </button>
                      <button className="btn btn-compact" type="button" onClick={() => openTest(sc)}>
                        Test
                      </button>
                      <button className="btn btn-compact danger" type="button" onClick={() => handleDelete(sc.id)}>
                        Delete
                      </button>
                    </td>
                  )}
                </tr>
              );
            })}
          </tbody>
        </table>
      </div>
    </div>
  );
}

function SelectField({ value, options, onChange }) {
  return (
    <select value={value ?? ""} onChange={(e) => onChange(e.target.value || null)}>
      <option value="">(any)</option>
      {options.map((opt) => (
        <option key={opt.name} value={opt.name}>{opt.name}</option>
      ))}
    </select>
  );
}

function ScopeField({ clause, scopeKey, valueKey, minKey, maxKey, options, onChange }) {
  const scope = clause?.[scopeKey] || "exact";

  const renderValueSelect = (key, placeholder) => (
    <SelectField
      value={clause?.[key] || ""}
      options={options}
      onChange={(val) => onChange({ [key]: val })}
    />
  );

  return (
    <div className="axis-cell">
      <select
        className="axis-scope"
        value={scope}
        onChange={(e) => onChange({ [scopeKey]: e.target.value || "exact" })}
      >
        {SCOPE_OPTIONS.map((opt) => (
          <option key={opt.value} value={opt.value}>{opt.label}</option>
        ))}
      </select>

      {scope === "exact" && renderValueSelect(valueKey, "Exact value")}
      {scope === "min" && renderValueSelect(minKey, "Min value")}
      {scope === "max" && renderValueSelect(maxKey, "Max value")}
      {scope === "between" && (
        <div className="axis-range">
          {renderValueSelect(minKey, "Min value")}
          {renderValueSelect(maxKey, "Max value")}
        </div>
      )}
    </div>
  );
}

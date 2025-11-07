import { useEffect, useState, forwardRef, useImperativeHandle } from "react";
import fetchAllCategories from "@data/fetchAllCategories";
import { useAppState } from "@context/AppStateContext";
import { API_FOLDER } from "@helpers/config";
import "./catlist.css";

// helper: coerce numbers/booleans; '' -> null for numbers
function normalizeCategoryPayload(row) {
  const numeric = [
    "hue_min","hue_max","chroma_min","chroma_max","light_min","light_max",
    "lrv_min","lrv_max"
  ];
  const bools = ["active","locked","calc_only"];
  const out = { ...row };
  numeric.forEach(k => { const v = out[k]; out[k] = v === "" || v == null ? null : Number(v); });
  bools.forEach(k => { out[k] = Boolean(out[k]); });
  return out;
}

const CategoryList = forwardRef(function CategoryList(_props, ref) {
  const [categories, setCategories] = useState([]);
  const { selectedCategory, setSelectedCategory } = useAppState();
  const [edited, setEdited] = useState({});
  const [sortField, setSortField] = useState("id");
  const [sortDir, setSortDir] = useState("asc");

  useImperativeHandle(ref, () => ({
    addBlankRow() {
      const tempId = `tmp-${Date.now()}`;
      const emptyRow = {
        id: tempId, name: "", type: "hue", notes: "",
        hue_min: "", hue_max: "", chroma_min: "", chroma_max: "",
        light_min: "", light_max: "",
        active: false, locked: false, calc_only: false,
        lrv_min: "", lrv_max: ""
      };
      setCategories(prev => [...prev, emptyRow]);
    }
  }));

  useEffect(() => {
    fetchAllCategories().then(setCategories).catch(console.error);
  }, []);

  const headers = [
    { key: "id", label: "ID" },
    { key: "name", label: "Name" },
    { key: "type", label: "Type" },
    { key: "notes", label: "Notes" },
    { key: "hue_min", label: "Hue Min" },
    { key: "hue_max", label: "Hue Max" },
    { key: "chroma_min", label: "Chr Min" },
    { key: "chroma_max", label: "Chr Max" },
    { key: "light_min", label: "Light Min" },
    { key: "light_max", label: "Light Max" },
    { key: "active", label: "Active" },
    { key: "locked", label: "Locked" },
    { key: "calc_only", label: "Calc Only" },
    { key: "actions", label: "Actions" },
    { key: "lrv_min", label: "LRV Min" },
    { key: "lrv_max", label: "LRV Max" }
  ];

  const handleSort = (key) => {
    if (key === "actions") return; // not sortable
    if (key === sortField) setSortDir(d => (d === "asc" ? "desc" : "asc"));
    else { setSortField(key); setSortDir("asc"); }
  };

  const sortedCategories = [...categories].sort((a, b) => {
    const av = a[sortField]; const bv = b[sortField];
    if (av == null && bv == null) return 0;
    if (av == null) return 1;
    if (bv == null) return -1;
    if (typeof av === "number" && typeof bv === "number")
      return sortDir === "asc" ? av - bv : bv - av;
    return sortDir === "asc"
      ? String(av).localeCompare(String(bv))
      : String(bv).localeCompare(String(av));
  });

  const getValue = (cat, id, field) => edited[id]?.[field] ?? (cat[field] ?? "");

  const handleChange = (id, field, value) => {
    setEdited(prev => ({ ...prev, [id]: { ...prev[id], [field]: value } }));
  };

  const handleSave = async (id) => {
    const updated = edited[id];
    if (!updated) return;
    const isNew = typeof id === "string" && id.startsWith("tmp-");
    const current = categories.find(c => c.id === id) || {};
    const payload = normalizeCategoryPayload({ ...current, ...updated, id: isNew ? null : id });

    try {
      const res = await fetch(`${API_FOLDER}/save-category.php`, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(payload)
      });
      const result = await res.json();
      if (!result.success) return alert("Save failed.");
      setCategories(result.categories);
      setEdited(prev => { const c = { ...prev }; delete c[id]; return c; });
    } catch (e) {
      console.error(e); alert("Save error. See console.");
    }
  };

  const handleDelete = (id) => {
    if (!window.confirm("Are you sure you want to delete this category?")) return;
    const isNew = typeof id === "string" && id.startsWith("tmp-");
    if (isNew) {
      setCategories(prev => prev.filter(c => c.id !== id));
      setEdited(prev => { const c = { ...prev }; delete c[id]; return c; });
      return;
    }
    fetch(`${API_FOLDER}/delete-category.php?id=${encodeURIComponent(id)}`, { method: "DELETE" })
      .then(r => r.json())
      .then(result => {
        if (!result.success) return alert(`Delete failed: ${result.error || "Unknown error"}`);
        setCategories(prev => prev.filter(c => c.id !== id));
        setEdited(prev => { const c = { ...prev }; delete c[id]; return c; });
      })
      .catch(e => { console.error(e); alert("Delete error. See console."); });
  };

  return (
    <div className="cat-admin">
      <div className="table-wrap">
        <table className="cat-table">
          {/* ONE place to control widths */}
          <colgroup>
            <col className="col-id" />
            <col className="col-name" />
            <col className="col-type" />
            <col className="col-notes" />
            {/* 6 numeric columns */}
            <col className="col-num" span="6" />
            {/* Active, Locked, Calc Only */}
            <col className="col-flag" span="3" />
            {/* Actions */}
            <col className="col-actions" />
            {/* LRV at the end (2 columns) */}
            <col className="col-lrv" span="2" />
          </colgroup>

          <thead>
            <tr>
              {headers.map(({ key, label }) => {
                const isSorted = key === sortField;
                const arrow = isSorted ? (sortDir === "asc" ? " ▲" : " ▼") : "";
                return (
                  <th
                    key={key}
                    onClick={() => handleSort(key)}
                    className={key === "actions" ? "is-actions" : ""}
                    title={key === "actions" ? "" : "Sort"}
                  >
                    {label}{arrow}
                  </th>
                );
              })}
            </tr>
          </thead>

          <tbody>
            {sortedCategories.map(cat => (
              <tr
                key={cat.id}
                className={selectedCategory?.id === cat.id ? "is-selected" : ""}
                onClick={() => setSelectedCategory(cat)}
              >
                <td className="td-id">{cat.id}</td>

                <td><input
                  type="text"
                  value={getValue(cat, cat.id, "name")}
                  onChange={(e) => handleChange(cat.id, "name", e.target.value)}
                /></td>

                <td><input
                  type="text"
                  value={getValue(cat, cat.id, "type")}
                  onChange={(e) => handleChange(cat.id, "type", e.target.value)}
                /></td>

                <td><input
                  type="text"
                  value={getValue(cat, cat.id, "notes")}
                  onChange={(e) => handleChange(cat.id, "notes", e.target.value)}
                  title={getValue(cat, cat.id, "notes")}
                  maxLength={100}
                  placeholder="note"
                /></td>

                {/* numeric block */}
                <td><input type="number" value={getValue(cat, cat.id, "hue_min")}    onChange={(e)=>handleChange(cat.id,"hue_min",e.target.value)} /></td>
                <td><input type="number" value={getValue(cat, cat.id, "hue_max")}    onChange={(e)=>handleChange(cat.id,"hue_max",e.target.value)} /></td>
                <td><input type="number" value={getValue(cat, cat.id, "chroma_min")} onChange={(e)=>handleChange(cat.id,"chroma_min",e.target.value)} /></td>
                <td><input type="number" value={getValue(cat, cat.id, "chroma_max")} onChange={(e)=>handleChange(cat.id,"chroma_max",e.target.value)} /></td>
                <td><input type="number" value={getValue(cat, cat.id, "light_min")}  onChange={(e)=>handleChange(cat.id,"light_min",e.target.value)} /></td>
                <td><input type="number" value={getValue(cat, cat.id, "light_max")}  onChange={(e)=>handleChange(cat.id,"light_max",e.target.value)} /></td>

                {/* flags */}
                <td className="td-flag"><input type="checkbox"
                  checked={!!getValue(cat, cat.id, "active")}
                  onChange={(e) => handleChange(cat.id, "active", e.target.checked)}
                /></td>
                <td className="td-flag"><input type="checkbox"
                  checked={!!getValue(cat, cat.id, "locked")}
                  onChange={(e) => handleChange(cat.id, "locked", e.target.checked)}
                /></td>
                <td className="td-flag"><input type="checkbox"
                  checked={!!getValue(cat, cat.id, "calc_only")}
                  onChange={(e) => handleChange(cat.id, "calc_only", e.target.checked)}
                /></td>

                {/* actions */}
                <td className="td-actions">
                  {edited[cat.id] ? (
                    <button
                      type="button"
                      className="link-btn"
                      onClick={(e) => { e.stopPropagation(); handleSave(cat.id); }}
                    >
                      Save
                    </button>
                  ) : (
                    <span className="muted">—</span>
                  )}
                  <button
                    type="button"
                    className="link-btn danger"
                    onClick={(e) => { e.stopPropagation(); handleDelete(cat.id); }}
                  >
                    Delete
                  </button>
                </td>

                {/* LRV (at end) */}
                <td><input type="number" value={getValue(cat, cat.id, "lrv_min")} onChange={(e)=>handleChange(cat.id,"lrv_min",e.target.value)} /></td>
                <td><input type="number" value={getValue(cat, cat.id, "lrv_max")} onChange={(e)=>handleChange(cat.id,"lrv_max",e.target.value)} /></td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  );
});

export default CategoryList;

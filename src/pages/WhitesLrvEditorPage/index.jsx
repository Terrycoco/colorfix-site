import React, { useEffect, useMemo, useState } from "react";
import { API_FOLDER } from "@helpers/config";
import "./whites-lrv-editor.css";

/**
 * Whites LRV Editor (brand → alpha list of Whites → inline LRV edit by name)
 * Uses /api/v2/admin/whites-list.php and /api/v2/admin/whites-set-lrv.php
 */
export default function WhitesLrvEditor() {
  const [brand, setBrand] = useState("de");
  const [loading, setLoading] = useState(false);
  const [rows, setRows] = useState([]);     // [{id,name,code,hex6,lrv}]
  const [edits, setEdits] = useState({});   // name -> lrv (string)

  const fetchList = async (b) => {
    setLoading(true);
    setEdits({});
    try {
      const url = `${API_FOLDER}/v2/admin/whites-list.php?brand=${encodeURIComponent(b)}`;
      const res = await fetch(url, { cache: "no-store" });
      const data = await res.json();
      if (data?.items) setRows(data.items);
      else setRows([]);
    } catch (e) {
      console.error(e);
      setRows([]);
      alert("Failed to fetch whites list");
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => { fetchList(brand); }, [brand]);

  const onChangeLrv = (name, val) => {
    const cleaned = val.replace(/[^0-9.]/g, "");
    setEdits((prev) => ({ ...prev, [name]: cleaned }));
  };

  const pendingUpdates = useMemo(() => {
    const ups = [];
    for (const r of rows) {
      const input = edits[r.name];
      if (input === undefined || input === "") continue;
      const num = Number(input);
      if (!Number.isFinite(num)) continue;
      const cur = r.lrv === null || r.lrv === "" ? null : Number(r.lrv);
      if (cur === null || Math.abs(num - cur) > 1e-9) {
        ups.push({ name: r.name, lrv: num });
      }
    }
    return ups;
  }, [rows, edits]);

  const saveAll = async () => {
    if (pendingUpdates.length === 0) {
      alert("No changes to save.");
      return;
    }
    try {
      const url = `${API_FOLDER}/v2/admin/whites-set-lrv.php`;
      const res = await fetch(url, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ brand, updates: pendingUpdates }),
      });
      const data = await res.json();
      if (data?.error) throw new Error(data.error);

      const failed = (data?.results || []).filter((r) => r.ok === false);
      if (failed.length > 0) {
        alert(`Saved with some errors:\n${failed.map(f => `• ${f.name}: ${f.reason}`).join("\n")}`);
      } else {
        alert(`Saved ${data.updated} ${data.updated === 1 ? "row" : "rows"}.`);
      }
      fetchList(brand);
    } catch (e) {
      console.error(e);
      alert("Save failed: " + e.message);
    }
  };

  return (
    <div className="lrv-editor">
      <div className="lrv-header">
        <div className="brand-row">
          <label htmlFor="brand">Brand:</label>
          <select id="brand" value={brand} onChange={(e) => setBrand(e.target.value)}>
            <option value="de">de (Dunn-Edwards)</option>
            <option value="sw">sw (Sherwin-Williams)</option>
            <option value="bm">bm (Benjamin Moore)</option>
            <option value="behr">behr</option>
            <option value="vs">valspar</option>
            <option value="ppg">ppg</option>
            <option value="fb">fb (Farrow & Ball)</option>
              <option value="vist">vist (Vista)</option>
          </select>
          <button className="refresh" onClick={() => fetchList(brand)} disabled={loading}>
            {loading ? "Loading…" : "Refresh"}
          </button>
        </div>
        <div className="hint">
          Showing Whites for <b>{brand}</b> — alphabetical by name. Edit LRV, then “Save all changes”.
        </div>
      </div>

      <div className="table-wrap">
        <table className="lrv-table">
          <thead>
            <tr>
              <th style={{width: '36px'}}>Swatch</th>
              <th>Name</th>
              <th>Code</th>
              <th style={{width: '120px'}}>Current LRV</th>
              <th style={{width: '140px'}}>New LRV</th>
            </tr>
          </thead>
          <tbody>
            {rows.length === 0 && !loading && (
              <tr><td colSpan={5} style={{textAlign:'center', padding:'16px'}}>No whites found.</td></tr>
            )}
            {rows.map((r) => {
              const cur = r.lrv === null || r.lrv === "" ? "" : String(r.lrv);
              const val = edits[r.name] ?? "";
              return (
                <tr key={`${brand}:${r.name}`}>
                  <td><div className="swatch" style={{ backgroundColor: `#${r.hex6}` }} /></td>
                  <td>{r.name}</td>
                  <td>{r.code}</td>
                  <td className="mono">{cur}</td>
                  <td>
                    <input
                      className="lrv-input"
                      inputMode="decimal"
                      placeholder="e.g. 84.0"
                      value={val}
                      onChange={(e) => onChangeLrv(r.name, e.target.value)}
                    />
                  </td>
                </tr>
              );
            })}
          </tbody>
          <tfoot>
            <tr>
              <td colSpan={5}>
                <div className="footer-bar">
                  <div>{pendingUpdates.length} change(s) ready</div>
                  <button className="save" onClick={saveAll} disabled={pendingUpdates.length === 0 || loading}>
                    Save all changes
                  </button>
                </div>
              </td>
            </tr>
          </tfoot>
        </table>
      </div>
    </div>
  );
}

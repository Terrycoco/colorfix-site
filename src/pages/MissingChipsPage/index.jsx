// src/pages/MissingChipsPage.jsx
import React, { useEffect, useMemo, useRef, useState } from "react";
import { API_FOLDER as API } from "@helpers/config";     // <-- fixed typo
import { isAdmin } from "@helpers/authHelper";

export default function MissingChipsPage() {
  if (!isAdmin()) {
    return (
      <div style={{padding:16}}>
        <h2>Missing Chip Numbers</h2>
        <p>Admins only.</p>
      </div>
    );
  }

  const [q, setQ] = useState('');
  const [rows, setRows] = useState([]);
  const [loading, setLoading] = useState(false);
  const [missingTotal, setMissingTotal] = useState(0);
  const [limit] = useState(200);
  const [offset, setOffset] = useState(0);
  const debRef = useRef(null);

  // keep refs to inputs for focus-next
  const inputRefs = useRef([]);

  // === FETCH MISSING LIST (your endpoint name preserved) ===
  const fetchData = async (opts = {}) => {
    const { q: qParam = q, offset: off = offset } = opts;
    setLoading(true);
    try {
      const url = new URL(`${API}/missing-chip-num.php`, window.location.origin);
      url.searchParams.set('limit', String(limit));
      url.searchParams.set('offset', String(off));
      if (qParam) url.searchParams.set('q', qParam);
      url.searchParams.set('_cb', Date.now()); // cache-buster

      const res = await fetch(url.toString());
      const json = await res.json();
      if (json && json.rows) {
        setRows(json.rows);
        setMissingTotal(json.missing_total ?? 0);
        inputRefs.current = new Array(json.rows.length);
      }
    } catch (e) {
      console.error('fetch missing chips failed', e);
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => { fetchData({ q, offset: 0 }); }, []);

  const onChangeQ = (e) => {
    const v = e.target.value;
    setQ(v);
    if (debRef.current) clearTimeout(debRef.current);
    debRef.current = setTimeout(() => {
      setOffset(0);
      fetchData({ q: v, offset: 0 });
    }, 250);
  };

  // === UPDATE CALL (restored) ===
  const saveChip = async (globalIndex, id, chip, focusNext = true) => {
    const value = (chip || '').trim();
    if (value === '') {
      alert('Please enter a chip # or brochure (max 20 chars)');
      return;
    }
    try {
      // allow any text up to 20 chars; backend should accept strings
      const res = await fetch(`${API}/update-chip-num.php?_cb=${Date.now()}`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ id, chip_num: value.slice(0, 20) })
      });
      const text = await res.text();
      let json;
      try { json = JSON.parse(text); } catch (e) { throw new Error('API returned non-JSON'); }
      if (!json.ok) throw new Error(json.error || 'Unknown error');

      // Remove the row locally (it’s no longer missing)
      const next = rows.slice();
      next.splice(globalIndex, 1);
      setRows(next);
      inputRefs.current.splice(globalIndex, 1);

      // Focus next input
      if (focusNext && inputRefs.current.length) {
        const nextRef = inputRefs.current[Math.min(globalIndex, inputRefs.current.length - 1)];
        nextRef?.focus?.();
      }
    } catch (e) {
      console.error('update failed', e);
      alert('Update failed: ' + e.message);
    }
  };

  return (
    <div style={{padding: 16, display: 'grid', gap: 12}}>
      <div style={{display:'flex', gap:12, alignItems:'center', flexWrap:'wrap'}}>
        <h2 style={{margin:0}}>Missing Chip Numbers</h2>
        <span style={{opacity:.75}}>Missing: {missingTotal}</span>
      </div>

      <div style={{display:'grid', gridTemplateColumns:'1fr auto', gap:8, alignItems:'center'}}>
        <input
          value={q}
          onChange={onChangeQ}
          placeholder="Search by color name…"
          inputMode="search"
          style={{width:'100%', padding:'10px 12px', fontSize:16, border:'1px solid #ccc', borderRadius:8}}
        />
        <button
          onClick={() => { setQ(''); fetchData({ q:'', offset:0 }); }}
          style={{padding:'10px 12px', borderRadius:8, border:'1px solid #ccc'}}
        >
          Clear
        </button>
      </div>

      <div style={{overflowX:'auto', border:'1px solid #e5e5e5', borderRadius:8}}>
        <table style={{width:'100%', borderCollapse:'collapse', fontSize:14}}>
          <thead>
            <tr style={{background:'#fafafa'}}>
              <th style={{textAlign:'left', padding:10, borderBottom:'1px solid #eee'}}>Name</th>
              <th style={{textAlign:'left', padding:10, borderBottom:'1px solid #eee'}}>Brand</th>
                 <th style={{textAlign:'left', padding:10, borderBottom:'1px solid #eee'}}>Code</th>
              <th style={{textAlign:'left', padding:10, borderBottom:'1px solid #eee'}}>Chip / Brochure</th>
              <th style={{textAlign:'left', padding:10, borderBottom:'1px solid #eee'}}>Actions</th>
            </tr>
          </thead>
          <tbody>
            {rows.map((r, idx) => (
              <Row
                key={r.id}
                r={r}
                globalIndex={idx}
                inputRefs={inputRefs}
                onSave={saveChip}
              />
            ))}
            {rows.length === 0 && (
              <tr><td colSpan={4} style={{padding:14}}>{loading ? 'Loading…' : 'No rows'}</td></tr>
            )}
          </tbody>
        </table>
      </div>

      <div style={{display:'flex', gap:8}}>
        <button
          onClick={() => { const off = Math.max(0, offset - limit); setOffset(off); fetchData({ q, offset: off }); }}
          disabled={offset===0}
          style={{padding:'8px 10px', border:'1px solid #ccc', borderRadius:6}}
        >
          ◀ Prev
        </button>
        <button
          onClick={() => { const off = offset + limit; setOffset(off); fetchData({ q, offset: off }); }}
          style={{padding:'8px 10px', border:'1px solid #ccc', borderRadius:6}}
        >
          Next ▶
        </button>
      </div>
    </div>
  );
}

function Row({ r, globalIndex, inputRefs, onSave }) {
  const [chip, setChip] = useState('');
  const myRef = useRef(null);

  useEffect(() => { inputRefs.current[globalIndex] = myRef.current; }, [globalIndex]);

  const onKeyDown = (e) => {
    if (e.key === 'Enter') {
      e.preventDefault();
      onSave(globalIndex, r.id, chip, /*focusNext*/ true);
    }
  };

  return (
    <tr>
      <td style={{padding:10, borderBottom:'1px solid #f1f1f1'}}>{r.name}</td>
      <td style={{padding:10, borderBottom:'1px solid #f1f1f1'}}>{r.brand}</td>
          <td style={{padding:10, borderBottom:'1px solid #f1f1f1'}}>{r.code}</td>
      <td style={{padding:10, borderBottom:'1px solid #f1f1f1'}}>
        <input
          ref={myRef}
          value={chip}
          onChange={(e)=>setChip(e.target.value.slice(0,20))}
          onKeyDown={onKeyDown}
          placeholder="chip # or brochure (max 20)"
          style={{width:220, padding:'8px 10px', border:'1px solid #ccc', borderRadius:6}}
        />
      </td>
      <td style={{padding:10, borderBottom:'1px solid #f1f1f1'}}>
        <button
          onClick={()=>onSave(globalIndex, r.id, chip, true)}
          style={{padding:'8px 12px', border:'1px solid #ccc', borderRadius:6}}
        >
          Save
        </button>
      </td>
    </tr>
  );
}

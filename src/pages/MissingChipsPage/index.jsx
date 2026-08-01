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
  const [chipQ, setChipQ] = useState('');
  const [brand, setBrand] = useState('');
  const [missingOnly, setMissingOnly] = useState(true);
  const [brands, setBrands] = useState([]);
  const [rows, setRows] = useState([]);
  const [loading, setLoading] = useState(false);
  const [missingTotal, setMissingTotal] = useState(0);
  const [matchingTotal, setMatchingTotal] = useState(0);
  const [limit] = useState(200);
  const [offset, setOffset] = useState(0);
  const debRef = useRef(null);
  const qInputRef = useRef(null);
  const chipInputRef = useRef(null);

  // keep refs to inputs for focus-next
  const inputRefs = useRef([]);

  // === FETCH MISSING LIST (your endpoint name preserved) ===
  const fetchData = async (opts = {}) => {
    const {
      q: qParam = q,
      chip: chipParam = chipQ,
      brand: brandParam = brand,
      missingOnly: missingOnlyParam = missingOnly,
      offset: off = offset
    } = opts;
    setLoading(true);
    try {
      const url = new URL(`${API}/missing-chip-num.php`, window.location.origin);
      url.searchParams.set('limit', String(limit));
      url.searchParams.set('offset', String(off));
      if (qParam) url.searchParams.set('q', qParam);
      if (chipParam) url.searchParams.set('chip', chipParam);
      if (brandParam) url.searchParams.set('brand', brandParam);
      url.searchParams.set('missing_only', missingOnlyParam ? '1' : '0');
      url.searchParams.set('_cb', Date.now()); // cache-buster

      const res = await fetch(url.toString());
      const json = await res.json();
      if (json && json.rows) {
        setRows(json.rows);
        setMissingTotal(json.missing_total ?? 0);
        setMatchingTotal(json.matching_total ?? json.rows.length);
        setBrands(json.brands || []);
        inputRefs.current = new Array(json.rows.length);
      }
    } catch (e) {
      console.error('fetch missing chips failed', e);
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => { fetchData({ q, chip: chipQ, brand, missingOnly, offset: 0 }); }, []);

  const onChangeQ = (e) => {
    const v = e.target.value;
    setQ(v);
    if (debRef.current) clearTimeout(debRef.current);
    debRef.current = setTimeout(() => {
      setOffset(0);
      fetchData({ q: v, chip: chipQ, brand, missingOnly, offset: 0 });
    }, 250);
  };

  const runSearchNow = (next = {}) => {
    if (debRef.current) clearTimeout(debRef.current);
    const nextQ = next.q ?? qInputRef.current?.value ?? q;
    const nextChip = next.chip ?? chipInputRef.current?.value ?? chipQ;
    const nextBrand = next.brand ?? brand;
    const nextMissingOnly = next.missingOnly ?? missingOnly;
    setQ(nextQ);
    setChipQ(nextChip);
    setOffset(0);
    fetchData({
      q: nextQ,
      chip: nextChip,
      brand: nextBrand,
      missingOnly: nextChip.trim() ? false : nextMissingOnly,
      offset: 0
    });
  };

  const onSearchSubmit = (e) => {
    e.preventDefault();
    runSearchNow();
  };

  const onSearchKeyDown = (e) => {
    if (e.key !== 'Enter') return;
    e.preventDefault();
    runSearchNow();
  };

  const onChangeChipQ = (e) => {
    const v = e.target.value;
    setChipQ(v);
    if (v.trim() && missingOnly) setMissingOnly(false);
    if (debRef.current) clearTimeout(debRef.current);
    debRef.current = setTimeout(() => {
      setOffset(0);
      fetchData({ q, chip: v, brand, missingOnly: v.trim() ? false : missingOnly, offset: 0 });
    }, 250);
  };

  const onChangeBrand = (e) => {
    const v = e.target.value;
    setBrand(v);
    setOffset(0);
    fetchData({ q, chip: chipQ, brand: v, missingOnly, offset: 0 });
  };

  const onChangeMissingOnly = (e) => {
    const v = e.target.checked;
    setMissingOnly(v);
    setOffset(0);
    fetchData({ q, chip: chipQ, brand, missingOnly: v, offset: 0 });
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

      const next = rows.slice();
      if (missingOnly) {
        next.splice(globalIndex, 1);
      } else {
        next[globalIndex] = { ...next[globalIndex], chip_num: json.chip_num };
      }
      setRows(next);
      if (missingOnly) inputRefs.current.splice(globalIndex, 1);

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
        <span style={{opacity:.75}}>Showing: {matchingTotal}</span>
      </div>

      <form
        onSubmit={onSearchSubmit}
        style={{display:'grid', gridTemplateColumns:'minmax(220px, 1fr) minmax(150px, 220px) minmax(180px, 260px) auto auto', gap:8, alignItems:'center'}}
      >
        <input
          ref={qInputRef}
          value={q}
          onChange={onChangeQ}
          onKeyDown={onSearchKeyDown}
          placeholder="Search by color name or code…"
          inputMode="search"
          style={{width:'100%', padding:'10px 12px', fontSize:16, border:'1px solid #ccc', borderRadius:8}}
        />
        <input
          ref={chipInputRef}
          value={chipQ}
          onChange={onChangeChipQ}
          onKeyDown={onSearchKeyDown}
          placeholder="Find chip # duplicates…"
          inputMode="search"
          style={{width:'100%', padding:'10px 12px', fontSize:16, border:'1px solid #ccc', borderRadius:8}}
        />
        <select
          value={brand}
          onChange={onChangeBrand}
          style={{width:'100%', padding:'10px 12px', fontSize:16, border:'1px solid #ccc', borderRadius:8, background:'#fff'}}
        >
          <option value="">All brands</option>
          {brands.map((b) => (
            <option key={b.code} value={b.code}>
              {b.name || b.code} ({b.missing_count ?? 0})
            </option>
          ))}
        </select>
        <label style={{display:'inline-flex', alignItems:'center', gap:6, whiteSpace:'nowrap'}}>
          <input type="checkbox" checked={missingOnly} onChange={onChangeMissingOnly} />
          Missing only
        </label>
        <button type="submit" style={{position:'absolute', width:1, height:1, padding:0, border:0, overflow:'hidden'}}>
          Search
        </button>
        <button
          type="button"
          onClick={() => { setQ(''); setChipQ(''); setBrand(''); setMissingOnly(true); setOffset(0); fetchData({ q:'', chip:'', brand:'', missingOnly:true, offset:0 }); }}
          style={{padding:'10px 12px', borderRadius:8, border:'1px solid #ccc'}}
        >
          Clear
        </button>
      </form>

      <div style={{overflowX:'auto', border:'1px solid #e5e5e5', borderRadius:8}}>
        <table style={{width:'100%', borderCollapse:'collapse', fontSize:14}}>
          <thead>
            <tr style={{background:'#fafafa'}}>
              <th style={{textAlign:'left', padding:10, borderBottom:'1px solid #eee'}}>Name</th>
              <th style={{textAlign:'left', padding:10, borderBottom:'1px solid #eee'}}>Brand</th>
                 <th style={{textAlign:'left', padding:10, borderBottom:'1px solid #eee'}}>Code</th>
              <th style={{textAlign:'left', padding:10, borderBottom:'1px solid #eee'}}>Current Chip</th>
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
              <tr><td colSpan={6} style={{padding:14}}>{loading ? 'Loading…' : 'No rows'}</td></tr>
            )}
          </tbody>
        </table>
      </div>

      <div style={{display:'flex', gap:8}}>
        <button
          onClick={() => { const off = Math.max(0, offset - limit); setOffset(off); fetchData({ q, chip: chipQ, brand, missingOnly, offset: off }); }}
          disabled={offset===0}
          style={{padding:'8px 10px', border:'1px solid #ccc', borderRadius:6}}
        >
          ◀ Prev
        </button>
        <button
          onClick={() => { const off = offset + limit; setOffset(off); fetchData({ q, chip: chipQ, brand, missingOnly, offset: off }); }}
          disabled={offset + limit >= matchingTotal}
          style={{padding:'8px 10px', border:'1px solid #ccc', borderRadius:6}}
        >
          Next ▶
        </button>
      </div>
    </div>
  );
}

function Row({ r, globalIndex, inputRefs, onSave }) {
  const [chip, setChip] = useState(r.chip_num || '');
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
      <td style={{padding:10, borderBottom:'1px solid #f1f1f1'}}>{r.chip_num || '—'}</td>
      <td style={{padding:10, borderBottom:'1px solid #f1f1f1'}}>
        <input
          ref={myRef}
          value={chip}
          onChange={(e)=>setChip(e.target.value.toUpperCase().slice(0,20))}
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

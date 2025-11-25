// src/pages/AdminQuickLookup.jsx
import React, { useEffect, useRef, useState } from "react";
import {useNavigate} from 'react-router-dom';
import { API_FOLDER as API } from "@helpers/config";
const v1 = '/chip-num-lookup.php';
const v2 = '/v2/chip-num-lookup.php'

function formatChip(chip) {
  if (!chip) return '';
  return /^\d+$/.test(chip) ? chip : chip + ' Brochure';
}

export default function QuickFindPage() {
 
  const navigate = useNavigate();
  const [q, setQ] = useState('');
  const [brand, setBrand] = useState('de'); // default to DE; set '' to search all brands
  const [rows, setRows] = useState([]);
  const [loading, setLoading] = useState(false);
  const debRef = useRef(null);

  const fetchRows = async (qStr) => {
    if (!qStr) { setRows([]); return; }
    setLoading(true);
    try {
      const url = new URL(`${API}${v2}`, window.location.origin);
      url.searchParams.set('q', qStr);
      if (brand) url.searchParams.set('brand', brand);
      url.searchParams.set('_cb', Date.now()); // cache-buster
      const res = await fetch(url.toString());
      const text = await res.text();
      let json;
      try { json = JSON.parse(text); } catch {
        console.error('Non-JSON response:', text);
        return;
      }
      if (json.ok) setRows(json.rows || []);
    } catch (e) {
      console.error('lookup failed', e);
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => { /* no initial fetch */ }, []);

  const onChange = (e) => {
    const v = e.target.value;
    setQ(v);
    if (debRef.current) clearTimeout(debRef.current);
    debRef.current = setTimeout(() => fetchRows(v.trim()), 120);
  };

  const onCopy = async (txt) => {
    try {
      await navigator.clipboard.writeText(txt || '');
    } catch {}
  };

  const bigRow = (r, i) => {
    const chipTxt = formatChip(r.chip_num || '');
    return (
      <div
        key={`${r.name}-${i}`}
        style={{
          padding: '14px 16px',
          maxWidth: '500px',
          borderBottom: '1px solid #eee',
          display: 'grid',
          gridTemplateColumns: '1fr auto',
          alignItems: 'center',
          gap: 12
        }}
        onClick={() => navigate(`/color/${r.id}`)} // tap row to copy chip
      >
        <div>
            <div style={{ fontSize: 20, fontWeight: 700, lineHeight: 1.2 }}>{r.name}</div>

          <div style={{ fontSize: 16, opacity: 0.7, marginTop: 4 }}>{r.code} • {r.brand}</div>
        </div>
        <div style={{ textAlign: 'right' }}>
          <div style={{ fontSize: 20, fontWeight: 800 }}>{chipTxt || '—'}</div>
         
        </div>
      </div>
    );
  };

  return (
    <div style={{ padding: 12, maxWidth: 800, margin: '0 auto' }}>
      <div style={{ display: 'grid', gridTemplateColumns: '1fr auto', gap: 8, alignItems: 'center' }}>
        <input
          value={q}
          onChange={onChange}
          placeholder="Start typing color name…"
          autoFocus
            onFocus={() => q && setQ('')}  
          inputMode="search"
          style={{
            width: '100%',
            padding: '14px 16px',
            fontSize: '20px',
            border: '2px solid #ddd',
            borderRadius: 12
          }}
        />
        <select
          value={brand}
          onChange={e=>setBrand(e.target.value)}
          style={{ padding: '12px', fontSize: 16, border: '1px solid #ccc', borderRadius: 10 }}
          title="Brand"
        >
          <option value="behr">Behr</option>
          <option value="bm">BM</option>
          <option value="de">DE</option>
          <option value="fb">FB</option>
             <option value="de">DE</option>
                  <option value="sw">SW</option>
                  <option value="vs">Valspar</option>
                       <option value="vist">Vista</option>
          <option value="">All</option>
          {/* add more brands as needed */}
        </select>
      </div>

      <div style={{ marginTop: 10, border: '1px solid #eee', borderRadius: 10, overflow: 'hidden' }}>
        {rows.length === 0 && (
          <div style={{ padding: 16, fontSize: 16, opacity: 0.7 }}>
            {loading ? 'Searching…' : (q ? 'No matches' : 'Type at least 1 letter')}
          </div>
        )}
        {rows.map(bigRow)}
      </div>
    </div>
  );
}

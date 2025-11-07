// ColorForm.jsx
import React, { forwardRef, useEffect, useImperativeHandle, useMemo, useState } from 'react';
import { useAppState } from '@context/AppStateContext';
import fetchColorDetail from '@data/fetchColorDetail';
import './colorform.css';

const toNum = v => (v === '' || v === null || v === undefined) ? '' : Number(v);
const hex6 = s => (s || '').trim().replace(/^#/, '').toUpperCase();

const ColorForm = forwardRef(function ColorForm(_, ref) {
  const { currentColorDetail, setCurrentColorDetail } = useAppState();

  const [form, setForm] = useState({
    id:'', name:'', brand:'', code:'',
    hex6:'', r:'', g:'', b:'',
    lab_l:'', lab_a:'', lab_b:'',
    chip_num:'',       // editable
    cluster_id:'',     // read-only
    exterior: true,    // NEW
    interior: true     // NEW
  });
  const [busy, setBusy] = useState(false);
  const [msg,  setMsg]  = useState('—');

  // Load form from the selected swatch (swatch_view row)
  useEffect(() => {
    if (!currentColorDetail) return;
    setForm({
      id:         currentColorDetail.id ?? '',
      name:       currentColorDetail.name ?? '',
      brand:      currentColorDetail.brand ?? '',
      code:       currentColorDetail.code ?? '',
      hex6:       currentColorDetail.hex6 ?? hex6(currentColorDetail.hex),
      r:          toNum(currentColorDetail.r),
      g:          toNum(currentColorDetail.g),
      b:          toNum(currentColorDetail.b),
      lab_l:      currentColorDetail.lab_l ?? '',
      lab_a:      currentColorDetail.lab_a ?? '',
      lab_b:      currentColorDetail.lab_b ?? '',
      chip_num:   currentColorDetail.chip_num ?? '',
      cluster_id: currentColorDetail.cluster_id ?? '',
      // default true if missing (DB defaults true/true)
      exterior:   (currentColorDetail.exterior ?? true) ? true : false,
      interior:   (currentColorDetail.interior ?? true) ? true : false,
    });
    setMsg('Loaded.');
  }, [currentColorDetail]);

  const chipBg = useMemo(() => {
    const h = hex6(form.hex6);
    return h ? `#${h}` : '#ffffff';
  }, [form.hex6]);

  const setField = (k, v) => setForm(s => ({ ...s, [k]: v }));

  // Interior-only derived flag
  const interiorOnly = form.interior && !form.exterior;

  // Build request body for /api/v2/admin/color-save.php
  function buildBody() {
    const body = {};
    if (String(form.id).trim() !== '' && Number(form.id) > 0) body.id = Number(form.id);
    if (form.name)  body.name  = String(form.name);
    if (form.brand) body.brand = String(form.brand);
    if (form.code)  body.code  = String(form.code);
    if (String(form.chip_num).trim() !== '') body.chip_num = String(form.chip_num);

    const h6 = hex6(form.hex6);
    if (h6) body.hex6 = h6;

    ['r','g','b'].forEach(k => {
      const v = String(form[k]).trim();
      if (v !== '' && !Number.isNaN(Number(v))) body[k] = Number(v);
    });

    // optional LAB overrides (usually omit)
    ['lab_l','lab_a','lab_b'].forEach(k => {
      const v = String(form[k]).trim();
      if (v !== '' && !Number.isNaN(Number(v))) body[k] = Number(v);
    });

    // NEW: usage flags (booleans → 0/1)
    body.exterior = !!form.exterior;
    body.interior = !!form.interior;

    return body;
  }

  async function doSave() {
    try {
      setBusy(true); setMsg('Saving…');
      const body = buildBody();

      if (!body.id && (!body.name || !body.brand)) {
        setMsg('For inserts, name and brand are required.'); return;
      }
      if (!body.hex6 && (body.r === undefined || body.g === undefined || body.b === undefined)) {
        setMsg('Provide hex6 OR r,g,b'); return;
      }

      const res = await fetch('/api/v2/admin/color-save.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(body)
      });
      const json = await res.json();
      if (!res.ok || !json.ok) throw new Error(json.error || 'Save failed');

      const id = json.id;
      setForm(s => ({ ...s, id }));
      setMsg('Saved ✔');

      // Refresh right-hand preview (authoritative swatch_view)
      await fetchColorDetail(id, setCurrentColorDetail);
    } catch (e) {
      setMsg('Save error: ' + (e?.message || e));
    } finally {
      setBusy(false);
    }
  }

  function doReset() {
    setForm({
      id:'', name:'', brand:'', code:'',
      hex6:'', r:'', g:'', b:'',
      lab_l:'', lab_a:'', lab_b:'',
      chip_num:'', cluster_id:'',
      exterior: true,
      interior: true
    });
    setMsg('Ready for new insert.');
  }

  useImperativeHandle(ref, () => ({
    save: doSave,
    reset: doReset,
    delete: () => {}
  }));

  return (
    <div className="cf-wrap">
      <div className="cf-note">{busy ? 'Working…' : '—'} <span style={{marginLeft:8}}>{msg}</span></div>

      <div className="cf-grid-2">
        <label className="cf-label">
          <span className="cf-tag">ID (leave empty to INSERT)</span>
          <input className="cf-input" type="number" min="0"
                 value={form.id} onChange={e=>setField('id', e.target.value)} />
        </label>

        <div className="cf-grid-2">
          <label className="cf-label">
            <span className="cf-tag">Brand</span>
            <input className="cf-input" placeholder="ppg / sw / behr / de"
                   value={form.brand} onChange={e=>setField('brand', e.target.value)} />
          </label>
          <label className="cf-label">
            <span className="cf-tag">Code</span>
            <input className="cf-input" placeholder="PPG1013-5"
                   value={form.code} onChange={e=>setField('code', e.target.value)} />
          </label>
        </div>

        <label className="cf-label" style={{gridColumn:'1 / -1'}}>
          <span className="cf-tag">Name</span>
          <input className="cf-input" placeholder="Victorian Pewter"
                 value={form.name} onChange={e=>setField('name', e.target.value)} />
        </label>

        <div className="cf-grid-3" style={{alignItems:'end'}}>
          <label className="cf-label">
            <span className="cf-tag">hex6 (no #)</span>
            <input className="cf-input" placeholder="EFEFEF"
                   value={form.hex6}
                   onChange={e=>setField('hex6', e.target.value.toUpperCase())}
                   onBlur={()=>setField('hex6', hex6(form.hex6))} />
          </label>
          <div className="cf-chipbox">
            <div className="cf-tag">Preview</div>
            <div className="cf-chip" style={{ background: chipBg }} />
          </div>
          <div />
        </div>

        <div className="cf-grid-3">
          {['r','g','b'].map(k=>(
            <label key={k} className="cf-label">
              <span className="cf-tag">{k.toUpperCase()}</span>
              <input className="cf-input" type="number" min="0" max="255"
                     value={form[k]} onChange={e=>setField(k, e.target.value)} />
            </label>
          ))}
        </div>

        <div className="cf-grid-3">
          {['lab_l','lab_a','lab_b'].map(k=>(
            <label key={k} className="cf-label">
              <span className="cf-tag">{k.replace('lab_','LAB ').toUpperCase()}</span>
              <input className="cf-input" type="number" step="0.0001"
                     value={form[k]} onChange={e=>setField(k, e.target.value)} />
            </label>
          ))}
        </div>

        <div className="cf-grid-2">
          <label className="cf-label">
            <span className="cf-tag">Chip #</span>
            <input className="cf-input" placeholder="optional"
                   value={form.chip_num} onChange={e=>setField('chip_num', e.target.value)} />
          </label>

          <label className="cf-label">
            <span className="cf-tag">Cluster ID (read-only)</span>
            <input className="cf-input" readOnly
                   value={form.cluster_id} />
          </label>
        </div>

        {/* NEW: Usage flags */}
        <div className="cf-grid-3">
          <label className="cf-check">
            <input
              type="checkbox"
              checked={form.exterior}
              onChange={e => setField('exterior', e.target.checked)}
            />
            <span>Exterior</span>
          </label>

          <label className="cf-check">
            <input
              type="checkbox"
              checked={form.interior}
              onChange={e => setField('interior', e.target.checked)}
            />
            <span>Interior</span>
          </label>

          <label className="cf-check">
            <input
              type="checkbox"
              checked={interiorOnly}
              onChange={e => {
                const on = e.target.checked;
                if (on) setForm(s => ({ ...s, interior: true, exterior: false }));
                else    setForm(s => ({ ...s, interior: true, exterior: true })); // back to both by default
              }}
            />
            <span>Interior Only</span>
          </label>
        </div>
      </div>
    </div>
  );
});

export default ColorForm;

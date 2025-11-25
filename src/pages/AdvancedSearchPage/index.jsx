import { useState, useMemo, useEffect } from 'react';
import { useNavigate } from 'react-router-dom';
import { useAppState } from '@context/AppStateContext';
import './advsearch.css';
import CategoryDropdown, { LightnessDropdown, ChromaDropdown } from '@components/CategoryDropdown';
import { toDisplayHue, toWheelRange } from '@helpers/hueHelper';
import ColorWheel300 from '@components/ColorWheel/ColorWheel300';
import ColorWheelIndicator from '@components/ColorWheel/ColorWheelIndicator';
import TopSpacer from '@layout/TopSpacer';
import { API_FOLDER } from '@helpers/config';

const GALLERY_PATH = '/adv-results'; // results page reads from appState.advancedSearch

export default function AdvancedSearchPage() {
  const navigate = useNavigate();
  const { categories = [], advancedSearch, setAdvancedSearch } = useAppState();

  const {
    selectedCatId,
    hueMin, hueMax,
    cMin, cMax,
    lMin, lMax,
    hex6 = '',
    supercatSlug = ''
  } = advancedSearch;

  const [wheelVisible, setWheelVisible] = useState(false);
  const [catObj, setCatObj] = useState(null); // keep for clearAll()
  const [supercats, setSupercats] = useState([]);
  const [supercatState, setSupercatState] = useState({ loading: false, error: '' });

  const selectedCat = useMemo(
    () => categories.find(c => c.id === selectedCatId) || null,
    [categories, selectedCatId]
  );

  // derive hue range for wheel/search (single value => exact hue ±1°)
  const hasHue = hueMin !== '' || hueMax !== '';
  const a = hueMin !== '' ? Number(hueMin) : (hueMax !== '' ? (Number(hueMax) + 359) % 360 : null);
  const b = hueMax !== '' ? Number(hueMax) : (hueMin !== '' ? (Number(hueMin) + 1) % 360 : null);
  const wheel = (a != null && b != null) ? toWheelRange(a, b) : null;

  // setters
  const setHueMinUI = (v) => setAdvancedSearch(p => ({ ...p, hueMin: v === '' ? '' : toDisplayHue(v) }));
  const setHueMaxUI = (v) => setAdvancedSearch(p => ({ ...p, hueMax: v === '' ? '' : toDisplayHue(v) }));
  const setCMinUI  = (v) => setAdvancedSearch(p => ({ ...p, cMin: v }));
  const setCMaxUI  = (v) => setAdvancedSearch(p => ({ ...p, cMax: v }));
  const setLMinUI  = (v) => setAdvancedSearch(p => ({ ...p, lMin: v }));
  const setLMaxUI  = (v) => setAdvancedSearch(p => ({ ...p, lMax: v }));
  const setHex6UI  = (v) => setAdvancedSearch(p => ({ ...p, hex6: v }));
  const setSupercatSlugUI = (slug) => setAdvancedSearch(p => ({ ...p, supercatSlug: slug }));

  useEffect(() => {
    let cancelled = false;
    async function fetchSupercats() {
      setSupercatState({ loading: true, error: '' });
      try {
        const res = await fetch(`${API_FOLDER}/v2/admin/supercats.php?action=list`, {
          credentials: 'include',
          headers: { Accept: 'application/json' }
        });
        const data = await res.json();
        if (cancelled) return;
        if (!data?.ok) throw new Error(data?.error || 'Failed to load supercats');
        const active = (data.supercats || []).filter(sc => (sc?.is_active ?? 1) === 1);
        setSupercats(active);
        setSupercatState({ loading: false, error: '' });
      } catch (err) {
        if (cancelled) return;
        setSupercatState({ loading: false, error: err?.message || 'Unable to load supercats' });
      }
    }
    fetchSupercats();
    return () => { cancelled = true; };
  }, []);

  const supercatOptions = useMemo(
    () => supercats.map((sc) => ({
      slug: sc.slug,
      label: sc.display_name || sc.slug || `Supercat ${sc.id}`,
      id: sc.id
    })),
    [supercats]
  );

  function clearAll() {
    setAdvancedSearch({
      selectedCatId: null,
      hueMin: '', hueMax: '',
      cMin: '', cMax: '',
      lMin: '', lMax: '',
      hex6: '',
      supercatSlug: ''
    });
    setCatObj(null);
  }

  // helpers
  const isAll = (cat) =>
    !cat || cat.category === 'all' || cat.id === 'all' ||
    (typeof cat.name === 'string' && /show all/i.test(cat.name));

  const pick = (obj, keys) => {
    for (const k of keys) if (obj && obj[k] != null && obj[k] !== '') return obj[k];
    return '';
  };

  const applyPreset = (cat, facet /* 'hue' | 'chroma' | 'lightness' */) => {
    const all = isAll(cat);

    if (all && facet === 'hue') {
      setAdvancedSearch(p => ({
        ...p,
        selectedCatId: null,
        hueMin: '', hueMax: '',
        cMin: '',  cMax: '',
        lMin: '',  lMax: '',
      }));
      return;
    }
    if (all && facet === 'chroma') {
      setAdvancedSearch(p => ({ ...p, cMin: '', cMax: '' }));
      return;
    }
    if (all && facet === 'lightness') {
      setAdvancedSearch(p => ({ ...p, lMin: '', lMax: '' }));
      return;
    }

    const hMinRaw = pick(cat, ['hue_min','h_min','hueMin']);
    const hMaxRaw = pick(cat, ['hue_max','h_max','hueMax']);
    const cMinRaw = pick(cat, ['chroma_min','c_min','min_c','min']);
    const cMaxRaw = pick(cat, ['chroma_max','c_max','max_c','max']);
    const lMinRaw = pick(cat, ['light_min','l_min','min_l']);
    const lMaxRaw = pick(cat, ['light_max','l_max','max_l']);

    setAdvancedSearch(p => ({
      ...p,
      ...(hMinRaw !== '' ? { hueMin: toDisplayHue(hMinRaw) } : {}),
      ...(hMaxRaw !== '' ? { hueMax: toDisplayHue(hMaxRaw) } : {}),
      ...(cMinRaw !== '' ? { cMin: String(cMinRaw) } : {}),
      ...(cMaxRaw !== '' ? { cMax: String(cMaxRaw) } : {}),
      ...(lMinRaw !== '' ? { lMin: String(lMinRaw) } : {}),
      ...(lMaxRaw !== '' ? { lMax: String(lMaxRaw) } : {}),
      ...(facet === 'hue' && cat?.id != null ? { selectedCatId: cat.id } : {}),
    }));
  };

// AdvancedSearchPage.jsx
function handleSubmit(e) {
  e.preventDefault();
  // bump a submit token so results page knows this was an explicit submit
  setAdvancedSearch(p => ({ ...p, _submitSeq: Date.now() }));
  navigate('/adv-results');
}

  return (
    <form className="asp-form" onSubmit={handleSubmit}>
      <TopSpacer />
      <div className="asp-container">
        <h3>Advanced Search</h3>

        <div className="asp-hcl-block">
          {/* Category */}
           <div className="asp-row">
          <div className="asp-field">
            <div className="asp-dropdown-row">
              <CategoryDropdown onSelect={(cat) => applyPreset(cat, 'hue')} useShowAll />
            </div>
          </div>

          {/* Supercat */}
          <div className="asp-field">
            <label htmlFor="supercat">Supercat</label>
            <select
              id="supercat"
              value={supercatSlug}
              onChange={(e) => setSupercatSlugUI(e.target.value)}
              disabled={supercatState.loading}
            >
              <option value="">Any</option>
              {supercatOptions.map((opt) => (
                <option key={opt.slug || opt.id} value={opt.slug || ''}>
                  {opt.label}
                </option>
              ))}
            </select>
            {supercatState.error && <div className="asp-hint">{supercatState.error}</div>}
          </div>
    </div>

          {/* Hue range */}
          <div className="asp-row">
            <div className="asp-field">
              <label htmlFor="hueMin">Hue Min (°)</label>
              <input
                id="hueMin"
                type="number"
                inputMode="numeric"
                min={0}
                max={360}
                step="any"
                value={hueMin}
                onChange={(e) => setHueMinUI(e.target.value)}
                placeholder="optional"
              />
            </div>
            <div className="asp-field">
              <label htmlFor="hueMax">Hue Max (°)</label>
              <input
                id="hueMax"
                type="number"
                inputMode="numeric"
                min={0}
                max={360}
                step="any"
                value={hueMax}
                onChange={(e) => setHueMaxUI(e.target.value)}
                placeholder="optional"
              />
            </div>
          </div>

          {/* Wheel toggle */}
          <div className="asp-wheel-toggle">
            <span
              type="button"
              className="asp-toggle-link"
              onClick={() => setWheelVisible(v => !v)}
              aria-expanded={wheelVisible}
              aria-controls="hue-wheel"
            >
              {wheelVisible ? 'Hide Hue Wheel' : 'Show Hue Wheel'}
            </span>
          </div>

          {wheelVisible && (
            <div className="asp-wheel" id="hue-wheel">
              <ColorWheel300 currentColor={null}>
                {wheel && (
                  <>
                    <ColorWheelIndicator
                      key="hue-start"
                      hue={wheel.wheelMin}
                      center={150}
                      radius={150}
                      strokeColor="black"
                      dashed
                    />
                    <ColorWheelIndicator
                      key="hue-end"
                      hue={wheel.wheelMax}
                      center={150}
                      radius={150}
                      strokeColor="black"
                      dashed
                    />
                  </>
                )}
              </ColorWheel300>
            </div>
          )}

          {/* CHROMA */}
          <div className="asp-range">
            <div className="asp-dropdown-row">
              <div className="asp-range-label">Chroma</div>
              <ChromaDropdown onSelect={(cat) => applyPreset(cat, 'chroma')} />
            </div>
            <div className="asp-range-fields">
              <input
                id="cMin"
                type="number"
                inputMode="decimal"
                min={0}
                step="any"
                placeholder="Min"
                value={cMin}
                onChange={(e) => setCMinUI(e.target.value)}
              />
              <input
                id="cMax"
                type="number"
                inputMode="decimal"
                min={0}
                step="any"
                placeholder="Max"
                value={cMax}
                onChange={(e) => setCMaxUI(e.target.value)}
              />
            </div>
          </div>

          {/* LIGHTNESS */}
          <div className="asp-range">
            <div className="asp-dropdown-row">
              <div className="asp-range-label">Lightness</div>
              <LightnessDropdown onSelect={(cat) => applyPreset(cat, 'lightness')} />
            </div>
            <div className="asp-range-fields">
              <input
                id="lMin"
                type="number"
                inputMode="decimal"
                min={0}
                max={100}
                step="any"
                placeholder="Min"
                value={lMin}
                onChange={(e) => setLMinUI(e.target.value)}
              />
              <input
                id="lMax"
                type="number"
                inputMode="decimal"
                min={0}
                max={100.1}
                step="any"
                placeholder="Max"
                value={lMax}
                onChange={(e) => setLMaxUI(e.target.value)}
              />
            </div>
          </div>

          {/* HEX6 */}
          <div className="asp-range">
            <div className="asp-dropdown-row">
              <div className="asp-range-label">HEX</div>
            </div>
            <div className="asp-range-fields">
              <input
                id="hex6"
                type="text"
                inputMode="text"
                placeholder="#RRGGBB or RRGGBB or #RGB"
                value={hex6}
                onChange={(e) => setHex6UI(e.target.value)}
              />
            </div>
          </div>

          {/* Actions */}
          <div className="asp-actionbar">
            <button type="button" className="asp-btn-link" onClick={clearAll}>Clear</button>
            <button type="submit" className="asp-btn-primary">Search</button>
          </div>
        </div>
      </div>
    </form>
  );
}

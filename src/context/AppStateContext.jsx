// src/context/AppStateContext.jsx

import { createContext, useState, useContext, useEffect, useRef, useMemo } from 'react';
import fetchCategories from '@data/fetchCategories'; //for wheels not admin
import fetchColors from '@data/fetchColors';
import fetchColorSchemes from '@data/fetchColorSchemes';
import { normalizeSwatch } from '@helpers/swatchHelper'; 

const AppStateContext = createContext();
const PALETTE_STORAGE_KEY = "pals.palette.v1";
//import mockBoards from '@test/mockBoards';


//helpers
const savePaletteIds = (arr = []) => {
  try {
    localStorage.setItem(
      PALETTE_STORAGE_KEY,
      JSON.stringify(arr.map(s => s?.id).filter(Boolean))
    );
  } catch {}
};

const sleep = (ms) => new Promise(r => setTimeout(r, ms));
async function normalizeWithRetry(id, tries = 5, delay = 120) {
  for (let i = 0; i < tries; i++) {
    const sw = await normalizeSwatch(id);
    if (sw && sw.id) return sw;
    await sleep(delay);
  }
  return null;
}


const initialAdvancedSearch = {
  selectedCatId: null,
  hueMin: "",
  hueMax: "",
  cMin: "",
  cMax: "",
  lMin: "",
  lMax: "",
  hex6: "",
  supercatSlug: ""
};


  // keep only one entry per id
  const dedupeById = (arr) => Array.from(new Map(arr.map(x => [x.id, x])).values());

  //empty color object
  const defaultColorDetail = {
    id: null,
    name: '',
    code: '',
    brand: '',
    brand_descr: '',
    interior: true,
    exterior: true,
    color_url: '',
    notes: '',
    hue_cats: '',
    neutral_cats: '',

    // RGB
    r: null,
    g: null,
    b: null,

    // LRV
    lrv: null,
    lrv_est: null,

    // HCL
    hcl_l: null,
    hcl_c: null,
    hcl_h: null,

    // CIELAB
    lab_l: null,
    lab_a: null,
    lab_b: null,

    // HSL (optional, legacy)
    hsl_h: null,
    hsl_l: null,
    hsl_s: null,
    hsl_hue_old: null
  };

  //empty color object
  const defaultColor = {
    id: null,
    name: '',
    code: '',
    brand: '',
   // brand_descr: '',
   // interior: true,
   // exterior: true,
   // color_url: '',
   // notes: '',
    hue_cats: '',
    neutral_cats: '',

    // RGB
    r: null,
    g: null,
    b: null,

    // LRV
    //lrv: null,
   //lrv_est: null,

    // HCL
    hcl_l: null,
    hcl_c: null,
    hcl_h: null,

    // CIELAB
   // lab_l: null,
   // lab_a: null,
   // lab_b: null,

    // HSL (optional, legacy)
  //  hsl_h: null,
  //  hsl_l: null,
  //  hsl_s: null,
  //  hsl_hue_old: null
  };

export function AppStateProvider({ children }) {
  //DECLARATIONS
  const [loggedIn, setLoggedIn] = useState(false);
  const [user, setUser] = useState(null);
  const [colors, setColors] = useState([]);
  const [searchResults, setSearchResults] = useState([]);


  const [currentColor, setCurrentColor] = useState(defaultColor);
  const [currentColorDetail, setCurrentColorDetail] = useState(defaultColorDetail);

  const [sortDescending, setSortDescending] = useState(false);

  const [selectedCategory, setSelectedCategory] = useState('all');

  const [message, setMessage] = useState('');
  const [categories, setCategories] = useState([]);
  const [showBack, setShowBack] = useState(false);

 
  const [recentSwatches, setRecentSwatches] = useState([]); //used for detail screen
const [searchFilters, setSearchFilters] = useState({
    brands: [],     // canonical
 });
  const [brandFiltersAppliedSeq, setBrandFiltersAppliedSeq] = useState(0);

  const [noResults, setNoResults] = useState(false);
  const [showPalette, setShowPalette] = useState(false);  //default true?

  const [paletteActiveColor, setPaletteActiveColor] = useState(null);
  const [advancedSearch, setAdvancedSearch] = useState(initialAdvancedSearch);
const [savedPaletteIds, setSavedPaletteIds] = useState([]);


const [palette, setPalette] = useState([]); // array of full swatch objects
const [hydrated, setHydrated] = useState(false);

//USE EFFECTS 

// 1) Load once: read ids -> hydrate with normalizeSwatch (MERGE + DEDUPE)
// Hydrate palette once from localStorage via normalizeSwatch (no writes here)
// Hydrate palette from localStorage once, with retries + numeric/string id handling
useEffect(() => {
  let cancelled = false;

  const sleep = (ms) => new Promise(r => setTimeout(r, ms));

  async function normalizeWithRetry(id, tries = 5, delay = 120) {
    for (let i = 0; i < tries; i++) {
      try {
        // try as-is (number or string), then as string, then as number
        let sw = await normalizeSwatch(id);
        if (!sw || !sw.id) sw = await normalizeSwatch(String(id));
        if (!sw || !sw.id) sw = await normalizeSwatch(Number(id));

        // last resort: try via loaded colors (if normalize needs a raw)
        if ((!sw || !sw.id) && Array.isArray(colors) && colors.length) {
          const raw = colors.find(c => String(c.id) === String(id));
          if (raw) sw = await normalizeSwatch(raw);
        }

        if (sw && sw.id) return sw;
      } catch (_) {}
      await sleep(delay);
    }
    return null;
  }

  (async () => {
    let ids = [];
    try {
      const raw = localStorage.getItem(PALETTE_STORAGE_KEY);
      const parsed = raw ? JSON.parse(raw) : [];
      ids = Array.isArray(parsed)
        ? parsed.map(x => (x && typeof x === 'object' ? x.id : x))
              .filter(v => v !== null && v !== undefined && v !== '')
        : [];
    } catch (_) {}

    if (!ids.length) return;

    const swatches = await Promise.all(ids.map(id => normalizeWithRetry(id)));
    if (cancelled) return;

    const clean = swatches.filter(Boolean);
    if (clean.length) {
      setPalette(clean);      // full swatch objects
      setShowPalette(true);   // make sure the palette bar is visible
    }
  })();

  return () => { cancelled = true; };
}, [colors]); // place directly after your fetchData() effect




// 4) Hydrate the palette from saved IDs via normalizeSwatch (with retry)
useEffect(() => {
  if (!savedPaletteIds.length) return;   // need IDs
  if (palette.length) return;            // already hydrated

  let cancelled = false;
  (async () => {
    const swatches = await Promise.all(
      savedPaletteIds.map(id => normalizeWithRetry(id)) // uses helper from #2
    );
    if (cancelled) return;
    const clean = swatches.filter(Boolean);
    if (clean.length) setPalette(clean);
  })();

  return () => { cancelled = true; };
}, [savedPaletteIds.join(','), palette.length]);




 // 3) Cross-tab sync: rehydrate if another tab updates (MERGE + DEDUPE)
useEffect(() => {
  const onStorage = (e) => {
    if (e.key !== PALETTE_STORAGE_KEY) return;
    try {
      const ids = e.newValue ? JSON.parse(e.newValue) : [];
      if (!Array.isArray(ids)) return;

      (async () => {
        const swatches = await Promise.all(ids.map(id => normalizeSwatch(id)));
        setPalette(prev => dedupeById([
          ...prev,
          ...swatches.filter(Boolean)
        ]));
      })();
    } catch (_) { /* ignore */ }
  };
  window.addEventListener("storage", onStorage);
  return () => window.removeEventListener("storage", onStorage);
}, []);

useEffect (() => {
    if (palette.length > 0) {
      setShowPalette(true);
    } else {
      setShowPalette(false);
    }
  }, [palette.length])

  //INITIAL LOAD -- cats and colors
  useEffect(() => {
    async function fetchData() {
      try {
        const [categoriesData, colorData] = await Promise.all([
          fetchCategories(),  // your async fetch function
          fetchColors()
        ]);
        console.log('cats from db:', categoriesData);
        setCategories(categoriesData);
        setColors(colorData);
      } catch (error) {
        console.error("Fetch failed", error);
      }
    }
    fetchData();
  }, []);


  useEffect(() => {
      console.log('current:', currentColor);
  }, [currentColor]);

  useEffect(() => {
    showMessage(message);
  }, [message]);

  //FUNCTIONS
function toggleFilter(key, value, { exclusive = false } = {}) {
  setSearchFilters(prev => {
    const curr = Array.isArray(prev[key]) ? prev[key] : [];
    let next;

    if (exclusive) {
      // only this value (click again to clear)
      next = curr.includes(value) ? [] : [value];
    } else {
      next = curr.includes(value)
        ? curr.filter(v => v !== value)     // remove
        : [...curr, value];                 // add
    }

    // de-dupe just in case
    next = Array.from(new Set(next));
    return { ...prev, [key]: next };
  });
}

function clearFilter(key) {
  setSearchFilters(prev => ({ ...prev, [key]: [] }));
}

function setFilterValues(key, values = []) {
  setSearchFilters(prev => ({ ...prev, [key]: Array.from(new Set(values)) }));
}

function isFilterChecked(key, value) {
  const arr = searchFilters?.[key] || [];
  return arr.includes(value);
}


  function refreshCategories() {
    //for when they are reassigned
    fetchCategories()
    .then(data => {
      setCategories([...data]);  //make sure new array
      setMessage('Categories refreshed');
    });
  }

  function upsertColor(newColor) {
    console.log('adding:', newColor);
    setColors((prevColors) => {
      const index = prevColors.findIndex(c => c.id === newColor.id);
      if (index !== -1) {
        const updated = [...prevColors];
        updated[index] = newColor;
        console.log('color count:', updated.length);
        return updated;
      } else {
        console.log('color count:', prevColors.length + 1);
        return [...prevColors, newColor];
      }
    });
    setCurrentColor(newColor);
  }


  function showMessage(msg, timeout = 3000) {
      setMessage(msg);
      setTimeout(() => {
        setMessage('');
      }, timeout);
  }

  const clearSearchFilters = () => {
    setSearchFilters({
      brands: []
    });
  };

function addToPalette(rawSwatch) {
  normalizeSwatch(rawSwatch).then((swatch) => {
    if (!swatch?.id) return;
    setPalette(prev => {
      const next = Array.from(new Map([...prev, swatch].map(x => [x.id, x])).values());
      savePaletteIds(next);              // ✅ write here
      return next;
    });
    setPaletteActiveColor(swatch);
  });
}

async function addManyToPalette(rawList) {
  if (!Array.isArray(rawList) || rawList.length === 0) return [];

  const settled = await Promise.allSettled(rawList.map(normalizeSwatch));
  const normalized = [];
  const seen = new Set();
  for (const r of settled) {
    if (r.status !== 'fulfilled') continue;
    const sw = r.value;
    const id = sw?.id;
    if (!id || seen.has(id)) continue;
    seen.add(id);
    normalized.push(sw);
  }
  if (normalized.length === 0) return [];

  let lastAdded = null;
  setPalette(prev => {
    const prevIds = new Set(prev.map(x => x.id));
    const toAdd = [];
    for (const sw of normalized) {
      if (!prevIds.has(sw.id)) {
        toAdd.push(sw);
        lastAdded = sw;
      }
    }
    if (toAdd.length === 0) return prev;
    const next = prev.concat(toAdd);
    savePaletteIds(next);
    return next;
  });

  if (lastAdded) setPaletteActiveColor(lastAdded);
  return normalized;
}




function removeFromPalette(id) {
  setPalette(prev => {
    const next = prev.filter(c => c.id !== id);
    savePaletteIds(next);                // ✅ write here
    return next;
  });
  setPaletteActiveColor(prev => (prev?.id === id ? null : prev));
}

 const clearPalette = () => {
  setPalette([]);
  savePaletteIds([]);                    // ✅ write here
};

function applyBrandFilters(brands) {
  setFilterValues('brands', brands);     // you already have setFilterValues
  setBrandFiltersAppliedSeq(s => s + 1); // bump a counter each Apply
}

  //EXPORTS
  const state = {
    loggedIn, setLoggedIn,
    user, setUser,
    defaultColor, defaultColorDetail,
    colors, setColors,  //summaries
    searchResults, setSearchResults,  //summaries
    currentColor, setCurrentColor, //summary
    currentColorDetail, setCurrentColorDetail,  //detail
    upsertColor,
    message, setMessage, showMessage,
    categories, setCategories, refreshCategories,
    selectedCategory, setSelectedCategory,
    showBack, setShowBack,
    recentSwatches, setRecentSwatches,
    searchFilters, setSearchFilters, clearSearchFilters,
       toggleFilter, clearFilter, setFilterValues, isFilterChecked,
    noResults, setNoResults,
    showPalette, setShowPalette,
    palette, setPalette, addToPalette, removeFromPalette, clearPalette, addManyToPalette,
    paletteActiveColor, setPaletteActiveColor,
    advancedSearch, setAdvancedSearch,
    applyBrandFilters, brandFiltersAppliedSeq
  };

  return (
    <AppStateContext.Provider value={state}>
      {children}
    </AppStateContext.Provider>
  );
}

export function useAppState() {
  return useContext(AppStateContext);
}

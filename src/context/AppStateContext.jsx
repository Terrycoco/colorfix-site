// src/context/AppStateContext.jsx



import { createContext, useState, useContext, useEffect, useRef, useMemo } from 'react';

import fetchCategories from '@data/fetchCategories'; //for wheels not admin


import fetchColorSchemes from '@data/fetchColorSchemes';

import { normalizeSwatch } from '@helpers/swatchHelper'; 

import { API_FOLDER } from '@helpers/config';
import { clearLegacyAdminState, setAdmin } from '@helpers/authHelper';



const AppStateContext = createContext();

const PALETTE_STORAGE_KEY = "pals.palette.v1";

const BRAND_FILTERS_STORAGE_KEY = "pals.brand_filters.v1";

const ADMIN_EXIT_PATH_STORAGE_KEY = "cf.admin_exit_path.v1";

const ACTIVE_PROJECT_STORAGE_KEY = "cf.active_project_id.v1";

const WORKING_PLAYLIST_STORAGE_KEY = "cf.working_playlist_id.v1";

const PLAYER_EXPERIENCE_STORAGE_KEY = "cf.player_experience.v1";

//import mockBoards from '@test/mockBoards';





//helpers

const savePalette = (arr = []) => {

  try {

    localStorage.setItem(

      PALETTE_STORAGE_KEY,

      JSON.stringify(arr.filter(s => s?.id))

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



function normalizeBrandList(values) {

  if (!Array.isArray(values)) return [];

  return Array.from(

    new Set(values.map((value) => String(value || '').trim().toLowerCase()).filter(Boolean))

  );

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

  const dedupeById = (arr) => Array.from(new Map(arr.map(x => [String(x.id), x])).values());



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

  // Stored browser flags are never proof of authentication.
  const [loggedIn, setLoggedIn] = useState(false);
  const [user, setUser] = useState(null);
  const [authReady, setAuthReady] = useState(false);
  const [authError, setAuthError] = useState('');

  const [colors, setColors] = useState([]);

  const [searchResults, setSearchResults] = useState([]);





  const [currentColor, setCurrentColor] = useState(defaultColor);

  const [currentColorDetail, setCurrentColorDetail] = useState(defaultColorDetail);



  const [sortDescending, setSortDescending] = useState(false);



  const [selectedCategory, setSelectedCategory] = useState('all');



  const [message, setMessage] = useState('');

  const [categories, setCategories] = useState([]);

  const [showBack, setShowBack] = useState(false);

  const [adminExitPath, setAdminExitPathState] = useState(() => {

    if (typeof window === 'undefined') return '';

    try {

      return sessionStorage.getItem(ADMIN_EXIT_PATH_STORAGE_KEY) || '';

    } catch {

      return '';

    }

  });



  const [activeProjectId, setActiveProjectIdState] = useState(() => {

    if (typeof window === 'undefined') return null;



    try {

      const raw = localStorage.getItem(ACTIVE_PROJECT_STORAGE_KEY);

      const id = Number(raw || 0);



      return id > 0

        ? id

        : null;

    } catch {

      return null;

    }

  });



  const [workingPlaylistId, setWorkingPlaylistIdState] = useState(() => {

    if (typeof window === 'undefined') return null;



    try {

      const raw = localStorage.getItem(WORKING_PLAYLIST_STORAGE_KEY);

      const id = Number(raw || 0);



      return id > 0

        ? id

        : null;

    } catch {

      return null;

    }

  });



  const [playerExperience, setPlayerExperienceState] = useState(() => {

    if (typeof window === 'undefined') return '';



    try {

      return String(

        sessionStorage.getItem(PLAYER_EXPERIENCE_STORAGE_KEY) || ''

      )

        .trim()

        .toLowerCase();

    } catch {

      return '';

    }

  });



  const [recentSwatches, setRecentSwatches] = useState([]); //used for detail screen

const [searchFilters, setSearchFilters] = useState(() => {

  if (typeof window === 'undefined') return { brands: [] };

  try {

    const raw = localStorage.getItem(BRAND_FILTERS_STORAGE_KEY);

    if (!raw) return { brands: [] };

    const parsed = JSON.parse(raw);

    if (Array.isArray(parsed)) {

      return { brands: normalizeBrandList(parsed) };

    }

    if (parsed && typeof parsed === 'object' && Array.isArray(parsed.brands)) {

      return { brands: normalizeBrandList(parsed.brands) };

    }

  } catch {

  }

  return { brands: [] };

});

  const [brandFiltersAppliedSeq, setBrandFiltersAppliedSeq] = useState(0);



  const [noResults, setNoResults] = useState(false);

  const [showPalette, setShowPalette] = useState(false);  // derived from paletteCollapsed later



  const [paletteActiveColor, setPaletteActiveColor] = useState(null);

  const [paletteCollapsed, setPaletteCollapsed] = useState(() => {

    if (typeof window === 'undefined') return false;

    const raw = localStorage.getItem('cf_palette_collapsed');

    if (raw === 'true') return true;

    if (raw === 'false') return false;

    return false;

  });

  const [advancedSearch, setAdvancedSearch] = useState(initialAdvancedSearch);







const [palette, setPaletteState] = useState([]); // array of full swatch objects
const paletteRef = useRef(palette);
const paletteRevision = useRef(0);
const paletteRestore = useRef(null);

// Keep the existing public setter and every palette operation persisted together.
function setPalette(update) {
  paletteRevision.current += 1;
  setPaletteState(previous => {
    const next = typeof update === 'function' ? update(previous) : update;
    paletteRef.current = next;
    savePalette(next);
    return next;
  });
}

function resolvePaletteSwatch(raw) {
  const id = raw && typeof raw === 'object' ? (raw.id ?? raw.color_id) : raw;
  const existing = paletteRef.current.find(color => String(color.id) === String(id));
  return normalizeSwatch(existing ? { ...existing, ...(raw && typeof raw === 'object' ? raw : {}) } : raw);
}



//USE EFFECTS 



// The server validates the HttpOnly session, including account/session revocation.
useEffect(() => {
  setAdmin(Boolean(user?.is_admin));
}, [user]);

useEffect(() => {
  let cancelled = false;
  let pending = false;
  let retryTimer;
  let requestController;
  clearLegacyAdminState();
  const refreshSession = async () => {
    if (pending) return;
    pending = true;
    requestController = new AbortController();
    const timeout = window.setTimeout(() => requestController.abort(), 15000);
    try {
      const response = await fetch(`${API_FOLDER}/session.php`, {
        credentials: 'include', cache: 'no-store', signal: requestController.signal,
      });
      // Only an authoritative rejection ends a verified login. A timeout or
      // temporary server error must not bounce an already verified device out.
      if (response.status !== 401 && !response.ok) throw new Error('Session check unavailable');
      const payload = await response.json();
      if (cancelled) return;
      if (response.status !== 401 && !(payload?.ok === false || payload?.ok === true && typeof payload?.user?.is_admin === 'boolean')) {
        throw new Error('Invalid session response');
      }
      const verified = response.ok && payload?.ok && payload?.user?.is_admin;
      setAdmin(Boolean(verified));
      setUser(previous => {
        if (!verified) return null;
        // Periodic verification must not look like a different user to editors.
        if (previous?.id === payload.user.id && previous?.email === payload.user.email
            && previous?.is_admin === payload.user.is_admin) return previous;
        return payload.user;
      });
      setLoggedIn(Boolean(verified));
      setAuthReady(true);
      setAuthError('');
      window.clearTimeout(retryTimer);
    } catch {
      if (!cancelled) {
        setAuthError('Unable to check your saved login. Retrying…');
        window.clearTimeout(retryTimer);
        retryTimer = window.setTimeout(refreshSession, 5000);
      }
    } finally {
      window.clearTimeout(timeout);
      pending = false;
    }
  };
  refreshSession();
  const timer = window.setInterval(refreshSession, 60000);
  window.addEventListener('focus', refreshSession);
  return () => {
    cancelled = true;
    requestController?.abort();
    window.clearTimeout(retryTimer);
    window.clearInterval(timer);
    window.removeEventListener('focus', refreshSession);
  };
}, []);

useEffect(() => {

  if (typeof window === 'undefined') return;

  try {

    const brands = normalizeBrandList(searchFilters?.brands ?? []);

    localStorage.setItem(BRAND_FILTERS_STORAGE_KEY, JSON.stringify(brands));

  } catch {}

}, [searchFilters?.brands]);



// Restore complete objects directly; fetch only legacy/incomplete entries.
// Reuse the same restore operation during StrictMode effect replay.
useEffect(() => {
  let cancelled = false;
  const revision = paletteRevision.current;
  if (!paletteRestore.current) {
    let entries = [];
    try {
      const parsed = JSON.parse(localStorage.getItem(PALETTE_STORAGE_KEY) || '[]');
      if (Array.isArray(parsed)) {
        entries = [...new Map(parsed.filter(entry => {
          const id = entry && typeof entry === 'object' ? entry.id : entry;
          return id !== null && id !== undefined && id !== '';
        }).map(entry => [String(entry && typeof entry === 'object' ? entry.id : entry), entry])).values()];
      }
    } catch { /* Invalid or unavailable storage starts with an empty palette. */ }
    paletteRestore.current = Promise.all(entries.map(entry => normalizeWithRetry(entry)));
  }
  paletteRestore.current.then(swatches => {
    if (cancelled || revision !== paletteRevision.current) return;
    const clean = dedupeById(swatches.filter(Boolean));
    paletteRef.current = clean;
    setPaletteState(clean);
    if (swatches.every(Boolean)) savePalette(clean); // Keep legacy IDs if a lookup failed.
  });
  return () => { cancelled = true; };
}, []);

// Another tab's palette is a replacement, including removals and clears.
useEffect(() => {
  let cancelled = false;
  const onStorage = event => {
    if (event.key !== PALETTE_STORAGE_KEY) return;
    let entries;
    try { entries = JSON.parse(event.newValue || '[]'); } catch { return; }
    if (!Array.isArray(entries)) return;
    const revision = ++paletteRevision.current;
    Promise.all(entries.map(entry => {
      const id = entry && typeof entry === 'object' ? (entry.id ?? entry.color_id) : entry;
      const existing = paletteRef.current.find(color => String(color.id) === String(id));
      return normalizeSwatch(existing ? { ...existing, ...(entry && typeof entry === 'object' ? entry : {}) } : entry);
    })).then(swatches => {
      if (cancelled || revision !== paletteRevision.current) return;
      const clean = dedupeById(swatches.filter(Boolean));
      paletteRef.current = clean;
      setPaletteState(clean);
      // Equal values do not emit storage events in other tabs.
      if (swatches.every(Boolean) && JSON.stringify(clean) !== event.newValue) savePalette(clean);
    });
  };
  window.addEventListener('storage', onStorage);
  return () => { cancelled = true; window.removeEventListener('storage', onStorage); };
}, []);

  useEffect(() => {

    if (typeof window === 'undefined') return;

    localStorage.setItem('cf_palette_collapsed', paletteCollapsed ? 'true' : 'false');

  }, [paletteCollapsed]);



  useEffect(() => {

    setShowPalette(palette.length > 0 && !paletteCollapsed);

  }, [palette.length, paletteCollapsed]);



  // INITIAL LOAD: category metadata only. Gallery swatches arrive via run-query.
  useEffect(() => {
    let cancelled = false;
    fetchCategories()
      .then((data) => { if (!cancelled) setCategories(data); })
      .catch((error) => console.error('Category fetch failed', error));
    return () => { cancelled = true; };
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

  resolvePaletteSwatch(rawSwatch).then((swatch) => {

    if (!swatch?.id) return;

    setPalette(prev => {

      const next = dedupeById([...prev, swatch]);


      return next;

    });

    setPaletteActiveColor(swatch);

  });

}



async function addManyToPalette(rawList) {

  if (!Array.isArray(rawList) || rawList.length === 0) return [];



  const settled = await Promise.allSettled(rawList.map(resolvePaletteSwatch));

  const normalized = [];

  const seen = new Set();

  for (const r of settled) {

    if (r.status !== 'fulfilled') continue;

    const sw = r.value;

    const id = sw?.id;

    if (!id || seen.has(String(id))) continue;

    seen.add(String(id));

    normalized.push(sw);

  }

  if (normalized.length === 0) return [];



  let lastAdded = null;

  setPalette(prev => {

    const prevIds = new Set(prev.map(x => String(x.id)));

    const toAdd = [];

    for (const sw of normalized) {

      if (!prevIds.has(String(sw.id))) {

        toAdd.push(sw);

        lastAdded = sw;

      }

    }

    if (toAdd.length === 0) return prev;

    const next = prev.concat(toAdd);



    return next;

  });



  if (lastAdded) setPaletteActiveColor(lastAdded);

  return normalized;

}









function removeFromPalette(id) {

  setPalette(prev => {

    const next = prev.filter(c => String(c.id) !== String(id));


    return next;

  });

  setPaletteActiveColor(prev => (String(prev?.id) === String(id) ? null : prev));

}



function reorderPalette(next) {

  if (!Array.isArray(next)) return;

  setPalette(next);



}



 const clearPalette = () => {

  setPalette([]);


};



function applyBrandFilters(brands) {

  setFilterValues('brands', brands);     // you already have setFilterValues

  setBrandFiltersAppliedSeq(s => s + 1); // bump a counter each Apply

}



function setAdminExitPath(path = '') {

  const next = String(path || '').trim();

  setAdminExitPathState(next);

  if (typeof window === 'undefined') return;

  try {

    if (next) {

      sessionStorage.setItem(ADMIN_EXIT_PATH_STORAGE_KEY, next);

    } else {

      sessionStorage.removeItem(ADMIN_EXIT_PATH_STORAGE_KEY);

    }

  } catch {}

}



function clearAdminExitPath() {

  setAdminExitPath('');

}





function setActiveProjectId(projectId = null) {

  const id = Number(projectId || 0);

  const next = id > 0

    ? id

    : null;



  setActiveProjectIdState(next);



  if (typeof window === 'undefined') return;



  try {

    if (next) {

      localStorage.setItem(

        ACTIVE_PROJECT_STORAGE_KEY,

        String(next)

      );

    } else {

      localStorage.removeItem(

        ACTIVE_PROJECT_STORAGE_KEY

      );

    }

  } catch {}

}



function clearActiveProjectId() {

  setActiveProjectId(null);

}



function setWorkingPlaylistId(playlistId = null) {

  const id = Number(playlistId || 0);

  const next = id > 0

    ? id

    : null;



  setWorkingPlaylistIdState(next);



  if (typeof window === 'undefined') return;



  try {

    if (next) {

      localStorage.setItem(

        WORKING_PLAYLIST_STORAGE_KEY,

        String(next)

      );

    } else {

      localStorage.removeItem(

        WORKING_PLAYLIST_STORAGE_KEY

      );

    }

  } catch {}

}



function clearWorkingPlaylistId() {

  setWorkingPlaylistId(null);

}





function setPlayerExperience(experience = '') {

  const next =

    String(experience || '')

      .trim()

      .toLowerCase();



  setPlayerExperienceState(next);



  if (typeof window === 'undefined') return;



  try {

    if (next) {

      sessionStorage.setItem(

        PLAYER_EXPERIENCE_STORAGE_KEY,

        next

      );

    } else {

      sessionStorage.removeItem(

        PLAYER_EXPERIENCE_STORAGE_KEY

      );

    }

  } catch {}

}



function clearPlayerExperience() {

  setPlayerExperience('');

}





  //EXPORTS

  const state = {

    loggedIn, setLoggedIn, authReady, authError,

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

    adminExitPath, setAdminExitPath, clearAdminExitPath,

    activeProjectId, setActiveProjectId, clearActiveProjectId,

    workingPlaylistId, setWorkingPlaylistId, clearWorkingPlaylistId,

    playerExperience, setPlayerExperience, clearPlayerExperience,

    recentSwatches, setRecentSwatches,

    searchFilters, setSearchFilters, clearSearchFilters,

       toggleFilter, clearFilter, setFilterValues, isFilterChecked,

    noResults, setNoResults,

    showPalette,

    paletteCollapsed, setPaletteCollapsed,

    palette, setPalette, addToPalette, removeFromPalette, reorderPalette, clearPalette, addManyToPalette,

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

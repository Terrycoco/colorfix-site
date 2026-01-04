// SideBySidePage.jsx
import { useEffect, useRef, useState } from 'react';
import { useLocation, useNavigate } from 'react-router-dom';
import {API_FOLDER} from '@helpers/config';
import {useAppState} from '@context/AppStateContext';
import {isAdmin} from '@helpers/authHelper';
import './sidebyside.css';
import {getWarmerColor } from '@helpers/colorCalcs';
import FuzzySearchColorSelect from '@components/FuzzySearchColorSelect';

export default function SideBySidePage() {
    const {recentSwatches, setRecentSwatches, setMessage} = useAppState();
    const [colorA, setColorA] = useState(null);
    const [colorB, setColorB] = useState(null);
    const [source, setSource] = useState('manual');
    const [notes, setNotes] = useState('');
    const [friends, setFriends] = useState([]);
    const navigate = useNavigate();
    const location = useLocation();
    const suppressNavigateRef = useRef(false);
    const lastSearchRef = useRef(location.search);

    const loadFriends = (id) => {
        const ts = Date.now();
        fetch(`${API_FOLDER}/get-friends.php?ids=${id}&ts=${ts}`)
          .then((res) => res.json())
  
          .then((data) => {
            if (Array.isArray(data)) setFriends(data);
            else console.error('Error loading friends', data);
          })
          .catch((err) => {
            console.error('Fetch failed:', err);
          });
      };
  
    useEffect(() => {
        const params = new URLSearchParams(location.search);
        if (params.get('a') || params.get('b')) return;
        if (recentSwatches.length >= 2) {
        setColorA(recentSwatches[recentSwatches.length - 2]);
        setColorB(recentSwatches[recentSwatches.length - 1]);
        }
    }, [recentSwatches, location.search]);

    useEffect(() => {
      if (colorA?.id) {
        loadFriends(colorA.id);
      } else {
        setFriends([]);
      }
    }, [colorA]);

    async function fetchColorById(colorId) {
      const resp = await fetch(`${API_FOLDER}/get-single-color.php?id=${colorId}`);
      const data = await resp.json();
      if (data?.status !== 'success' || !data?.data) return null;
      const c = data.data;
      return {
        id: c.id,
        name: c.name,
        brand: c.brand,
        brand_name: c.brand_name,
        code: c.code,
        r: c.r,
        g: c.g,
        b: c.b,
        hcl_l: c.hcl_l,
        hcl_c: c.hcl_c,
        hcl_h: c.hcl_h,
        hex6: c.hex6,
        cluster_id: c.cluster_id
      };
    }

    useEffect(() => {
      const params = new URLSearchParams(location.search);
      const aId = Number(params.get('a') || 0);
      const bId = Number(params.get('b') || 0);
      let alive = true;
      async function hydrateFromQuery() {
        if (aId && (!colorA || Number(colorA.id) !== aId)) {
          const fetched = await fetchColorById(aId);
          if (alive && fetched) setColorA(fetched);
        }
        if (bId && (!colorB || Number(colorB.id) !== bId)) {
          const fetched = await fetchColorById(bId);
          if (alive && fetched) setColorB(fetched);
        }
      }
      hydrateFromQuery();
      return () => { alive = false; };
      // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [location.search]);

    useEffect(() => {
      const params = new URLSearchParams(location.search);
      if (colorA?.id) params.set('a', colorA.id);
      else params.delete('a');
      if (colorB?.id) params.set('b', colorB.id);
      else params.delete('b');
      const nextSearch = params.toString() ? `?${params.toString()}` : '';
      if (nextSearch === lastSearchRef.current) return;
      lastSearchRef.current = nextSearch;
      const nextUrl = `${location.pathname}${nextSearch}`;
      window.history.replaceState(window.history.state, "", nextUrl);
    }, [colorA?.id, colorB?.id, location.pathname, location.search]);

    const go = (colorid, event) => {
      if (event) {
        event.preventDefault();
        event.stopPropagation();
      }
      if (suppressNavigateRef.current) {
        suppressNavigateRef.current = false;
        return;
      }
      const params = new URLSearchParams();
      if (colorA?.id) params.set('a', colorA.id);
      if (colorB?.id) params.set('b', colorB.id);
      const query = params.toString();
      const returnTo = `/sbs${query ? `?${query}` : ''}`;
      navigate(`/color/${colorid}${query ? `?${query}` : ''}`, {
        state: { returnTo }
      }); // go to detail
    };

    //handlers
    const handleOnSelect = (color) => {
        console.log('newcolor:', color);
      suppressNavigateRef.current = true;
      setTimeout(() => {
        suppressNavigateRef.current = false;
      }, 150);
      if (!colorA) {
        setColorA(color);
      } else if (!colorB) {
        setColorB(color);
      } else {
        setColorB(color);
      }
    }

   
     const handleOnFocus = () => {

     }

   async function handleSubmit() {
  if (!colorA?.id || !colorB?.id) return;

  const formData = new FormData();
  formData.append('color1_id', colorA.id);
  formData.append('color2_id', colorB.id);
  formData.append('source', source);
  formData.append('notes', notes);

  try {
    const res = await fetch(`${API_FOLDER}/enter-relationship.php`, {
      method: 'POST',
      body: formData
    });

    const text = await res.text();
    setMessage(text);

    // ✅ Refresh the list of friends AFTER saving
    loadFriends(colorA.id);

  } catch (err) {
    console.error('Failed to submit relationship', err);
    setMessage('Something went wrong.');
  }
}



    const areExactMatch = colorA && colorB &&
        colorA.r === colorB.r &&
        colorA.g === colorB.g &&
        colorA.b === colorB.b;


return (

  <div className="side-by-side-page">
    <div className="swatch-section">
      <h4 className="page-title">Compare Colors</h4>
      <FuzzySearchColorSelect
        onSelect={handleOnSelect}
        onFocus={handleOnFocus}
        mobileBreakpoint={0}
      />
      <div className="sbs-wrapper admin-compare-wrapper">
            <div className="sbs-comparison-block">
              <div className="sbs-swatch-block">
                <div
                  className={`sbs-swatch${!colorA ? ' placeholder' : ''}`}
                  style={colorA ? { backgroundColor: `rgb(${colorA.r}, ${colorA.g}, ${colorA.b})` } : {}}
                    onClick={colorA ? (e) => go(colorA.id, e) : undefined}
                />
                
                {colorA && (
                  <>
                    <div className="sbs-swatch-label">
                      <div className="sbs-swatch-name">{colorA.name}</div>
                      <div className="sbs-swatch-brand">{colorA.brand_name || colorA.brand}</div>
                    </div>

                
                      <button className="remove-x" onClick={() => setColorA(null)} title="Remove color">
                        ×
                      </button>
                   
                  </>
                )}
                {colorA && colorB && (
                <div className="compare-column">
                      <div className="value-label">Hue: {colorA.hcl_h}°</div>
                      <div className="compare-highlight">
                          {getWarmerColor(colorA.hcl_h, colorB.hcl_h) === 'A' ? 'Warmer' : 'Cooler'}
                      </div>
                      <div className="value-label">Chroma: {colorA.hcl_c}</div>
                      <div className="compare-highlight">
                          {colorA.hcl_c < colorB.hcl_c ? 'Less Color' : 'More Color'}
                      </div>
                      <div className="value-label">Lightness: {colorA.hcl_l}</div>
                      <div className="compare-highlight">
                          {colorA.hcl_l < colorB.hcl_l ? 'Darker' : 'Lighter'}
                      </div>
                      <div className="value-label">Code:</div>
                      <div className="compare-highlight">{colorA.code}</div>
                  
                        <div className="value-label" >Hex: #{colorA.hex6}</div>
                              <div className="value-label" >id: {colorA.id}</div>
                      </div>
                )}
              </div>

              <div className="sbs-swatch-block">
                <div
                  className={`sbs-swatch${!colorB ? ' placeholder' : ''}`}
                  style={colorB ? { backgroundColor: `rgb(${colorB.r}, ${colorB.g}, ${colorB.b})` } : {}}
                  onClick={colorB ? (e) => go(colorB.id, e) : undefined}
                />
                
                {colorB && (
                  <>
                    <div className="sbs-swatch-label">
                      <div className="sbs-swatch-name">{colorB.name}</div>
                      <div className="sbs-swatch-brand">{colorB.brand_name || colorB.brand}</div>
                    </div>

                   
                      <button className="remove-x" onClick={() => setColorB(null)} title="Remove color">
                        ×
                      </button>
                    
                  </>
                )}




                {colorA && colorB && (

              
                <div className="compare-column">
                      <div className="value-label">Hue: {colorB.hcl_h}°</div>
                      <div className="compare-highlight">
                          {getWarmerColor(colorA.hcl_h, colorB.hcl_h) === 'B' ? 'Warmer' : 'Cooler'}
                      </div>
                      <div className="value-label">Chroma: {colorB.hcl_c}</div>
                      <div className="compare-highlight">
                          {colorB.hcl_c < colorA.hcl_c ? 'Less Color' : 'More Color'}
                      </div>
                      <div className="value-label">Lightness: {colorB.hcl_l}</div>
                      <div className="compare-highlight">
                          {colorB.hcl_l < colorA.hcl_l ? 'Darker' : 'Lighter'}
                      </div>
                      <div className="value-label">Code:</div>
                      <div className="compare-highlight">{colorB.code}</div>
      
                      <div className="value-label">Hex: #{colorB.hex6}</div>
                      <div className="value-label" >id: {colorB.id}</div>
                     
              </div>
                )}
              </div>
            </div>






      </div>

  </div>

  </div>
);

  
}

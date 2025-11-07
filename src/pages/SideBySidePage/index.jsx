// SideBySidePage.jsx
import { useEffect, useState } from 'react';
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
        if (recentSwatches.length >= 2) {
        setColorA(recentSwatches[recentSwatches.length - 2]);
        setColorB(recentSwatches[recentSwatches.length - 1]);
        }
    }, [recentSwatches]);

    useEffect(() => {
      if (colorA?.id) {
        loadFriends(colorA.id);
      } else {
        setFriends([]);
      }
    }, [colorA]);



    //handlers
    const handleOnSelect = (color) => {
        console.log('newcolor:', color);
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
      <FuzzySearchColorSelect onSelect={handleOnSelect} onFocus={handleOnFocus} />
      <div className="sbs-wrapper admin-compare-wrapper">
            <div className="sbs-comparison-block">
              <div className="sbs-swatch-block">
                <div
                  className={`sbs-swatch${!colorA ? ' placeholder' : ''}`}
                  style={colorA ? { backgroundColor: `rgb(${colorA.r}, ${colorA.g}, ${colorA.b})` } : {}}
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
                        <div className="value-label" >{colorA.id}</div>
                          <div className="value-label" >{colorA.hex6}</div>
                      </div>
                )}
              </div>

              <div className="sbs-swatch-block">
                <div
                  className={`sbs-swatch${!colorB ? ' placeholder' : ''}`}
                  style={colorB ? { backgroundColor: `rgb(${colorB.r}, ${colorB.g}, ${colorB.b})` } : {}}
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
                      <div className="value-label" >{colorB.id}</div>
                      <div className="value-label" >{colorB.hex6}</div>
              </div>
                )}
              </div>
            </div>






      </div>

  </div>

  </div>
);

  
}
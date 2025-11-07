import './palettedisplay.css';
import { useNavigate } from 'react-router-dom';

// 🛡️ Global click shield helpers
window.__clickShieldUntil = 0;
window.armClickShield = (ms = 450) => { window.__clickShieldUntil = Date.now() + ms; };

const PaletteSwatch = ({ color }) => {
  const navigate = useNavigate();

  function getTextColor(hcl_l) {
    return (hcl_l > 70 ? 'black' : 'white');
  }

  function handleClick(e) {
    // Ignore clicks if we're inside the shield time window
    if (Date.now() < (window.__clickShieldUntil || 0)) {
      console.log('NAV swallowed due to shield');
      return;
    }
    if (e?.target?.closest?.('.no-nav')) return;
    console.log('NAV click', color.id);
    navigate(`/color/${color.id}`);
  }

  return (
    <div
      key={color.id}
      className="ps-swatch-item"
      style={{
        backgroundColor: `rgb(${color.r}, ${color.g}, ${color.b})`,
        color: getTextColor(color.hcl_l)
      }}
      onClick={handleClick}
    >
      <div className="ps-swatch-label">
        <div className="ps-swatch-name">{color.name}</div>
        <div className="ps-swatch-code-full">
          {`${color.brand_name} • ${color.code} • H: ${
            typeof color.hcl_h === 'number' ? color.hcl_h.toFixed(0) : '–'
          }`}
        </div>
        <div className="ps-swatch-code-abbr">
          {`${color.brand} • ${color.code} • H: ${
            typeof color.hcl_h === 'number' ? color.hcl_h.toFixed(0) : '–'
          }`}
        </div>
      </div>
    </div>
  );
};

export default PaletteSwatch;

import colorfixLightBgUrl from "../../assets/brand/colorfix_lightbg.png";
import {buildResultsUrl} from '@helpers/routingHelper';
import { PaletteOutlineIcon } from '../Icons/PaletteIcons';
import { useLocation, useNavigate } from 'react-router-dom';

function FilterIcon(props) {
  return (
    <svg viewBox="0 0 24 24" aria-hidden="true" {...props}>
      <path
        d="M3 5h18l-7 8v5l-4 2v-7L3 5z"
        fill="none"
        stroke="currentColor"
        strokeLinecap="round"
        strokeLinejoin="round"
        strokeWidth="1.8"
      />
    </svg>
  );
}

function getDisplayIcon(display) {
  const key = String(display || '').trim().toLowerCase();
  if (key === 'my palette') return 'palette';
  if (key === 'filter by brand') return 'filter';
  return '';
}

const SearchItem = ({ item }) => {
  const navigate = useNavigate();
  const location = useLocation();
  const isColorFixBrand = String(item?.display || '').trim().toLowerCase() === 'colorfix';
  const displayIcon = getDisplayIcon(item?.display);

  const goToTarget = (url) => {
    const target = String(url || '').trim();
    if (!target) return;
    const isAdminRoute = location.pathname === '/admin' || location.pathname.startsWith('/admin/');
    const isPublicAbsolutePath = target.startsWith('/') && !target.startsWith('/admin');
    navigate(target);
  };


  const handleClick = () => {
 // console.log("📦 handleClick triggered:", item);

  if ('on_click_query' in item && Number.isInteger(Number(item.on_click_query))) {
    const queryId = Number(item.on_click_query);
   // console.log("🔁 Navigating to query:", queryId);
    goToTarget(buildResultsUrl(queryId, item.on_click_params));
  } else if (item.on_click_url) {
   // console.log("🌐 Navigating to on_click_url:", item.on_click_url);
    goToTarget(item.on_click_url);
  } else if (item.target_url) {
   // console.log("🎯 Navigating to target_url:", item.target_url);
    goToTarget(item.target_url);
  } else {
    console.warn("⚠️ No navigation target for item:", item);
  }
};



  return (
    <div
      key={item.id}
      className="search-item item"
      onClick={handleClick}
    >
      <div>
        <div className='search-display'>
          {isColorFixBrand ? (
            <img src={colorfixLightBgUrl} alt="ColorFix" className="search-display__brand-image" />
          ) : displayIcon ? (
            <span className="search-display__with-icon">
              <span>{item.display}</span>
              <span className="search-display__icon" aria-hidden="true">
                {displayIcon === 'palette' ? (
                  <PaletteOutlineIcon />
                ) : (
                  <FilterIcon />
                )}
              </span>
            </span>
          ) : (
            item.display
          )}
        </div>
        <div className='search-descr'>{item.description}</div>
      </div>
    </div>
  );
};

export default SearchItem;

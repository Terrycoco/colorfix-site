import colorfixLightBgUrl from "../../assets/brand/colorfix_lightbg.png";
import {buildResultsUrl} from '@helpers/routingHelper';
import { useLocation, useNavigate } from 'react-router-dom';

const SearchItem = ({ item }) => {
  const navigate = useNavigate();
  const location = useLocation();
  const isColorFixBrand = String(item?.display || '').trim().toLowerCase() === 'colorfix';

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

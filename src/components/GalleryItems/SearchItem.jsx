import {buildResultsUrl} from '@helpers/routingHelper';
import { useNavigate } from 'react-router-dom';

const SearchItem = ({ item }) => {
  const navigate = useNavigate();



  const handleClick = () => {
 // console.log("📦 handleClick triggered:", item);

  if ('on_click_query' in item && Number.isInteger(Number(item.on_click_query))) {
    const queryId = Number(item.on_click_query);
   // console.log("🔁 Navigating to query:", queryId);
    navigate(buildResultsUrl(queryId, item.on_click_params));
  } else if (item.on_click_url) {
   // console.log("🌐 Navigating to on_click_url:", item.on_click_url);
    navigate(item.on_click_url);
  } else if (item.target_url) {
   // console.log("🎯 Navigating to target_url:", item.target_url);
    navigate(item.target_url);
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
        <div className='search-display'>{item.display}</div>
        <div className='search-descr'>{item.description}</div>
      </div>
    </div>
  );
};

export default SearchItem;
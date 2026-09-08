import { useEffect, useState } from 'react';
import { useLocation, useNavigate } from 'react-router-dom';
import { API_FOLDER } from '@helpers/config';
import GalleryItem from '@components/Gallery/GalleryItem';
import TopSpacer from '@layout/TopSpacer';
import { resolveAppPath } from '@helpers/routingHelper';
import './searchpage.css';

const SearchPage = () => {
  const [searchOptions, setSearchOptions] = useState([]);
  const navigate = useNavigate();
  const location = useLocation();

  useEffect(() => {
    const fetchSearchPresets = async () => {
      const res = await fetch(`${API_FOLDER}/get-search-presets.php`);
      const data = await res.json();
      setSearchOptions(data);
    };

    fetchSearchPresets();
  }, []);

  return (
   <div className='searchpage'>
    <TopSpacer />
    <div className="search-options">
     
      {searchOptions.map((option) => (
        <GalleryItem
          key={option.id}
          item={option}
          onClick={() => navigate(resolveAppPath(`/results/${option.id}`, location.pathname))}
        />
      ))}
    </div>
 </div>
  );
};

export default SearchPage;

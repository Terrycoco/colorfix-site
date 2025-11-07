import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { API_FOLDER } from '@helpers/config';
import GalleryItem from '@components/Gallery/GalleryItem';
import TopSpacer from '@layout/TopSpacer';
import './searchpage.css';

const SearchPage = () => {
  const [searchOptions, setSearchOptions] = useState([]);
  const navigate = useNavigate();

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
          onClick={() => navigate(`/results/${option.id}`)}
        />
      ))}
    </div>
 </div>
  );
};

export default SearchPage;

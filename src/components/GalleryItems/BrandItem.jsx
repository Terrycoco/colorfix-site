import colorfixLightBgUrl from "../../assets/brand/colorfix_lightbg.png";
import { useNavigate } from 'react-router-dom';

const BrandItem = ({ item }) => {
  const navigate = useNavigate();
  const isColorFixBrand = String(item?.display || '').trim().toLowerCase() === 'colorfix';

  const handleClick = () => {
     navigate('.', { state: { openBrandFilter: true } });
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

export default BrandItem;

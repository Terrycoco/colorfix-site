import colorfixLightBgUrl from "../../assets/brand/colorfix_lightbg.png";
import { useNavigate } from 'react-router-dom';

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
            <span className="search-display__with-icon">
              <span>{item.display}</span>
              <span className="search-display__icon" aria-hidden="true">
                <FilterIcon />
              </span>
            </span>
          )}
        </div>
        <div className='search-descr'>{item.description}</div>
      </div>
    </div>
  );
};

export default BrandItem;

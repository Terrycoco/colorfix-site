
import { useNavigate } from 'react-router-dom';

const BrandItem = ({ item }) => {
  const navigate = useNavigate();



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
        <div className='search-display'>{item.display}</div>
        <div className='search-descr'>{item.description}</div>
      </div>
    </div>
  );
};

export default BrandItem;
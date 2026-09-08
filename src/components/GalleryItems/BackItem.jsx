// components/items/BackItem.jsx
import { useLocation, useNavigate } from 'react-router-dom';
import useCanGoBack from '@hooks/useCanGoBack';
import { isAdminPath, resolveAppPath } from '@helpers/routingHelper';

const BackItem = ({ item }) => {
  const navigate = useNavigate();
  const location = useLocation();
  const canGoBack = useCanGoBack();

  const handleClick = () => {
    if (canGoBack) {
      navigate(-1);
      return;
    }
    const fallback = item?.target_url || (isAdminPath(location.pathname) ? "/admin/" : "/");
    navigate(resolveAppPath(fallback, location.pathname));
  };

  return (
    <div
      className="back-item item"
      onClick={handleClick}
      style={{
        cursor: 'pointer',
        fontSize: '1rem',
        fontWeight: 'bold',
        display: 'inline-block',
        border: 'none',
        padding: '2px, 5px'
      }}
    >
      ← Back
    </div>
  );
};

export default BackItem;

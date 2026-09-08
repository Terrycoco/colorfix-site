import { useLocation, useNavigate } from 'react-router-dom';
import {useAppState} from '@context/AppStateContext';
import { resolveAppPath } from '@helpers/routingHelper';

const ButtonItem = ({ item }) => {
  const navigate = useNavigate();
  const location = useLocation();
  const { loggedIn } = useAppState();

  // normalize and detect the login button
  const isLoginTarget =
    (item?.target_url || '').replace(/\/+$/, '') === '/login';

  // 🔒 hide the Login button when already logged in
  if (loggedIn && isLoginTarget) return null;

  const handleClick = () => {
    if (item?.target_url) {
      navigate(resolveAppPath(item.target_url, location.pathname));
    }
  };

  return (
    <div
      key={item.id}
      className="button-item item"
      onClick={handleClick}
    >
      <div>
        <div className='button-display'>{item.display}</div>
        <div className='search-descr'>{item.description}</div>
      </div>
    </div>
  );
};

export default ButtonItem;

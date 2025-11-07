import { useLocation, Link, useNavigate } from 'react-router-dom';
import { useAppState } from '@context/AppStateContext';
import './boardscroller.css';

const BoardScrollerTrail = ({ boards }) => {
  const location = useLocation();
  const navigate = useNavigate();


  return (
    <div className="board-scroller">
    
    
    </div>
  );
};

export default BoardScrollerTrail;

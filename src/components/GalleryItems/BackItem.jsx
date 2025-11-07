// components/items/BackItem.jsx
import { useNavigate } from 'react-router-dom';

const BackItem = () => {
  const navigate = useNavigate();

  return (
    <div
      className="back-item item"
      onClick={() => navigate(-1)}
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

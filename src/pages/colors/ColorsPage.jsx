import { useEffect, useState } from 'react';
import SwatchCard from '@components/SwatchCard';

function ColorsPage() {
  const [colors, setColors] = useState([]);

  useEffect(() => {
    fetch('/api/get-colors.php')  // adjust path to match your structure
      .then(res => res.json())
      .then(data => setColors(data))
      .catch(err => console.error('Error loading colors:', err));
  }, []);

  return (
    <div className="swatch-grid">
      {colors.map(color => (
        <SwatchCard key={color.id} color={color} />
      ))}
    </div>
  );
}

export default ColorsPage;

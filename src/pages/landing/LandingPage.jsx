
import { useAppState } from '@context/AppStateContext';
import {useState, useEffect} from 'react';
import taglines from '@helpers/taglines';

function LandingPage() {
  const { user } = useAppState();
  const [tagline, setTagline] = useState('');

  useEffect(() => {
    const randomIndex = Math.floor(Math.random() * taglines.length);
    setTagline(taglines[randomIndex]);
  }, []);


  return (
    <div className="landing">

      <div className="headline">
        {user && <p className="greeting">Hi, {user.firstname}</p>}
        <h2>Welcome to <span className="brand">ColorFix!</span></h2>
      </div>
      <div className='tagline'>{tagline}</div>

    </div>
  );
}

export default LandingPage;

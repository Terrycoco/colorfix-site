import { useState } from 'react';
import { useAppState} from '@context/AppStateContext';
import {API_FOLDER} from '@helpers/config';

export default function CategoryRecalcButton() {
  const {refreshCategories, setColors, setMessage} = useAppState(); //smart button
 console.log('ENV full dump:', import.meta.env);
 console.log('apifolder:', API_FOLDER);

  const handleClick = async () => {
    setMessage('working...');
    try {
      const res = await fetch(`${API_FOLDER}/assign-categories.php`); //<---- recalculate everything
     const data = await res.json();

       if (data.status === 'success') {
         setMessage('Reassign complete');

          // ✅ Re-fetch categories
        refreshCategories();  ///<---- calling it here. 

          // ✅ Re-fetch colors
         setMessage('Success!');

       } else {
         setMessage('Error:' + data.message);
       }
    } catch (err) {
        console.error('Failed to reassign:', err);
        setMessage('Error: ' + err.message);   //<--------not valid JSON error
    }
    };

  return (
    <div className="my-2">
      <button
        onClick={handleClick}
        className="px-2 py-1 text-xs text-white rounded "
      >
        Assign Categories
      </button>

    </div>
  );
}

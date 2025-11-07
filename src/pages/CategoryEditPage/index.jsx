import {useState, useEffect, useRef} from 'react';
import {useAppState} from '@context/AppStateContext';
import {isAdmin} from '@helpers/authHelper';
import CategoryList from '@components/CategoryList';
import ResponsiveRow from '@layout/ResponsiveRow';
import ColorWheel300 from '@components/ColorWheel/ColorWheel300';
import ColorWheelIndicator from '@components/ColorWheel/ColorWheelIndicator';
import Column from '@layout/Column';
import fetchAllCategories from '@data/fetchAllCategories';
import RecalcCatsButton from './RecalcCatsButton';
import './cateditpage.css';


export default function CategoryEditPage() {
    const {selectedCategory, refreshCategories} = useAppState();
    const categoryListRef = useRef();




   if (!isAdmin()) return <p>You must be an Admin to view this page.</p>;


  
 





    return (
    <>
     <ResponsiveRow align="center">
      <Column>
           <p className="font-bold">{selectedCategory?.name}</p>
      <ColorWheel300 currentColor={null}>
        {selectedCategory?.hue_min && (
            [<ColorWheelIndicator
            key={selectedCategory?.id}
            hue={(selectedCategory?.hue_min)}
            center={150}
            radius={150}
            strokeColor="black"
            dashed={true}
          />,
           <ColorWheelIndicator
            key={selectedCategory?.id + 'end'}
            hue={(selectedCategory?.hue_max)}
            center={150}
            radius={150}
            strokeColor="black"
            dashed={true}
          />])}
      </ColorWheel300>
   </Column>
      <Column>
  
       <RecalcCatsButton />
       <button
          onClick={() => categoryListRef.current?.addBlankRow()}
          className="mt-4 px-3 py-1 bg-blue-500 text-white rounded hover:bg-blue-600 text-sm"
        >
          + New Category
        </button>
</Column>
   </ResponsiveRow>

   <ResponsiveRow>
   
     <CategoryList ref={categoryListRef}/>
    
    </ResponsiveRow>
    </>
    );

}
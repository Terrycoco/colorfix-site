import {useEffect} from 'react';
import { useAppState } from '@context/AppStateContext';
import {isAdmin} from '@helpers/authHelper';
import PageDashboard from '@components/PageDashboard';
import AdminDashboard from '@components/AdminDashboard';
import {FlexRow, FlexCol} from '@components/FlexBox';
import SearchColor from '@components/SearchColor';
import ColorForm from '@components/ColorForm';
import SwatchCard from '@components/SwatchCard';
import ColorWheel from '@components/ColorWheel';
import './admin.css';


export default function AdminPage() {
  const { isLoggedIn, user, currentColor, setColors, colors } = useAppState();



  const isTerry = () => {
      isLoggedIn && isAdmin(user);
  }
  


 return (
  <>
    {isTerry ? (
      <>
        <PageDashboard sticky={true}>
          <FlexRow>
            <label>Search Color</label>
            <SearchColor />
          </FlexRow>
        </PageDashboard>

        <div className="admin-container">
          <div className="admin-left">
             <ColorForm />
          </div>
          <div className="admin-middle">
             <SwatchCard color={currentColor } />
          </div>
          <div className="admin-right">
            {currentColor ?  <p className='color-cats'>{currentColor.hcl_h}&deg;&nbsp;&mdash; {currentColor.categories}</p> : null}
            <ColorWheel width='400' currentColor={currentColor}/>
          </div>
        </div>
      </>
    ) : (
      <p>Access denied. This page is for Terry only.</p>
    )}
  </>
);

}

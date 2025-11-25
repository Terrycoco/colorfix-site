// src/App.jsx
import { useState, useRef, useEffect } from 'react';
import { Outlet } from 'react-router-dom';
import { useAppState } from '@context/AppStateContext';
import AppLayout from '@layout/AppLayout';
import BoardScroller from '@components/BoardScroller';
import MessagePopup from '@components/MessagePopup';
import { isAdmin } from '@helpers/authHelper';

export default function App() {
  const { boards } = useAppState();
  const [showAdminMenu, setShowAdminMenu] = useState(false);
  const adminMenuRef = useRef(null);
  const admin = isAdmin();

  useEffect(() => {
    function handleClickOutside(e) {
      if (adminMenuRef.current && !adminMenuRef.current.contains(e.target)) {
        setShowAdminMenu(false);
      }
    }
    if (showAdminMenu) document.addEventListener('mousedown', handleClickOutside);
    return () => document.removeEventListener('mousedown', handleClickOutside);
  }, [showAdminMenu]);

  return (
    <AppLayout>
      <BoardScroller boards={boards} />
      <MessagePopup />

      {/* MainLayout/AdminLayout + pages render here via Router */}
      <Outlet />

      {admin && (
        <>
          <button
            className="fixed bottom-4 right-4 hidden lg:flex items-center justify-center w-12 h-12 bg-blue-600 text-white rounded-full shadow-lg hover:bg-blue-700 z-5000"
            onClick={() => setShowAdminMenu(!showAdminMenu)}
            aria-label="Toggle Admin Menu"
          >
            ⚙️
          </button>

          {showAdminMenu && (
            <div
              ref={adminMenuRef}
              className="fixed bottom-20 right-4 bg-white shadow-lg rounded-md p-4 w-60 z-5000"
            >
              <h3 className="font-semibold mb-2">Admin Tools</h3>
              <ul>
                <li><a href="/admin/colors" className="block py-1 hover:underline">Colors</a></li>
                <li><a href="/admin/categories" className="block py-1 hover:underline">Categories</a></li>
                <li><a href="/admin/supercats" className="block py-1 hover:underline">Supercats</a></li>
                <li><a href="/admin/roles-masks" className="block py-1 hover:underline">Roles/Masks</a></li>
          
                <li><a href="/admin/search-presets" className="block py-1 hover:underline">Search Presets</a></li>
                <li><a href="/admin/sql" className="block py-1 hover:underline">SQL Builder</a></li>
                <li><a href="/admin/items" className="block py-1 hover:underline">Items</a></li>
                <li><a href="/admin/filters" className="block py-1 hover:underline">Filters</a></li>
                <li><a href="/admin/friends" className="block py-1 hover:underline">Friends</a></li>
                <li><a href="/admin/saved-palettes" className="block py-1 hover:underline">Saved Palettes</a></li>
                 <li><a href="/admin/missing-chips" className="block py-1 hover:underline">Missing Chips</a></li>
                <li><a href="/admin/lrv-editor" className="block py-1 hover:underline">LRV Editor</a></li>
                  <li><a href="/admin/upload-photo" className="block py-1 hover:underline">Upload Photos</a></li>
                    <li><a href="/admin/photo-preview" className="block py-1 hover:underline">Photo Preview</a></li>
                 
              </ul>
            </div>
          )}
        </>
      )}
    </AppLayout>
  );
}

// src/App.jsx
import { useEffect } from 'react';
import { Outlet } from 'react-router-dom';
import { useAppState } from '@context/AppStateContext';
import AppLayout from '@layout/AppLayout';
import BoardScroller from '@components/BoardScroller';
import MessagePopup from '@components/MessagePopup';
import { ensureViewerId } from "./lib/viewer";

export default function App() {
  const { boards } = useAppState();

  // on load plant/retrieve a viewer_id
  useEffect(() => {
    ensureViewerId();
  }, []);

  return (
    <AppLayout>
      <BoardScroller boards={boards} />
      <MessagePopup />

      {/* MainLayout/AdminLayout + pages render here via Router */}
      <Outlet />
    </AppLayout>
  );
}

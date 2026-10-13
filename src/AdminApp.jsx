import { useEffect } from 'react';
import AppLayout from '@layout/AppLayout';
import BoardScroller from '@components/BoardScroller';
import MessagePopup from '@components/MessagePopup';
import ErrStatusDialog from '@components/AdminLayout/ErrStatusDialog';
import AdminRoutes from './routes/AdminRoutes.jsx';
import { ensureViewerId } from './lib/viewer';

export default function AdminApp() {
  useEffect(() => {
    ensureViewerId();
  }, []);

  return (
    <AppLayout>
      <BoardScroller adminContext />
      <MessagePopup />
      <ErrStatusDialog />
      <AdminRoutes />
    </AppLayout>
  );
}
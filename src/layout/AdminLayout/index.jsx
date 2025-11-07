// layouts/AdminLayout.jsx
import './adminlayout.css';
import { Outlet } from "react-router-dom";
export default function AdminLayout() {
  return (
    <main className="admin-layout layout--no-gutters">
      <Outlet />
    </main>
  );
}
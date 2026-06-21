// src/layout/MainLayout.jsx
import './mainlayout.css';
import {Outlet} from 'react-router-dom';

export default function MainLayout() {
  return (
    <>
      <main className="main-layout"><Outlet /></main>
      <footer className="site-footer">ColorFix by Terry — home color transformations by Terry Marr</footer>
    </>
  );
}

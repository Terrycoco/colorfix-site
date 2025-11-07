// src/layout/MainLayout.jsx
import './mainlayout.css';
import {Outlet} from 'react-router-dom';

export default function MainLayout() {
  return <main className="main-layout"><Outlet /></main>;
}

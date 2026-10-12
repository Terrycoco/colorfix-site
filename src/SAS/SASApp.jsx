import { BrowserRouter, Link, Navigate, Route, Routes } from 'react-router-dom';
import Photos from './components/Photos';
import QuickSend from './components/QuickSend';
import { Images, ListVideo, PanelsTopLeft, Compass, Send } from 'lucide-react';

function Home() {
  return <main className="sas-content">
    <h1>Seek &amp; Send</h1>
    <div className="sas-tiles">
      <Link className="sas-tile sas-tile--photos" to="/photos"><Images aria-hidden="true"/><span>Photos</span></Link>
      <Link className="sas-tile sas-tile--quick" to="/quick-send"><Send aria-hidden="true"/><span>Quick Send</span></Link>
      <button className="sas-tile" type="button" disabled><ListVideo aria-hidden="true"/><span>Playlists</span></button>
      <button className="sas-tile" type="button" disabled><Compass aria-hidden="true"/><span>Browse Playlists</span></button>
      <button className="sas-tile" type="button" disabled><PanelsTopLeft aria-hidden="true"/><span>ColorFix Pages</span></button>
    </div>
  </main>;
}
export default function SASApp() {
  return <BrowserRouter basename="/sas"><div className="sas-app">
    <header className="sas-header"><Link to="/" className="sas-brand" aria-label="ColorFix SAS home"><img src="/brand/colorfix-by-terry-frontpage-logo-white-color.png" alt="ColorFix by Terry"/></Link><span className="sas-name">SAS</span></header>
    <Routes><Route path="/" element={<Home/>}/><Route path="/photos" element={<Photos/>}/><Route path="/quick-send" element={<QuickSend/>}/><Route path="*" element={<Navigate to="/" replace/>}/></Routes>
  </div></BrowserRouter>;
}

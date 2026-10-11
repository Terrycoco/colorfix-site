import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import SASApp from './SASApp.jsx';
import './sas.css';

createRoot(document.getElementById('sas-root')).render(
  <StrictMode><SASApp /></StrictMode>,
);

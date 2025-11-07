import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import { AppStateProvider } from '@context/AppStateContext.jsx';
import AppRouter from './Router.jsx'; // this contains <App />


import '@styles/global.css';    // optional
import '@styles/reset.css';     // optional
import '@styles/typography.css';  //font styling 
import '@styles/named.css';

createRoot(document.getElementById('root')).render(
  <StrictMode>
    <AppStateProvider> <AppRouter /></AppStateProvider>
   
  </StrictMode>
)

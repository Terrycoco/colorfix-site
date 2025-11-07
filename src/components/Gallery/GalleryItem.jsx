import React from 'react';
import './gallery.css';

const GalleryItem = ({ children }) => {
  return (
  <div className="gallery-item" >
     {children}
  </div>
  );
};

export default GalleryItem;

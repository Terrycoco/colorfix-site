import Masonry from 'react-masonry-css';
import './gallery.css';

const breakpointColumnsObj = {
  default: 4,
  1200: 4,
  800: 3,
  500: 2,
};

const GalleryGrid = ({ children }) => {
  return (
    <div className="gallery-grid-wrap">
    <Masonry
      breakpointCols={breakpointColumnsObj}
      className="gallery-masonry"
      columnClassName="gallery-column"
    >
      {children}
    </Masonry>
    </div>
  );
};

export default GalleryGrid;

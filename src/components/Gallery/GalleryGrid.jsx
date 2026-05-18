import Masonry from 'react-masonry-css';
import './gallery.css';

const defaultBreakpointColumnsObj = {
  default: 4,
  1200: 4,
  800: 3,
  500: 2,
};

const GalleryGrid = ({ children, breakpointCols = defaultBreakpointColumnsObj }) => {
  return (
    <div className="gallery-grid-wrap">
    <Masonry
      breakpointCols={breakpointCols}
      className="gallery-masonry"
      columnClassName="gallery-column"
    >
      {children}
    </Masonry>
    </div>
  );
};

export default GalleryGrid;

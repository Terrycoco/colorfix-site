import AdminFullScreenOverlay from "@components/AdminLayout/AdminFullScreenOverlay";
import "./photo-preview.css";

export default function PhotoPreview({ src = "", alt = "", onClose }) {
  return (
    <AdminFullScreenOverlay open={Boolean(src)} modal ariaLabel="Photo preview" onClose={onClose} className="photo-preview">
      <button type="button" className="photo-preview__close" aria-label="Close photo preview"
        onPointerDown={event => event.stopPropagation()}
        onDoubleClick={event => event.stopPropagation()}
        onKeyDown={event => event.stopPropagation()}
        onKeyUp={event => event.stopPropagation()}
        onClick={event => { event.stopPropagation(); onClose?.(); }}>
        <img src={src} alt={alt} />
      </button>
    </AdminFullScreenOverlay>
  );
}

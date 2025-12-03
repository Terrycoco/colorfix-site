import PropTypes from "prop-types";
import "./maskoverlaymodal.css";

export default function MaskOverlayModal({ title, subtitle, onClose, children }) {
  return (
    <div className="mask-overlay-modal" role="dialog" aria-modal="true">
      <div className="mask-overlay-modal__backdrop" onClick={onClose} />
      <div className="mask-overlay-modal__panel">
        <div className="mask-overlay-modal__header">
          <div className="mask-overlay-modal__title">
            {title}
            {subtitle ? <span className="mask-overlay-modal__subtitle"> · {subtitle}</span> : null}
          </div>
          <button
            type="button"
            className="mask-overlay-modal__close"
            aria-label="Close"
            onClick={onClose}
          >
            ×
          </button>
        </div>
        <div className="mask-overlay-modal__content">{children}</div>
      </div>
    </div>
  );
}

MaskOverlayModal.propTypes = {
  title: PropTypes.string.isRequired,
  subtitle: PropTypes.string,
  onClose: PropTypes.func,
  children: PropTypes.node.isRequired,
};

MaskOverlayModal.defaultProps = {
  subtitle: null,
  onClose: undefined,
};

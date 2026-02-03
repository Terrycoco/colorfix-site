// PictureSwatch.jsx
import { useNavigate } from "react-router-dom";
import "./swatches.css";

export default function PictureSwatch({
  photoUrl,
  name,
  meta,
  to,
  onClick,
  widthPercent = 20,
}) {
  const navigate = useNavigate();

  const go = () => {
    if (onClick) {
      onClick();
      return;
    }
    if (to) {
      navigate(to);
    }
  };

  return (
    <div
      className="pals-swatch pals-photo"
      style={{ "--pals-width": `${widthPercent}%` }}
      onClick={go}
      role={to || onClick ? "button" : undefined}
      tabIndex={to || onClick ? 0 : undefined}
      onKeyDown={(e) => {
        if (!to && !onClick) return;
        if (e.key === "Enter" || e.key === " ") {
          e.preventDefault();
          go();
        }
      }}
    >
      <div className="pals-fill pals-photo-fill">
        {photoUrl && (
          <img
            className="pals-photo-img"
            src={photoUrl}
            alt={name || "Palette example"}
            loading="lazy"
          />
        )}
      </div>
    </div>
  );
}

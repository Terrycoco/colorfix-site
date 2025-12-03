// RolesBar.jsx
import PropTypes from "prop-types";
import EditableSwatch from "@components/EditableSwatch";
import "./rolesbar.css";

/** Dumb strip of Body / Trim / Accent role controls. */
export default function RolesBar({
  values,
  onRoleChange,
  size = "sm",
  showNames = true,
  showLightness = false,
  disabled = false,
  readOnly = false,
  className = "",
}) {
  return (
    <div className={`roles-bar ${className}`}>
      <EditableSwatch
        label="Body"
        value={values.body || null}
        onChange={(sw) => onRoleChange("body", sw)}
        size={size}
        showName={showNames}
        showLightness={showLightness}
        disabled={disabled}
        readOnly={readOnly}
      />
      <EditableSwatch
        label="Trim"
        value={values.trim || null}
        onChange={(sw) => onRoleChange("trim", sw)}
        size={size}
        showName={showNames}
        showLightness={showLightness}
        disabled={disabled}
        readOnly={readOnly}
      />
      <EditableSwatch
        label="Accent"
        value={values.accent || null}
        onChange={(sw) => onRoleChange("accent", sw)}
        size={size}
        showName={showNames}
        showLightness={showLightness}
        disabled={disabled}
        readOnly={readOnly}
      />
    </div>
  );
}

RolesBar.propTypes = {
  values: PropTypes.shape({
    body: PropTypes.object,
    trim: PropTypes.object,
    accent: PropTypes.object,
  }).isRequired,
  onRoleChange: PropTypes.func.isRequired,
  size: PropTypes.oneOf(["xs", "sm", "md"]),
  showNames: PropTypes.bool,
  showLightness: PropTypes.bool,
  disabled: PropTypes.bool,
  readOnly: PropTypes.bool,
  className: PropTypes.string,
};

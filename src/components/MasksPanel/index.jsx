import FuzzySearchColorSelect from '@components/FuzzySearchColorSelect';
import './maskspanel.css';


const GROUP_ORDER = ['body', 'trim', 'accent'];

export default function MasksPanel({
  masks = [],
  roleGroups = {},
  overrides = {},
  maskToRoleGroup,
  onSelect,
  onRevert,
  onEditOverlay,
}) {
  const pickLightness = (swatch) => {
    if (!swatch) return null;
    const raw =
      swatch.lightness ??
      swatch.lab_l ??
      swatch.hcl_l ??
      swatch.L ??
      null;
    const num = raw != null ? Number(raw) : null;
    return Number.isFinite(num) ? num : null;
  };

  const orderedMasks = [...masks].sort((a, b) => {
    const ga = GROUP_ORDER.indexOf(maskToRoleGroup(a.role));
    const gb = GROUP_ORDER.indexOf(maskToRoleGroup(b.role));
    return (ga === -1 ? 99 : ga) - (gb === -1 ? 99 : gb);
  });

  return (
    <div className="masks-panel">
      <div className="panel-title">Parts (Masks)</div>

      {orderedMasks.map(({ role: mask }) => {
        const group = maskToRoleGroup(mask);
        const roleValue = roleGroups[group];
        const hasOverride = Object.prototype.hasOwnProperty.call(overrides, mask);
        const overrideValue = hasOverride ? overrides[mask] : null;
        const isSkipOverride = !!(overrideValue && overrideValue.__skip);
        const value = hasOverride ? (isSkipOverride ? null : overrideValue) : null;
        const ghostValue = hasOverride && !isSkipOverride ? null : roleValue || null;
        const lightnessVal =
          pickLightness(value) ??
          (isSkipOverride ? pickLightness(roleValue) : pickLightness(ghostValue));
        const bucket =
          lightnessVal == null
            ? ""
            : lightnessVal < 45
            ? "Dark"
            : lightnessVal >= 88
            ? "Light"
            : "Medium";

        return (
          <div className="mask-row" key={mask}>
            <FuzzySearchColorSelect
              label={mask}
              value={value}
              ghostValue={ghostValue}
              detail="swatch"
              onSelect={(sw) => onSelect(mask, sw)}
              onEmpty={() => onRevert && onRevert(mask)}
              className="mask-color-picker"
            />
            {isSkipOverride && (
              <div className="mask-row__skip-badge">Original Photo</div>
            )}
            {lightnessVal != null && (
              <div className="mask-lightness">
                L {lightnessVal.toFixed(1)} · {bucket}
              </div>
            )}
            <div
              role="button"
              tabIndex={0}
              className="mask-row__overlay-btn"
              title="Edit blend settings"
              onClick={() => onEditOverlay && onEditOverlay(mask)}
              onKeyDown={(e) => {
                if (e.key === "Enter" || e.key === " ") {
                  e.preventDefault();
                  onEditOverlay && onEditOverlay(mask);
                }
              }}
            >
              <span className="mask-row__overlay-gear" aria-hidden="true" />
            </div>
          </div>
        );
      })}
    </div>
  );
}

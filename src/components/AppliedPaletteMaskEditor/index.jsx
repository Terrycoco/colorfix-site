import PropTypes from "prop-types";
import MaskBlendHistory from "@components/MaskBlendHistory";
import "./applied-palette-mask-editor.css";

export default function AppliedPaletteMaskEditor({
  assetId,
  masks = [],
  entriesMap = {},
  selectorVersion = 0,
  activeColorByMask = {},
  onSelectRow,
  onApplyBlend,
}) {
  const ordered = [...(masks || [])].sort((a, b) =>
    (a?.role || "").localeCompare(b?.role || "")
  );

  const rows = ordered
    .map((mask) => {
      const entry = entriesMap?.[mask.role] || {};
      const colorId = entry?.color?.id || entry?.color_id || null;
      if (!colorId) return null;
      return { mask, colorId };
    })
    .filter(Boolean);

  if (!rows.length) {
    return <div className="ap-mask-editor__empty">No palette colors assigned yet.</div>;
  }

  return (
    <div className="ap-mask-editor">
      {rows.map(({ mask, colorId }) => (
        <div key={mask.role} className="ap-mask-editor__row">
          <MaskBlendHistory
            assetId={assetId}
            maskRole={mask.role}
            baseLightness={mask?.stats?.l_avg01 ?? mask?.base_lightness ?? null}
            selectorVersion={selectorVersion}
            rowTitle={mask.role}
            hideHeader
            activeColorId={activeColorByMask?.[mask.role]}
            onSelectRow={(row) => onSelectRow?.(mask.role, row)}
            onApplyBlend={onApplyBlend}
          />
        </div>
      ))}
    </div>
  );
}

AppliedPaletteMaskEditor.propTypes = {
  assetId: PropTypes.string.isRequired,
  masks: PropTypes.arrayOf(
    PropTypes.shape({
      role: PropTypes.string,
      base_lightness: PropTypes.number,
      stats: PropTypes.object,
    })
  ),
  entriesMap: PropTypes.object,
  selectorVersion: PropTypes.number,
  activeColorByMask: PropTypes.object,
  onSelectRow: PropTypes.func,
  onApplyBlend: PropTypes.func,
};

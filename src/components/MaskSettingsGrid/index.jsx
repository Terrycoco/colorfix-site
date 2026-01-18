import MaskRoleGrid from "@components/MaskRoleGrid";

export default function MaskSettingsGrid({
  masks = [],
  entries = {},
  activeColorByMask = null,
  onChange,
  onApply,
  onShadow,
  showRole = false,
}) {
  return (
    <MaskRoleGrid
      masks={masks}
      entries={entries}
      activeColorByMask={activeColorByMask}
      onChange={onChange}
      onApply={onApply}
      onShadow={onShadow}
      showRole={showRole}
    />
  );
}

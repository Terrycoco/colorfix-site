import MaskRoleGrid from "@components/MaskRoleGrid";

export default function MaskSettingsGrid({
  masks = [],
  entries = {},
  onChange,
  onApply,
  onShadow,
  showRole = false,
}) {
  return (
    <MaskRoleGrid
      masks={masks}
      entries={entries}
      onChange={onChange}
      onApply={onApply}
      onShadow={onShadow}
      showRole={showRole}
    />
  );
}

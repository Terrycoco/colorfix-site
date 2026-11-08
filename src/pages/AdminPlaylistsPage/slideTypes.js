// Display labels only. Keep persisted type values and legacy aliases unchanged.
export const SLIDE_TYPE_OPTIONS = [
  { value: "palette", label: "Palette" },
  { value: "non-palette", label: "Non Palette" },
  { value: "intro", label: "Intro" },
  { value: "text", label: "Text" },
  { value: "hue-wheel", label: "Hue Wheel" },
  { value: "cover-image", label: "Cover Image" },
  { value: "brand-bumper", label: "Brand Bumper" },
];

export function slideTypeLabel(value) {
  if (value === "normal") return "Palette";
  return SLIDE_TYPE_OPTIONS.find((option) => option.value === value)?.label
    || String(value || "—").replace(/[_-]+/g, " ").replace(/\b\w/g, (letter) => letter.toUpperCase());
}

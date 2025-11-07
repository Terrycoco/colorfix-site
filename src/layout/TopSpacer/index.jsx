// TopSpacer.jsx
import { useAppState } from "@context/AppStateContext";

export default function TopSpacer({ disabled = false }) {
  const { showPalette } = useAppState();
  const paletteHeight="70px";  //also in global styles
  const h = !disabled && showPalette ? paletteHeight : 0;
  return (
    <div
      aria-hidden
      style={{
        height: h,
        transition: "height 200ms ease",
        pointerEvents: "none",
      }}
    />
  );
}
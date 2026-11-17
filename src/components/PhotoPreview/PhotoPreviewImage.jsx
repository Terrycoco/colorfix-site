import { useState } from "react";
import PhotoPreview from "./index";

function previewSource(src) {
  if (!src || !String(src).includes("/image-thumb.php?")) return src;
  const url = new URL(src, window.location.origin);
  url.searchParams.set("w", "1920");
  url.searchParams.set("crop_type", "original");
  url.searchParams.set("fit", "inside");
  return url.href;
}

// The image itself is the trigger, so existing list-row buttons and layout stay intact.
export default function PhotoPreviewImage({ previewSrc, src, alt = "", style, ...props }) {
  const [open, setOpen] = useState(false);
  const stop = event => event.stopPropagation();
  const show = event => {
    event.preventDefault();
    event.stopPropagation();
    setOpen(true);
  };
  return (
    <>
      <img
        {...props}
        src={src}
        alt={alt}
        role="button"
        tabIndex={0}
        aria-label={`Enlarge ${alt || "photo"}`}
        aria-haspopup="dialog"
        style={{ ...style, cursor: "zoom-in" }}
        onClick={show}
        onDoubleClick={stop}
        onPointerDown={stop}
        onKeyDown={event => {
          event.stopPropagation();
          if (event.key === "Enter" || event.key === " ") show(event);
        }}
        onKeyUp={stop}
      />
      {open && (
        <PhotoPreview
          src={previewSource(previewSrc || props["data-photo-original"] || src)}
          alt={alt}
          onClose={() => setOpen(false)}
        />
      )}
    </>
  );
}

import { useEffect, useId, useMemo, useRef, useState } from "react";
import { createPortal } from "react-dom";
import "./PhotoCropDialog.css";

/**
 * Reusable, non-destructive PHOTOS crop editor.
 *
 * The imageUrl MUST be an uncropped, aspect-preserving image.
 * The original image file is never changed.
 *
 * <PhotoCropDialog
 *   open={open}
 *   photoLibraryId={photo.photo_library_id}
 *   imageUrl={photo.original_url}
 *   cropType="swatch"
 *   onClose={() => setOpen(false)}
 *   onSaved={(crop) => { ... }}
 * />
 */
const ENDPOINT = "/api/v2/admin/photo-library/crop.php";
const MIN_DISPLAY_SIZE = 32; // minimum crop width in displayed pixels

const clamp = (value, min, max) => Math.min(max, Math.max(min, value));
const validCrop = (c) =>
  c && ["crop_x", "crop_y", "crop_width", "crop_height"]
    .every((key) => Number.isFinite(Number(c[key])));

function normalizeCrop(c) {
  return {
    crop_x: Number(c.crop_x),
    crop_y: Number(c.crop_y),
    crop_width: Number(c.crop_width),
    crop_height: Number(c.crop_height),
  };
}

function centeredCrop(imageWidth, imageHeight, ratio) {
  const actual = imageWidth / imageHeight;
  const width = actual > ratio ? ratio / actual : 1;
  const height = actual > ratio ? 1 : actual / ratio;
  return { crop_x: (1 - width) / 2, crop_y: (1 - height) / 2,
    crop_width: width, crop_height: height };
}

async function request(url, options) {
  const response = await fetch(url, {
    credentials: "same-origin",
    cache: "no-store",
    ...options,
  });
  const json = await response.json().catch(() => null);
  if (!response.ok || !json?.ok) {
    throw new Error(json?.error || `Crop request failed (${response.status})`);
  }
  return json;
}

export default function PhotoCropDialog({
  open,
  photoLibraryId,
  imageUrl,
  cropType = "swatch",
  onClose,
  onSaved,
  onReset,
  title = "Set Photo Crop",
  allowedTypes = null,
}) {
  const titleId = useId();
  const photoRef = useRef(null);
  const pointerRef = useRef(null);
  const [type, setType] = useState(cropType);
  const [presets, setPresets] = useState(null);
  const [crop, setCrop] = useState(null);
  const [originalCrop, setOriginalCrop] = useState(null);
  const [isCustom, setIsCustom] = useState(false);
  const [dimensions, setDimensions] = useState(null);
  const [loading, setLoading] = useState(false);
  const [working, setWorking] = useState(false);
  const [error, setError] = useState("");
  const [imageLoaded, setImageLoaded] = useState(false);
  const [imageError, setImageError] = useState(false);
  const cancelRef = useRef(null);

  const preset = presets?.[type];
  const ratio = preset ? Number(preset.width) / Number(preset.height) : null;
  const ready = !!(crop && preset && dimensions && imageLoaded && !imageError);
  const cropDraftRef = useRef(null);
  cropDraftRef.current = crop;

  // Initial preset comes from the caller; the available ratios come from PHOTOS.
  useEffect(() => {
    if (!open) return;
    setType(cropType);
    setCrop(null);
    setOriginalCrop(null);
    setDimensions(null);
    setImageLoaded(false);
    setImageError(false);
    setPresets(null);
    setError("");
  }, [open, photoLibraryId, cropType, imageUrl]);

  useEffect(() => {
    if (!open || !photoLibraryId || !type) return;
    const controller = new AbortController();
    setLoading(true);
    setError("");
    setCrop(null);
    setOriginalCrop(null);
    Promise.all([
      request(`${ENDPOINT}?action=presets`, { signal: controller.signal }),
      request(`${ENDPOINT}?action=get&photo_library_id=${encodeURIComponent(photoLibraryId)}&crop_type=${encodeURIComponent(type)}`,
        { signal: controller.signal }),
    ]).then(([presetResponse, data]) => {
      if (controller.signal.aborted) return;
      setPresets(presetResponse.presets);
      setDimensions({ width: Number(data.image_width), height: Number(data.image_height) });
      const normalized = normalizeCrop(data.crop);
      setCrop(normalized);
      setOriginalCrop(normalized);
      setIsCustom(Boolean(data.crop.is_custom));
    }).catch((err) => {
      if (err.name !== "AbortError") setError(err.message);
    }).finally(() => {
      if (!controller.signal.aborted) setLoading(false);
    });
    return () => controller.abort();
  }, [open, photoLibraryId, type]);

  useEffect(() => {
    if (!open) return;
    function keydown(event) {
      if (event.key === "Escape" && !working) onClose?.();
    }
    document.addEventListener("keydown", keydown);
    cancelRef.current?.focus();
    return () => document.removeEventListener("keydown", keydown);
  }, [open, working, onClose]);

  const onImageLoad = (event) => {
    const img = event.currentTarget;
    const width = img.naturalWidth, height = img.naturalHeight;
    setImageLoaded(true);
    setImageError(false);
    // The URL must preserve the original photograph's aspect ratio.
    if (dimensions && Math.abs(width / height - dimensions.width / dimensions.height) > 0.003) {
      setError("The supplied image is already cropped. Use an uncropped photo URL to edit its crop.");
    }
  };

  const geometry = useMemo(() => {
    if (!dimensions || !ratio) return null;
    const slope = dimensions.width / (ratio * dimensions.height);
    return {
      slope,
      defaultCrop: centeredCrop(dimensions.width, dimensions.height, ratio),
    };
  }, [dimensions, ratio]);

  const startDrag = (event, mode) => {
    if (!ready || working || !geometry || !photoRef.current) return;
    if (event.button !== 0) return;
    event.preventDefault();
    const rect = photoRef.current.getBoundingClientRect();
    pointerRef.current = {
      pointerId: event.pointerId,
      mode,
      x: event.clientX,
      y: event.clientY,
      rect,
      initial: { ...cropDraftRef.current },
    };
    event.currentTarget.setPointerCapture(event.pointerId);
  };

  const moveDrag = (event) => {
    const drag = pointerRef.current;
    if (!drag || drag.pointerId !== event.pointerId || !geometry) return;
    event.preventDefault();
    const { initial: c, rect } = drag;
    const dx = (event.clientX - drag.x) / rect.width;
    const dy = (event.clientY - drag.y) / rect.height;
    if (drag.mode === "move") {
      setCrop({
        ...c,
        crop_x: clamp(c.crop_x + dx, 0, 1 - c.crop_width),
        crop_y: clamp(c.crop_y + dy, 0, 1 - c.crop_height),
      });
      return;
    }

    // Resize about the opposite fixed corner, maintaining preset aspect ratio.
    const left = drag.mode.includes("left");
    const top = drag.mode.includes("top");
    const anchorX = left ? c.crop_x + c.crop_width : c.crop_x;
    const anchorY = top ? c.crop_y + c.crop_height : c.crop_y;

    const horizontal = left ? -dx : dx;
    const vertical = (top ? -dy : dy) / geometry.slope;
    const desired = c.crop_width + (
      Math.abs(horizontal) >= Math.abs(vertical) ? horizontal : vertical
    );

    const maxWidthX = left ? anchorX : 1 - anchorX;
    const maxHeightY = top ? anchorY : 1 - anchorY;
    const maxWidth = Math.min(maxWidthX, maxHeightY / geometry.slope);
    const minWidth = Math.min(
      maxWidth,
      Math.max(0.00001, MIN_DISPLAY_SIZE / rect.width)
    );
    const width = clamp(desired, minWidth, maxWidth);
    const height = width * geometry.slope;

    setCrop({
      crop_x: left ? anchorX - width : anchorX,
      crop_y: top ? anchorY - height : anchorY,
      crop_width: width,
      crop_height: height,
    });
  };

  const endDrag = (event) => {
    if (pointerRef.current?.pointerId !== event.pointerId) return;
    pointerRef.current = null;
    if (event.currentTarget.hasPointerCapture(event.pointerId)) {
      event.currentTarget.releasePointerCapture(event.pointerId);
    }
  };

  const save = async () => {
    if (!ready || working || error) return;
    setWorking(true);
    setError("");
    try {
      const payload = {
        action: "save",
        photo_library_id: Number(photoLibraryId),
        crop_type: type,
        ...crop,
      };
      const data = await request(ENDPOINT, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(payload),
      });
      const next = normalizeCrop(data.crop);
      setCrop(next);
      setOriginalCrop(next);
      setIsCustom(true);
      onSaved?.({ ...data.crop, photo_library_id: Number(photoLibraryId), crop_type: type });
      onClose?.();
    } catch (err) {
      setError(err.message);
    } finally {
      setWorking(false);
    }
  };

  const reset = async () => {
    if (working || !photoLibraryId || !geometry) return;
    setWorking(true);
    setError("");
    try {
      await request(ENDPOINT, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ action: "reset", photo_library_id: Number(photoLibraryId), crop_type: type }),
      });
      const next = geometry.defaultCrop;
      setCrop(next);
      setOriginalCrop(next);
      setIsCustom(false);
      onReset?.({ photo_library_id: Number(photoLibraryId), crop_type: type });
      onSaved?.({ ...next, is_custom: false, photo_library_id: Number(photoLibraryId), crop_type: type });
    } catch (err) {
      setError(err.message);
    } finally {
      setWorking(false);
    }
  };

  if (!open || typeof document === "undefined") return null;

  const types = Object.keys(presets || {}).filter((key) => !allowedTypes || allowedTypes.includes(key));

  return createPortal(
    <div className="photo-crop-backdrop" onPointerDown={(event) => {
      if (event.target === event.currentTarget && !working) onClose?.();
    }}>
      <section
        className="photo-crop-dialog"
        role="dialog"
        aria-modal="true"
        aria-labelledby={titleId}
      >
        <header className="photo-crop-header">
          <h2 id={titleId}>{title}</h2>
          <button type="button" className="photo-crop-close" disabled={working}
            onClick={() => onClose?.()} aria-label="Close crop editor">×</button>
        </header>

        <div className="photo-crop-body">
          <div className="photo-crop-toolbar">
            <label htmlFor={`${titleId}-preset`}>Crop format</label>
            <select
              id={`${titleId}-preset`}
              value={type}
              disabled={loading || working || types.length <= 1}
              onChange={(event) => setType(event.target.value)}
            >
              {types.length
                ? types.map((key) => (
                  <option key={key} value={key}>{presets[key].label} ({presets[key].width}:{presets[key].height})</option>
                ))
                : <option value={type}>Loading crop format…</option>}
            </select>
          </div>

          <p className="photo-crop-hint">
            Drag the frame to reposition it. Drag a corner to resize it; proportions stay fixed.
          </p>

          {loading && <p role="status">Loading crop…</p>}
          {error && <p className="photo-crop-error" role="alert">{error}</p>}
          {!imageUrl && <p className="photo-crop-error">An uncropped photo URL is required.</p>}

          <div className="photo-crop-workspace">
            {imageUrl ? (
              <div className="photo-crop-image-wrap" ref={photoRef}>
                <img
                  src={imageUrl}
                  alt="Original photograph for crop positioning"
                  onLoad={onImageLoad}
                  onError={() => { setImageError(true); setError("Unable to load photograph."); }}
                  draggable={false}
                />
                {ready && (
                  <>
                    <div className="photo-crop-shade" />
                    <div
                      className="photo-crop-selection"
                      style={{
                        left: `${crop.crop_x * 100}%`,
                        top: `${crop.crop_y * 100}%`,
                        width: `${crop.crop_width * 100}%`,
                        height: `${crop.crop_height * 100}%`,
                      }}
                      onPointerDown={(event) => startDrag(event, "move")}
                      onPointerMove={moveDrag}
                      onPointerUp={endDrag}
                      onPointerCancel={endDrag}
                      role="img"
                      aria-label="Drag to move the crop rectangle"
                    >
                      {["top-left", "top-right", "bottom-left", "bottom-right"].map((corner) => (
                        <button
                          key={corner}
                          type="button"
                          className={`photo-crop-handle photo-crop-handle--${corner}`}
                          onPointerDown={(event) => { event.stopPropagation(); startDrag(event, corner); }}
                          onPointerMove={moveDrag}
                          onPointerUp={endDrag}
                          onPointerCancel={endDrag}
                          aria-label={`Resize crop from ${corner.replace("-", " ")} corner`}
                        />
                      ))}
                    </div>
                  </>
                )}
              </div>
            ) : null}
          </div>

          {ready && (
            <div className="photo-crop-preview-row">
              <div>
                <strong>Crop preview</strong>
                <p>Only this framing is saved; your original photograph stays unchanged.</p>
              </div>
              <div className="photo-crop-preview"
                style={{ aspectRatio: `${preset.width} / ${preset.height}` }}>
                <img
                  src={imageUrl}
                  alt="Preview of the selected crop"
                  draggable={false}
                  style={{
                    width: `${100 / crop.crop_width}%`,
                    height: `${100 / crop.crop_height}%`,
                    left: `${-crop.crop_x / crop.crop_width * 100}%`,
                    top: `${-crop.crop_y / crop.crop_height * 100}%`,
                  }}
                />
              </div>
            </div>
          )}
        </div>

        <footer className="photo-crop-footer">
          <button type="button" onClick={reset} disabled={!ready || working || !isCustom}>
            Delete Saved Crop
          </button>
          <div className="photo-crop-footer-right">
            <button type="button" ref={cancelRef} onClick={() => onClose?.()} disabled={working}>Cancel</button>
            <button className="photo-crop-save" type="button" onClick={save} disabled={!ready || working || !!error}>
              {working ? "Saving…" : "Save Crop"}
            </button>
          </div>
        </footer>
      </section>
    </div>,
    document.body
  );
}

import {
  forwardRef,
  useEffect,
  useImperativeHandle,
  useRef,
  useState,
} from "react";
import { createPortal } from "react-dom";

import { AdminWorkbenchDrawer } from "@components/AdminLayout";
import PhotoPickerModal from "@components/PhotoPickerModal";
import { buildImageUrl } from "@helpers/assetImage";
import { API_FOLDER } from "@helpers/config";

import "./palette-photos-drawer.css";

const API_URL = `${API_FOLDER}/v2/admin/palette-viewers.php`;

const EMPTY_VIEWER = {
  palette_viewer_id: null,
  saved_palette_id: "",
  format: "public",
  template_key: "full_palette",
  kicker_text: "",
  title: "",
  intro: "",
  notes: "",
  cta_label: "",
  is_active: 1,
};

async function readJson(response, fallbackMessage) {
  const text = await response.text();
  let data = {};

  try {
    data = text.trim() ? JSON.parse(text) : {};
  } catch {
    throw new Error(
      `${fallbackMessage}: invalid JSON response`
    );
  }

  if (!response.ok || data?.ok === false) {
    throw new Error(
      data?.error || `HTTP ${response.status}`
    );
  }

  return data;
}

function cleanText(value) {
  return String(value ?? "").trim();
}

function withReturnTo(url, returnTo) {
  const rawUrl = String(url || "").trim();

  if (!rawUrl) return "";

  try {
    const parsed = new URL(
      rawUrl,
      window.location.origin
    );

    parsed.searchParams.set(
      "return_to",
      returnTo
    );

    if (parsed.origin === window.location.origin) {
      return `${parsed.pathname}${parsed.search}${parsed.hash}`;
    }

    return parsed.toString();
  } catch {
    const separator = rawUrl.includes("?")
      ? "&"
      : "?";

    return `${rawUrl}${separator}return_to=${encodeURIComponent(returnTo)}`;
  }
}

function paletteLabel(palette) {
  return (
    cleanText(palette?.nickname)
    || cleanText(palette?.display_title)
    || (
      palette?.id
        ? `Palette #${palette.id}`
        : "Palette"
    )
  );
}

function normalizePhoto(photo, index) {
  const type = cleanText(
    photo?.photo_type
  ).toLowerCase();

  return {
    ...photo,
    order_index: Number(
      photo?.order_index ?? index
    ),
    is_main: type === "full",
    is_before: type === "before",
  };
}

function normalizeDetail(payload, palette) {
  return {
    viewer: {
      ...EMPTY_VIEWER,
      ...(payload?.viewer || {}),
      saved_palette_id: String(
        payload?.viewer?.saved_palette_id
        ?? palette?.id
        ?? ""
      ),
      title:
        cleanText(payload?.viewer?.title)
        || cleanText(palette?.pv_title)
        || cleanText(palette?.display_title)
        || cleanText(palette?.nickname),
      is_active:
        Number(
          payload?.viewer?.is_active ?? 1
        )
          ? 1
          : 0,
    },
    photos: (
      Array.isArray(payload?.photos)
        ? payload.photos
        : []
    ).map(normalizePhoto),
    rex: payload?.rex || null,
  };
}

function photoImageUrl(photo) {
  const raw = cleanText(
    photo?.rel_path
    || photo?.raw_rel_path
    || photo?.image_url
  );

  if (!raw) return "";

  if (
    /^https?:\/\//i.test(raw)
    || raw.startsWith("/")
  ) {
    return raw;
  }

  return buildImageUrl(raw);
}

function photoLabel(photo) {
  return (
    cleanText(photo?.alt_text)
    || cleanText(photo?.caption)
    || cleanText(photo?.rel_path)
      .split("/")
      .pop()
    || `Photo #${photo?.photo_library_id || ""}`
  );
}

function photoStateSignature(photos) {
  return JSON.stringify(
    (
      Array.isArray(photos)
        ? photos
        : []
    ).map((photo, index) => ({
      photo_library_id: Number(
        photo?.photo_library_id || 0
      ),
      rel_path: cleanText(
        photo?.rel_path
        || photo?.raw_rel_path
        || photo?.image_url
      ),
      is_main: Boolean(photo?.is_main),
      is_before: Boolean(photo?.is_before),
      order_index: Number(
        photo?.order_index ?? index
      ),
    }))
  );
}

const PalettePhotosDrawer = forwardRef(
  function PalettePhotosDrawer(
    {
      open = false,
      palette = null,
      onClose,
      onChanged,
      onDirtyChange,
    },
    ref
  ) {
    const [detail, setDetail] = useState(null);
    const [loading, setLoading] = useState(false);
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState("");
    const [status, setStatus] = useState("");
    const [pickerOpen, setPickerOpen] = useState(false);
    const [pickerMode, setPickerMode] = useState("add");
    const [selectedPhotoIndex, setSelectedPhotoIndex] = useState(null);
    const [previewPhoto, setPreviewPhoto] = useState(null);

    const baselinePhotosRef = useRef("");

    useEffect(() => {
      if (!open || !palette?.id) {
        setDetail(null);
        setError("");
        setStatus("");
        setSelectedPhotoIndex(null);
        setPreviewPhoto(null);
        baselinePhotosRef.current = "";
        onDirtyChange?.(false);
        return;
      }

      let cancelled = false;

      async function loadPhotos() {
        setLoading(true);
        setError("");
        setStatus("");

        try {
          let viewerId = Number(
            palette?.palette_viewer_id || 0
          );

          if (!viewerId) {
            const listData = await readJson(
              await fetch(
                `${API_URL}?_=${Date.now()}`,
                {
                  credentials: "include",
                  cache: "no-store",
                }
              ),
              "Failed to find PV"
            );

            const rows = Array.isArray(
              listData?.items
            )
              ? listData.items
              : [];

            const match = rows.find((row) => {
              const viewer =
                row?.viewer || row || {};

              return Number(
                viewer?.saved_palette_id || 0
              ) === Number(palette.id);
            });

            viewerId = Number(
              match?.viewer?.palette_viewer_id
              || match?.palette_viewer_id
              || 0
            );
          }

          if (viewerId) {
            const data = await readJson(
              await fetch(
                `${API_URL}?id=${encodeURIComponent(viewerId)}&_=${Date.now()}`,
                {
                  credentials: "include",
                  cache: "no-store",
                }
              ),
              "Failed to load PV"
            );

            if (!cancelled) {
              const nextDetail =
                normalizeDetail(
                  data?.item,
                  palette
                );

              baselinePhotosRef.current =
                photoStateSignature(
                  nextDetail.photos
                );

              setDetail(nextDetail);
              setSelectedPhotoIndex(null);
              onDirtyChange?.(false);
            }

            return;
          }

          if (!cancelled) {
            const nextDetail =
              normalizeDetail(
                {
                  viewer: {
                    ...EMPTY_VIEWER,
                    saved_palette_id: String(
                      palette.id
                    ),
                  },
                  photos: [],
                },
                palette
              );

            baselinePhotosRef.current =
              photoStateSignature(
                nextDetail.photos
              );

            setDetail(nextDetail);
            setSelectedPhotoIndex(null);
            onDirtyChange?.(false);
          }
        } catch (err) {
          if (!cancelled) {
            setError(
              err?.message
              || "Failed to load photos."
            );
          }
        } finally {
          if (!cancelled) {
            setLoading(false);
          }
        }
      }

      loadPhotos();

      return () => {
        cancelled = true;
      };
    }, [open, palette, onDirtyChange]);

    useEffect(() => {
      if (
        !detail
        || !baselinePhotosRef.current
      ) {
        return;
      }

      onDirtyChange?.(
        photoStateSignature(detail.photos)
        !== baselinePhotosRef.current
      );
    }, [detail?.photos, onDirtyChange]);

    function chooseMain(index) {
      setDetail((current) => {
        if (!current) return current;

        return {
          ...current,
          photos: current.photos.map(
            (photo, photoIndex) => ({
              ...photo,
              is_main: photoIndex === index,
              is_before:
                photoIndex === index
                  ? false
                  : photo.is_before,
            })
          ),
        };
      });
    }

    function toggleBefore(index, checked) {
      setDetail((current) => {
        if (!current) return current;

        return {
          ...current,
          photos: current.photos.map(
            (photo, photoIndex) =>
              photoIndex === index
                ? {
                    ...photo,
                    is_before: checked,
                  }
                : photo
          ),
        };
      });
    }

    function removePhoto(index) {
      setDetail((current) => {
        if (!current) return current;

        const removedWasMain = Boolean(
          current.photos[index]?.is_main
        );

        let photos = current.photos
          .filter(
            (_, photoIndex) =>
              photoIndex !== index
          )
          .map((photo, photoIndex) => ({
            ...photo,
            order_index: photoIndex,
          }));

        if (
          removedWasMain
          && photos.length > 0
          && !photos.some(
            (photo) => photo.is_main
          )
        ) {
          photos = photos.map(
            (photo, photoIndex) => ({
              ...photo,
              is_main: photoIndex === 0,
              is_before:
                photoIndex === 0
                  ? false
                  : photo.is_before,
            })
          );
        }

        return {
          ...current,
          photos,
        };
      });

      setSelectedPhotoIndex((current) => {
        if (current === null) return null;
        if (current === index) return null;
        if (current > index) return current - 1;
        return current;
      });
    }

    function openAddPhotoPicker() {
      setPickerMode("add");
      setPickerOpen(true);
      setError("");
      setStatus("");
    }

    function openReplacePhotoPicker() {
      if (
        selectedPhotoIndex === null
        || !detail?.photos?.[selectedPhotoIndex]
      ) {
        return;
      }

      setPickerMode("replace");
      setPickerOpen(true);
      setError("");
      setStatus("");
    }

    function handlePhotoPick(photo) {
      setPickerOpen(false);

      const pickedPhoto = {
        photo_library_id:
          photo?.photo_library_id
          || photo?.id
          || null,
        rel_path:
          photo?.raw_rel_path
          || photo?.rel_path
          || photo?.image_url
          || "",
        caption: "",
        alt_text:
          photo?.title
          || photo?.alt_text
          || "",
      };

      if (
        pickerMode === "replace"
        && selectedPhotoIndex !== null
      ) {
        setDetail((current) => {
          if (
            !current
            || !current.photos[selectedPhotoIndex]
          ) {
            return current;
          }

          return {
            ...current,
            photos: current.photos.map(
              (existingPhoto, photoIndex) =>
                photoIndex === selectedPhotoIndex
                  ? {
                      ...existingPhoto,
                      ...pickedPhoto,
                      palette_viewer_photo_id: null,
                    }
                  : existingPhoto
            ),
          };
        });

        setPickerMode("add");
        setStatus("Photo replaced. Save Photos to keep the change.");
        return;
      }

      setDetail((current) => {
        if (!current) return current;

        const hasMain = current.photos.some(
          (item) => item.is_main
        );

        const nextPhoto = {
          palette_viewer_photo_id: null,
          palette_viewer_id:
            current.viewer.palette_viewer_id
            || null,
          ...pickedPhoto,
          trigger_mode: "any",
          trigger_color_id: null,
          order_index:
            current.photos.length,
          is_main: !hasMain,
          is_before: false,
        };

        return {
          ...current,
          photos: [
            ...current.photos,
            nextPhoto,
          ],
        };
      });

      setPickerMode("add");
    }

    function openPV() {
      const rexUrl =
        cleanText(
          detail?.rex?.public_url
        )
        || cleanText(detail?.rex?.url)
        || cleanText(detail?.rex?.href);

      if (!rexUrl) {
        setError(
          "This PV does not currently have a REX URL."
        );
        return;
      }

      setError("");

      window.location.assign(
        withReturnTo(
          rexUrl,
          "/admin/palettes"
        )
      );
    }

    async function savePhotos() {
      if (
        !detail
        || !palette?.id
        || saving
      ) {
        return true;
      }

      const mainCount = detail.photos.filter(
        (photo) => photo.is_main
      ).length;

      if (
        detail.photos.length > 0
        && mainCount !== 1
      ) {
        setError(
          "Choose exactly one Main photo."
        );

        return false;
      }

      setSaving(true);
      setError("");
      setStatus("");

      try {
        const payload = {
          viewer: {
            ...detail.viewer,
            saved_palette_id: Number(
              palette.id
            ),
            is_active:
              Number(
                detail.viewer.is_active ?? 1
              )
                ? 1
                : 0,
          },

          photos: detail.photos.map(
            (photo, index) => ({
              ...photo,
              photo_type: photo.is_main
                ? "full"
                : photo.is_before
                  ? "before"
                  : "zoom",
              photo_library_id:
                photo.photo_library_id
                  ? Number(
                      photo.photo_library_id
                    )
                  : null,
              rel_path:
                photo.photo_library_id
                  ? ""
                  : cleanText(
                      photo.rel_path
                    ),
              trigger_mode: "any",
              trigger_color_id: null,
              order_index: index,
            })
          ),
        };

        const data = await readJson(
          await fetch(API_URL, {
            method: "POST",
            credentials: "include",
            headers: {
              "Content-Type":
                "application/json",
            },
            body: JSON.stringify(payload),
          }),
          "Failed to save photos"
        );

        const saved = normalizeDetail(
          data?.item,
          palette
        );

        baselinePhotosRef.current =
          photoStateSignature(saved.photos);

        setDetail(saved);
        setStatus("Photos saved.");
        onDirtyChange?.(false);

        if (
          typeof onChanged === "function"
        ) {
          onChanged(saved);
        }

        return true;
      } catch (err) {
        setError(
          err?.message
          || "Failed to save photos."
        );

        return false;
      } finally {
        setSaving(false);
      }
    }

    useImperativeHandle(
      ref,
      () => ({
        save: savePhotos,
      }),
      [detail, palette, saving]
    );

    if (!open || !palette) {
      return null;
    }

    return (
      <>
        <AdminWorkbenchDrawer
          open
          width={500}
          title={`${paletteLabel(palette)} · Photos`}
          onClose={onClose}
          portal
          padded
        >
          <div className="palette-photo-manager">
            <div className="palette-photo-manager__toolbar">
              <button
                type="button"
                className="palette-photo-manager__button"
                onClick={openAddPhotoPicker}
                disabled={loading || saving}
              >
                Pick from Library
              </button>

              <button
                type="button"
                className="palette-photo-manager__button"
                onClick={openReplacePhotoPicker}
                disabled={
                  loading
                  || saving
                  || selectedPhotoIndex === null
                }
              >
                Replace Photo
              </button>

              <button
                type="button"
                className="palette-photo-manager__button"
                onClick={savePhotos}
                disabled={
                  loading
                  || saving
                  || !detail
                  || (
                    baselinePhotosRef.current !== ""
                    && photoStateSignature(
                      detail?.photos
                    )
                    === baselinePhotosRef.current
                  )
                }
              >
                {saving
                  ? "Saving…"
                  : "Save Photos"}
              </button>

              <button
                type="button"
                className="palette-photo-manager__button"
                onClick={openPV}
                disabled={
                  loading
                  || !(
                    cleanText(
                      detail?.rex?.public_url
                    )
                    || cleanText(
                      detail?.rex?.url
                    )
                    || cleanText(
                      detail?.rex?.href
                    )
                  )
                }
              >
                Open PV
              </button>
            </div>

            {error ? (
              <div className="palette-photo-manager__message palette-photo-manager__message--error">
                {error}
              </div>
            ) : null}

            {status ? (
              <div className="palette-photo-manager__message">
                {status}
              </div>
            ) : null}

            {loading ? (
              <div className="palette-photo-manager__empty">
                Loading photos…
              </div>
            ) : detail?.photos?.length ? (
              <div className="palette-photo-manager__list">
                {detail.photos.map(
                  (photo, index) => {
                    const imageUrl =
                      photoImageUrl(photo);

                    return (
                      <div
                        className={[
                          "palette-photo-manager__row",
                          selectedPhotoIndex === index
                            ? "palette-photo-manager__row--selected"
                            : "",
                        ].filter(Boolean).join(" ")}
                        key={`${photo.palette_viewer_photo_id || "new"}-${photo.photo_library_id || "photo"}-${index}`}
                        onClick={() =>
                          setSelectedPhotoIndex(index)
                        }
                      >
                        <div
                          className="palette-photo-manager__thumb"
                          role="button"
                          tabIndex={0}
                          title="Click to view full screen"
                          onClick={(event) => {
                            event.stopPropagation();
                            setSelectedPhotoIndex(index);
                            setPreviewPhoto(photo);
                          }}
                          onKeyDown={(
                            event
                          ) => {
                            if (
                              event.key
                                === "Enter"
                              || event.key
                                === " "
                            ) {
                              event.preventDefault();

                              setSelectedPhotoIndex(index);
                              setPreviewPhoto(
                                photo
                              );
                            }
                          }}
                        >
                          {imageUrl ? (
                            <img
                              src={imageUrl}
                              alt={photoLabel(
                                photo
                              )}
                            />
                          ) : (
                            <span>
                              No preview
                            </span>
                          )}
                        </div>

                        <label className="palette-photo-manager__choice">
                          <input
                            type="radio"
                            name="palette-main-photo"
                            checked={Boolean(
                              photo.is_main
                            )}
                            onChange={() =>
                              chooseMain(index)
                            }
                          />
                          <span>Main</span>
                        </label>

                        <label className="palette-photo-manager__choice">
                          <input
                            type="checkbox"
                            checked={Boolean(
                              photo.is_before
                            )}
                            disabled={Boolean(
                              photo.is_main
                            )}
                            onChange={(
                              event
                            ) =>
                              toggleBefore(
                                index,
                                event.target
                                  .checked
                              )
                            }
                          />
                          <span>Before</span>
                        </label>

                        <button
                          type="button"
                          className="palette-photo-manager__remove"
                          onClick={(event) => {
                            event.stopPropagation();
                            removePhoto(index);
                          }}
                          title="Remove photo"
                          aria-label="Remove photo"
                        >
                          ×
                        </button>
                      </div>
                    );
                  }
                )}
              </div>
            ) : (
              <div className="palette-photo-manager__empty">
                No photos attached yet.
              </div>
            )}

            <div className="palette-photo-manager__note">
              Main is the single full view.
              Every other photo is a zoom
              unless marked Before.
            </div>
          </div>
        </AdminWorkbenchDrawer>

        <PhotoPickerModal
          open={pickerOpen}
          title={
            pickerMode === "replace"
              ? "Replace Viewer Photo"
              : "Add Viewer Photo"
          }
          onClose={() => {
            setPickerOpen(false);
            setPickerMode("add");
          }}
          onPick={handlePhotoPick}
        />

        {previewPhoto
          && typeof document !== "undefined"
          ? createPortal(
              <div
                className="palette-photo-preview"
                role="button"
                tabIndex={0}
                title="Click to close"
                onClick={() =>
                  setPreviewPhoto(null)
                }
                onKeyDown={(event) => {
                  if (
                    event.key === "Escape"
                    || event.key === "Enter"
                    || event.key === " "
                  ) {
                    event.preventDefault();
                    setPreviewPhoto(null);
                  }
                }}
              >
                <img
                  src={photoImageUrl(
                    previewPhoto
                  )}
                  alt={photoLabel(
                    previewPhoto
                  )}
                />
              </div>,
              document.body
            )
          : null}
      </>
    );
  }
);

export default PalettePhotosDrawer;

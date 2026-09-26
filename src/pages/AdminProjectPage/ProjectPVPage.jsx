import {
  forwardRef,
  useCallback,
  useEffect,
  useImperativeHandle,
  useMemo,
  useRef,
  useState,
} from "react";

import {
  AdminButton,
  AdminDialog,
  AdminDetailPane,
  AdminEmptyState,
  AdminField,
  AdminMetaText,
  AdminNotice,
  AdminPanel,
  AdminSmartGrid,
  AdminStack,
  AdminToolbar,
} from "@components/AdminLayout";

import PhotoPickerDialog from "@components/Dialogs/PhotoPickerDialog";
import KickerDropdown from "@components/KickerDropdown";
import FetchRexButton from "@components/REX/FetchRexButton";

import {
  API_FOLDER,
} from "@helpers/config";

import {
  buildImageUrl,
} from "@helpers/assetImage";


const PROJECT_PALETTES_URL =
  `${API_FOLDER}/v2/admin/projects/palettes/list.php`;

const PV_LIST_URL =
  `${API_FOLDER}/v2/admin/palettes/pvs/list.php`;

const PV_CREATE_URL =
  `${API_FOLDER}/v2/admin/palettes/pvs/create.php`;

const PV_ADMIN_URL =
  `${API_FOLDER}/v2/admin/palette-viewers.php`;

const PLAYLIST_GET_URL =
  `${API_FOLDER}/v2/admin/playlists/get.php`;

const PROJECT_SLIDE_PALETTE_URL =
  `${API_FOLDER}/v2/admin/playlist-items/project-palette.php`;

const PHOTO_LIBRARY_LIST_URL =
  `${API_FOLDER}/v2/admin/photo-library/list.php`;


const EXPERIENCE_OPTIONS = [
  {
    value:
      "public",
    label:
      "Public",
  },
  {
    value:
      "concept",
    label:
      "Concept",
  },
  {
    value:
      "client",
    label:
      "Client",
  },
  {
    value:
      "painter",
    label:
      "Painter",
  },
];


function cleanText(
  value
) {
  return String(
    value ?? ""
  ).trim();
}


function paletteLabel(
  row
) {
  return cleanText(
    row?.display_title
    ||
    row?.nickname
    ||
    row?.palette_name
    ||
    (
      row?.saved_palette_id
        ? `Palette #${row.saved_palette_id}`
        : ""
    )
  );
}


function experienceLabel(
  value
) {
  const text =
    cleanText(
      value
    ).toLowerCase();

  if (!text) {
    return "—";
  }

  return (
    text.charAt(0).toUpperCase()
    +
    text.slice(1)
  );
}


function derivedHandle(
  item
) {
  const title =
    cleanText(
      item?.title
    )
    ||
    "Untitled";

  const experience =
    experienceLabel(
      item?.format
      ??
      item?.experience_key
    );

  return `${title} - ${experience}`;
}


async function readJson(
  response,
  fallbackMessage
) {
  const text =
    await response.text();

  let data = {};

  try {
    data =
      text.trim()
        ? JSON.parse(
            text
          )
        : {};
  } catch {
    throw new Error(
      `${fallbackMessage}: invalid JSON response`
    );
  }

  if (
    !response.ok
    ||
    data?.ok === false
  ) {
    throw new Error(
      data?.error
      ||
      `HTTP ${response.status}`
    );
  }

  return data;
}


function normalizePVPhoto(
  photo,
  index
) {
  return {
    ...photo,
    photo_type:
      cleanText(
        photo?.photo_type
      ).toLowerCase()
      ||
      (
        index === 0
          ? "full"
          : "zoom"
      ),
    order_index:
      Number(
        photo?.order_index
        ??
        index
      ),
  };
}


function photoImageUrl(
  photo
) {
  const raw =
    cleanText(
      photo?.rel_path
      ||
      photo?.raw_rel_path
      ||
      photo?.image_url
    );

  if (!raw) {
    return "";
  }

  if (
    /^https?:\/\//i.test(
      raw
    )
    ||
    raw.startsWith(
      "/"
    )
  ) {
    return raw;
  }

  return buildImageUrl(
    raw
  );
}


function photoLabel(
  photo
) {
  return (
    cleanText(
      photo?.caption
    )
    ||
    cleanText(
      photo?.alt_text
    )
    ||
    (
      photo?.photo_library_id
        ? `Photo #${photo.photo_library_id}`
        : "Photo"
    )
  );
}


function rexUrlFromDetail(
  value
) {
  return cleanText(
    value?.rex?.public_url
    ||
    value?.rex?.url
    ||
    value?.rex?.href
  );
}


function rexAdminUrl(
  value
) {
  const raw =
    cleanText(
      value
    );

  if (!raw) {
    return "";
  }

  try {
    const url =
      new URL(
        raw,
        window.location.origin
      );

    url.searchParams.set(
      "back",
      "1"
    );

    return url.toString();
  } catch {
    return raw;
  }
}


function normalizePVDetail(
  payload,
  row
) {
  const viewer =
    payload?.viewer
    ||
    {};

  return {
    viewer: {
      ...viewer,

      palette_viewer_id:
        Number(
          viewer?.palette_viewer_id
          ??
          row?.palette_viewer_id
          ??
          0
        ),

      saved_palette_id:
        String(
          viewer?.saved_palette_id
          ??
          row?.saved_palette_id
          ??
          ""
        ),

      format:
        cleanText(
          viewer?.format
          ??
          row?.format
          ??
          row?.experience_key
        ).toLowerCase(),

      kicker_text:
        cleanText(
          viewer?.kicker_text
          ??
          row?.kicker_text
        ),

      title:
        cleanText(
          viewer?.title
          ??
          row?.title
        ),

      intro:
        String(
          viewer?.intro
          ??
          row?.intro
          ??
          ""
        ),

      notes:
        String(
          viewer?.notes
          ??
          ""
        ),

      cta_label:
        String(
          viewer?.cta_label
          ??
          ""
        ),

      is_active:
        Number(
          viewer?.is_active
          ??
          row?.is_active
          ??
          1
        )
          ? 1
          : 0,
    },

    photos:
      (
        Array.isArray(
          payload?.photos
        )
          ? payload.photos
          : []
      ).map(
        normalizePVPhoto
      ),

    rex:
      payload?.rex
      ||
      null,
  };
}


const PVDrawerEditor = forwardRef(function PVDrawerEditor({
  item,
  palettes,
  playlistId,
  onSaved,
}, ref) {
  const [
    detail,
    setDetail,
  ] = useState(null);

  const [
    loading,
    setLoading,
  ] = useState(true);

  const [
    saving,
    setSaving,
  ] = useState(false);

  const [
    error,
    setError,
  ] = useState("");

  const [
    status,
    setStatus,
  ] = useState("");

  const [
    pickerOpen,
    setPickerOpen,
  ] = useState(false);

  const [
    mainConflictOpen,
    setMainConflictOpen,
  ] = useState(false);

  const [
    suggestedPhotos,
    setSuggestedPhotos,
  ] = useState([]);

  const [
    suggestedPhotosLoading,
    setSuggestedPhotosLoading,
  ] = useState(false);

  const [
    suggestedPhotosError,
    setSuggestedPhotosError,
  ] = useState("");

  const pvId =
    Number(
      item?.palette_viewer_id
      ||
      0
    );


  useEffect(
    () => {
      let cancelled =
        false;

      if (pvId <= 0) {
        setDetail(null);
        setLoading(false);
        setError(
          "This PV does not have a valid ID."
        );
        return undefined;
      }

      setLoading(true);
      setError("");
      setStatus("");

      (
        async () => {
          try {
            const data =
              await readJson(
                await fetch(
                  `${PV_ADMIN_URL}?id=${encodeURIComponent(
                    pvId
                  )}&_=${Date.now()}`,
                  {
                    credentials:
                      "include",
                    cache:
                      "no-store",
                  }
                ),
                "Failed to load PV"
              );

            if (
              cancelled
            ) {
              return;
            }

            setDetail(
              normalizePVDetail(
                data?.item,
                item
              )
            );

          } catch (err) {
            if (
              !cancelled
            ) {
              setDetail(
                null
              );

              setError(
                err?.message
                ||
                "Failed to load PV."
              );
            }

          } finally {
            if (
              !cancelled
            ) {
              setLoading(
                false
              );
            }
          }
        }
      )();

      return () => {
        cancelled =
          true;
      };
    },
    [
      pvId,
      item,
    ]
  );


  useEffect(
    () => {
      let cancelled =
        false;

      const savedPaletteId =
        Number(
          detail
            ?.viewer
            ?.saved_palette_id
          ||
          0
        );

      const resolvedPlaylistId =
        Number(
          playlistId
          ||
          0
        );

      if (
        savedPaletteId <= 0
        ||
        resolvedPlaylistId <= 0
      ) {
        setSuggestedPhotos([]);
        setSuggestedPhotosLoading(false);
        setSuggestedPhotosError("");
        return undefined;
      }

      setSuggestedPhotosLoading(true);
      setSuggestedPhotosError("");

      (
        async () => {
          try {
            const playlistParams =
              new URLSearchParams({
                playlist_id:
                  String(
                    resolvedPlaylistId
                  ),

                _:
                  String(
                    Date.now()
                  ),
              });

            const playlistData =
              await readJson(
                await fetch(
                  `${PLAYLIST_GET_URL}?${playlistParams.toString()}`,
                  {
                    credentials:
                      "include",

                    cache:
                      "no-store",
                  }
                ),

                "Failed to load playlist photos"
              );

            const slides =
              (
                Array.isArray(
                  playlistData?.items
                )
                  ? playlistData.items
                  : []
              ).filter(
                (slide) =>
                  Number(
                    slide?.playlist_item_id
                    ||
                    0
                  ) > 0
                  &&
                  (
                    Number(
                      slide?.photo_library_id
                      ||
                      0
                    ) > 0
                    ||
                    cleanText(
                      slide?.image_url
                    )
                  )
              );

            const photoLibraryIds =
              Array.from(
                new Set(
                  slides
                    .map(
                      (slide) =>
                        Number(
                          slide?.photo_library_id
                          ||
                          0
                        )
                    )
                    .filter(
                      (id) =>
                        id > 0
                    )
                )
              );

            const resolvedThumbs =
              {};

            if (
              photoLibraryIds.length
            ) {
              const photoParams =
                new URLSearchParams({
                  photo_library_ids:
                    photoLibraryIds.join(
                      ","
                    ),

                  limit:
                    String(
                      photoLibraryIds.length
                    ),

                  _:
                    String(
                      Date.now()
                    ),
                });

              const photoData =
                await readJson(
                  await fetch(
                    `${PHOTO_LIBRARY_LIST_URL}?${photoParams.toString()}`,
                    {
                      credentials:
                        "include",

                      cache:
                        "no-store",
                    }
                  ),

                  "Failed to resolve playlist photos"
                );

              for (
                const photo
                of (
                  Array.isArray(
                    photoData?.items
                  )
                    ? photoData.items
                    : []
                )
              ) {
                const id =
                  Number(
                    photo?.photo_library_id
                    ||
                    0
                  );

                if (
                  id <= 0
                ) {
                  continue;
                }

                resolvedThumbs[
                  id
                ] =
                  cleanText(
                    photo?.image_url
                    ||
                    photo?.rel_path
                  );
              }
            }

            const resolved =
              await Promise.all(
                slides.map(
                  async (
                    slide
                  ) => {
                    let linkedPaletteId =
                      Number(
                        slide?.saved_palette_id
                        ||
                        0
                      );

                    if (
                      linkedPaletteId <= 0
                    ) {
                      const linkParams =
                        new URLSearchParams({
                          playlist_item_id:
                            String(
                              slide.playlist_item_id
                            ),

                          _:
                            String(
                              Date.now()
                            ),
                        });

                      const linkData =
                        await readJson(
                          await fetch(
                            `${PROJECT_SLIDE_PALETTE_URL}?${linkParams.toString()}`,
                            {
                              credentials:
                                "include",

                              cache:
                                "no-store",
                            }
                          ),

                          "Failed to load slide palette"
                        );

                      linkedPaletteId =
                        Number(
                          linkData
                            ?.saved_palette_id
                          ||
                          0
                        );
                    }

                    if (
                      linkedPaletteId !==
                      savedPaletteId
                    ) {
                      return null;
                    }

                    return {
                      photo_library_id:
                        Number(
                          slide?.photo_library_id
                          ||
                          0
                        )
                        ||
                        null,

                      image_url:
                        cleanText(
                          resolvedThumbs[
                            Number(
                              slide?.photo_library_id
                              ||
                              0
                            )
                          ]
                          ||
                          slide?.image_url
                        ),

                      rel_path:
                        "",

                      caption:
                        "",

                      alt_text:
                        cleanText(
                          slide?.title
                        ),

                      playlist_item_id:
                        Number(
                          slide?.playlist_item_id
                          ||
                          0
                        ),

                      order_index:
                        Number(
                          slide?.order_index
                          ||
                          0
                        ),
                    };
                  }
                )
              );

            if (
              cancelled
            ) {
              return;
            }

            const seen =
              new Set();

            const unique =
              resolved
                .filter(Boolean)
                .filter(
                  (photo) => {
                    const key =
                      photo
                        ?.photo_library_id
                        ? `library:${photo.photo_library_id}`
                        : `url:${cleanText(photo?.image_url)}`;

                    if (
                      seen.has(
                        key
                      )
                    ) {
                      return false;
                    }

                    seen.add(
                      key
                    );

                    return true;
                  }
                )
                .sort(
                  (
                    a,
                    b
                  ) =>
                    Number(
                      a?.order_index
                      ||
                      0
                    )
                    -
                    Number(
                      b?.order_index
                      ||
                      0
                    )
                );

            setSuggestedPhotos(
              unique
            );

          } catch (err) {
            if (
              !cancelled
            ) {
              setSuggestedPhotos([]);

              setSuggestedPhotosError(
                err?.message
                ||
                "Failed to load photos used with this palette."
              );
            }

          } finally {
            if (
              !cancelled
            ) {
              setSuggestedPhotosLoading(
                false
              );
            }
          }
        }
      )();

      return () => {
        cancelled =
          true;
      };
    },
    [
      detail
        ?.viewer
        ?.saved_palette_id,
      playlistId,
    ]
  );


  function updateViewer(
    field,
    value
  ) {
    setDetail(
      (current) =>
        current
          ? {
              ...current,
              viewer: {
                ...current.viewer,
                [field]:
                  value,
              },
            }
          : current
    );

    setStatus(
      ""
    );
  }


  function photoIdentity(
    photo
  ) {
    const libraryId =
      Number(
        photo?.photo_library_id
        ||
        0
      );

    if (
      libraryId > 0
    ) {
      return `library:${libraryId}`;
    }

    const path =
      cleanText(
        photo?.rel_path
        ||
        photo?.raw_rel_path
        ||
        photo?.image_url
        ||
        photo?.file_path
      );

    return path
      ? `path:${path}`
      : "";
  }


  function samePhoto(
    a,
    b
  ) {
    const aId =
      Number(
        a?.photo_library_id
        ||
        0
      );

    const bId =
      Number(
        b?.photo_library_id
        ||
        0
      );

    if (
      aId > 0
      &&
      bId > 0
    ) {
      return aId === bId;
    }

    const aPath =
      cleanText(
        a?.rel_path
        ||
        a?.raw_rel_path
        ||
        a?.image_url
        ||
        a?.file_path
      );

    const bPath =
      cleanText(
        b?.rel_path
        ||
        b?.raw_rel_path
        ||
        b?.image_url
        ||
        b?.file_path
      );

    return Boolean(
      aPath
      &&
      bPath
      &&
      aPath === bPath
    );
  }


  function usedPhotoIndex(
    photo
  ) {
    return (
      detail?.photos?.findIndex(
        (row) =>
          samePhoto(
            row,
            photo
          )
      )
      ??
      -1
    );
  }


  function addPhoto(
    photo
  ) {
    setPickerOpen(
      false
    );

    const photoLibraryId =
      Number(
        photo?.photo_library_id
        ||
        0
      );

    const relPath =
      cleanText(
        photo?.rel_path
        ||
        photo?.raw_rel_path
        ||
        photo?.image_url
        ||
        photo?.file_path
      );

    if (
      photoLibraryId <= 0
      &&
      !relPath
    ) {
      setError(
        "That photo does not have a usable Photo Library ID or image path."
      );
      return;
    }

    setError(
      ""
    );

    setDetail(
      (current) => {
        if (
          !current
        ) {
          return current;
        }

        if (
          current.photos.some(
            (row) =>
              samePhoto(
                row,
                photo
              )
          )
        ) {
          return current;
        }

        const alreadyHasMain =
          current.photos.some(
            (row) =>
              cleanText(
                row?.photo_type
              ).toLowerCase()
              ===
              "full"
          );

        return {
          ...current,

          photos: [
            ...current.photos,

            {
              palette_viewer_photo_id:
                null,

              palette_viewer_id:
                current.viewer
                  .palette_viewer_id,

              photo_library_id:
                photoLibraryId > 0
                  ? photoLibraryId
                  : null,

              rel_path:
                relPath,

              photo_type:
                alreadyHasMain
                  ? "zoom"
                  : "full",

              trigger_mode:
                "any",

              trigger_color_id:
                null,

              caption:
                cleanText(
                  photo?.caption
                ),

              alt_text:
                cleanText(
                  photo?.alt_text
                  ||
                  photo?.title
                ),

              order_index:
                current.photos.length,
            },
          ],
        };
      }
    );

    setStatus(
      ""
    );
  }


  function setPhotoUse(
    photo,
    checked
  ) {
    if (
      checked
    ) {
      addPhoto(
        photo
      );

      return;
    }

    setDetail(
      (current) => {
        if (
          !current
        ) {
          return current;
        }

        return {
          ...current,

          photos:
            current.photos
              .filter(
                (row) =>
                  !samePhoto(
                    row,
                    photo
                  )
              )
              .map(
                (
                  row,
                  index
                ) => ({
                  ...row,
                  order_index:
                    index,
                })
              ),
        };
      }
    );

    setStatus(
      ""
    );
  }


  function setPhotoRole(
    photo,
    role
  ) {
    const isUsed =
      detail?.photos?.some(
        (row) =>
          samePhoto(
            row,
            photo
          )
      );

    if (
      !isUsed
    ) {
      return;
    }

    if (
      role ===
      "full"
    ) {
      const anotherMain =
        detail.photos.some(
          (row) =>
            !samePhoto(
              row,
              photo
            )
            &&
            cleanText(
              row?.photo_type
            ).toLowerCase()
            ===
            "full"
        );

      if (
        anotherMain
      ) {
        setMainConflictOpen(
          true
        );

        return;
      }
    }

    setDetail(
      (current) => {
        if (
          !current
        ) {
          return current;
        }

        return {
          ...current,

          photos:
            current.photos.map(
              (row) =>
                samePhoto(
                  row,
                  photo
                )
                  ? {
                      ...row,
                      photo_type:
                        role,
                      trigger_mode:
                        role === "before"
                          ? "none"
                          : "any",
                      caption:
                        role === "before"
                          ? "Before"
                          : "",
                    }
                  : row
            ),
        };
      }
    );

    setStatus(
      ""
    );
  }


  const photoChoices =
    useMemo(
      () => {
        const choices =
          [];

        const seen =
          new Set();

        for (
          const photo
          of [
            ...suggestedPhotos,
            ...(
              detail?.photos
              ||
              []
            ),
          ]
        ) {
          const key =
            photoIdentity(
              photo
            );

          if (
            !key
            ||
            seen.has(
              key
            )
          ) {
            continue;
          }

          seen.add(
            key
          );

          choices.push(
            photo
          );
        }

        return choices;
      },
      [
        suggestedPhotos,
        detail?.photos,
      ]
    );


  async function savePV() {
    if (
      !detail
      ||
      saving
    ) {
      return;
    }

    const viewer =
      detail.viewer;

    if (
      Number(
        viewer
          ?.saved_palette_id
        ||
        0
      ) <= 0
    ) {
      setError(
        "Choose a palette."
      );
      return;
    }

    if (
      !cleanText(
        viewer?.format
      )
    ) {
      setError(
        "Choose an experience."
      );
      return;
    }

    if (
      !cleanText(
        viewer?.title
      )
    ) {
      setError(
        "Enter a title."
      );
      return;
    }

    if (
      detail.photos.length
      &&
      detail.photos.filter(
        (photo) =>
          cleanText(
            photo?.photo_type
          ).toLowerCase()
          ===
          "full"
      ).length !==
        1
    ) {
      setError(
        "Choose exactly one Main photo."
      );
      return;
    }

    setSaving(
      true
    );

    setError(
      ""
    );

    setStatus(
      ""
    );

    try {
      const payload = {
        viewer: {
          ...viewer,

          saved_palette_id:
            Number(
              viewer
                .saved_palette_id
            ),

          format:
            cleanText(
              viewer
                .format
            ).toLowerCase(),

          kicker_text:
            cleanText(
              viewer
                .kicker_text
            )
            ||
            null,

          title:
            cleanText(
              viewer
                .title
            ),

          intro:
            String(
              viewer
                .intro
              ??
              ""
            ),

          is_active:
            Number(
              viewer
                .is_active
              ??
              1
            )
              ? 1
              : 0,
        },

        photos:
          detail.photos.map(
            (
              photo,
              index
            ) => ({
              ...photo,

              photo_library_id:
                Number(
                  photo
                    ?.photo_library_id
                  ||
                  0
                )
                ||
                null,

              rel_path:
                photo
                  ?.photo_library_id
                  ? ""
                  : cleanText(
                      photo?.rel_path
                    ),

              photo_type:
                cleanText(
                  photo
                    ?.photo_type
                ).toLowerCase()
                ||
                (
                  index === 0
                    ? "full"
                    : "zoom"
                ),

              trigger_mode:
                cleanText(
                  photo
                    ?.photo_type
                ).toLowerCase()
                ===
                "before"
                  ? "none"
                  : "any",

              trigger_color_id:
                null,

              caption:
                cleanText(
                  photo
                    ?.photo_type
                ).toLowerCase()
                ===
                "before"
                  ? "Before"
                  : null,

              alt_text:
                cleanText(
                  photo
                    ?.alt_text
                )
                ||
                null,

              order_index:
                index,
            })
          ),
      };

      const data =
        await readJson(
          await fetch(
            PV_ADMIN_URL,
            {
              method:
                "POST",

              credentials:
                "include",

              headers: {
                "Content-Type":
                  "application/json",
              },

              body:
                JSON.stringify(
                  payload
                ),
            }
          ),

          "Failed to save PV"
        );

      const saved =
        normalizePVDetail(
          data?.item,
          item
        );

      setDetail(
        saved
      );

      setStatus(
        "Saved."
      );

      onSaved?.(
        saved
      );

      return saved;

    } catch (err) {
      setError(
        err?.message
        ||
        "Failed to save PV."
      );

      return null;

    } finally {
      setSaving(
        false
      );
    }
  }


  useImperativeHandle(
    ref,
    () => ({
      save: savePV,
    }),
    [detail, saving]
  );



  if (
    loading
  ) {
    return (
      <AdminEmptyState
        title="PV"
        message="Loading PV..."
      />
    );
  }

  if (
    !detail
  ) {
    return (
      <AdminEmptyState
        title="PV could not load"
        message={
          error
        }
      />
    );
  }


  return (
    <>
      <AdminStack gap="md">
        {
          error
            ? (
                <AdminNotice variant="danger">
                  {error}
                </AdminNotice>
              )
            : null
        }

        {
          status
            ? (
                <AdminNotice variant="success">
                  {status}
                </AdminNotice>
              )
            : null
        }


        <AdminPanel
          title="PV"
          compact
        >
          <AdminStack gap="sm">
            <AdminToolbar compact>
              <AdminField
                label="Palette"
                compact
              >
                <select
                  className="admin-field__control"
                  value={
                    detail
                      .viewer
                      .saved_palette_id
                  }
                  disabled={
                    saving
                  }
                  onChange={(
                    event
                  ) =>
                    updateViewer(
                      "saved_palette_id",
                      event
                        .target
                        .value
                    )
                  }
                >
                  <option value="">
                    Choose a palette...
                  </option>

                  {
                    palettes.map(
                      (
                        palette
                      ) => (
                        <option
                          key={
                            palette
                              .saved_palette_id
                          }
                          value={
                            palette
                              .saved_palette_id
                          }
                        >
                          {
                            paletteLabel(
                              palette
                            )
                          }
                        </option>
                      )
                    )
                  }
                </select>
              </AdminField>

              <AdminField
                label="Experience"
                compact
              >
                <select
                  className="admin-field__control"
                  value={
                    detail
                      .viewer
                      .format
                  }
                  disabled={
                    saving
                  }
                  onChange={(
                    event
                  ) =>
                    updateViewer(
                      "format",
                      event
                        .target
                        .value
                    )
                  }
                >
                  {
                    EXPERIENCE_OPTIONS.map(
                      (
                        option
                      ) => (
                        <option
                          key={
                            option.value
                          }
                          value={
                            option.value
                          }
                        >
                          {
                            option.label
                          }
                        </option>
                      )
                    )
                  }
                </select>
              </AdminField>
            </AdminToolbar>

            <AdminToolbar compact>
              <AdminField
                label="Kicker"
                compact
              >
                <KickerDropdown
                  textValue={
                    detail
                      .viewer
                      .kicker_text
                    ||
                    ""
                  }
                  blankLabel="Saved kickers..."
                  onChange={(
                    _,
                    kicker
                  ) =>
                    updateViewer(
                      "kicker_text",
                      kicker
                        ?.display_text
                      ||
                      ""
                    )
                  }
                />
              </AdminField>

              <AdminField
                label="Custom"
                compact
              >
                <input
                  className="admin-field__control"
                  type="text"
                  value={
                    detail
                      .viewer
                      .kicker_text
                    ||
                    ""
                  }
                  disabled={
                    saving
                  }
                  onChange={(
                    event
                  ) =>
                    updateViewer(
                      "kicker_text",
                      event
                        .target
                        .value
                    )
                  }
                />
              </AdminField>
            </AdminToolbar>

            <AdminField
              label="Title"
              compact
            >
              <input
                className="admin-field__control"
                type="text"
                value={
                  detail
                    .viewer
                    .title
                  ||
                  ""
                }
                disabled={
                  saving
                }
                onChange={(
                  event
                ) =>
                  updateViewer(
                    "title",
                    event
                      .target
                      .value
                  )
                }
              />
            </AdminField>

            <AdminField
              label="Intro"
              compact
            >
              <textarea
                className="admin-field__control"
                rows={
                  3
                }
                value={
                  detail
                    .viewer
                    .intro
                  ||
                  ""
                }
                disabled={
                  saving
                }
                onChange={(
                  event
                ) =>
                  updateViewer(
                    "intro",
                    event
                      .target
                      .value
                  )
                }
              />
            </AdminField>
          </AdminStack>
        </AdminPanel>

        <AdminPanel
          title="Photos"
          compact
          actions={
            <AdminButton
              type="button"
              variant="secondary"
              disabled={
                saving
              }
              onClick={() =>
                setPickerOpen(
                  true
                )
              }
            >
              Add from Library
            </AdminButton>
          }
        >
          <AdminStack gap="sm">
            {
              suggestedPhotosLoading
                ? (
                    <AdminMetaText as="div">
                      Loading related playlist photos...
                    </AdminMetaText>
                  )
                : suggestedPhotosError
                  ? (
                      <AdminNotice variant="danger">
                        {suggestedPhotosError}
                      </AdminNotice>
                    )
                  : null
            }

            {
              photoChoices.length
                ? (
                    <AdminToolbar compact>
                      {
                        photoChoices.map(
                          (
                            photo,
                            index
                          ) => {
                            const key =
                              photoIdentity(
                                photo
                              )
                              ||
                              `photo-${index}`;

                            const usedIndex =
                              usedPhotoIndex(
                                photo
                              );

                            const used =
                              usedIndex >=
                              0;

                            const usedPhoto =
                              used
                                ? detail
                                    .photos[
                                      usedIndex
                                    ]
                                : photo;

                            const role =
                              cleanText(
                                usedPhoto
                                  ?.photo_type
                              ).toLowerCase()
                              ||
                              "zoom";

                            const imageUrl =
                              photoImageUrl(
                                photo
                              )
                              ||
                              photoImageUrl(
                                usedPhoto
                              );

                            return (
                              <AdminStack
                                key={
                                  key
                                }
                                gap="xs"
                              >
                                {
                                  imageUrl
                                    ? (
                                        <img
                                          src={
                                            imageUrl
                                          }
                                          alt={
                                            photoLabel(
                                              photo
                                            )
                                          }
                                          width="180"
                                          loading="lazy"
                                        />
                                      )
                                    : (
                                        <AdminMetaText as="div">
                                          No preview
                                        </AdminMetaText>
                                      )
                                }

                                <div
                                  style={{
                                    display:
                                      "flex",

                                    alignItems:
                                      "center",

                                    justifyContent:
                                      "space-between",

                                    width:
                                      180,

                                    minHeight:
                                      24,
                                  }}
                                >
                                  <label
                                    style={{
                                      display:
                                        "flex",

                                      alignItems:
                                        "center",

                                      gap:
                                        4,

                                      height:
                                        24,

                                      lineHeight:
                                        "24px",
                                    }}
                                  >
                                    <input
                                      type="checkbox"
                                      checked={
                                        used
                                      }
                                      disabled={
                                        saving
                                      }
                                      style={{
                                        margin:
                                          0,
                                      }}
                                      onChange={(
                                        event
                                      ) =>
                                        setPhotoUse(
                                          photo,
                                          event
                                            .target
                                            .checked
                                        )
                                      }
                                    />

                                    <span>
                                      Use
                                    </span>
                                  </label>

                                  <select
                                    aria-label="Photo role"
                                    value={
                                      role
                                    }
                                    disabled={
                                      saving
                                      ||
                                      !used
                                    }
                                    style={{
                                      width:
                                        "auto",

                                      minWidth:
                                        0,

                                      height:
                                        24,

                                      margin:
                                        0,

                                      padding:
                                        "0 22px 0 6px",

                                      fontSize:
                                        12,

                                      lineHeight:
                                        "22px",
                                    }}
                                    onChange={(
                                      event
                                    ) =>
                                      setPhotoRole(
                                        photo,
                                        event
                                          .target
                                          .value
                                      )
                                    }
                                  >
                                    <option value="full">
                                      Main
                                    </option>

                                    <option value="zoom">
                                      Zoom
                                    </option>

                                    <option value="before">
                                      Before
                                    </option>
                                  </select>
                                </div>
                              </AdminStack>
                            );
                          }
                        )
                      }
                    </AdminToolbar>
                  )
                : (
                    <AdminEmptyState
                      title="No photos available"
                      message="No related playlist photos are available for this palette. Add from Library if needed."
                    />
                  )
            }
          </AdminStack>
        </AdminPanel>

      </AdminStack>

      <PhotoPickerDialog
        open={
          pickerOpen
        }
        title="Add PV Photo"
        onClose={() =>
          setPickerOpen(
            false
          )
        }
        onPick={
          addPhoto
        }
      />

      <AdminDialog
        open={
          mainConflictOpen
        }
        title="Main Photo"
        width={
          420
        }
        actions={
          <AdminButton
            type="button"
            onClick={() =>
              setMainConflictOpen(
                false
              )
            }
          >
            OK
          </AdminButton>
        }
        onClose={() =>
          setMainConflictOpen(
            false
          )
        }
        onCancel={() =>
          setMainConflictOpen(
            false
          )
        }
      >
        <AdminMetaText as="div">
          Only one used photo can be Main. Change the current Main photo first.
        </AdminMetaText>
      </AdminDialog>
    </>
  );
});


export default function ProjectPVPage({
  projectId,
  playlistId = null,
  onRex = null,
}) {
  const [
    palettes,
    setPalettes,
  ] = useState([]);

  const [
    items,
    setItems,
  ] = useState([]);

  const [
    loading,
    setLoading,
  ] = useState(true);

  const [
    error,
    setError,
  ] = useState("");

  const [
    selectedKey,
    setSelectedKey,
  ] = useState(null);

  const pvEditorRef =
    useRef(null);


  const [
    newOpen,
    setNewOpen,
  ] = useState(false);

  const [
    newPaletteId,
    setNewPaletteId,
  ] = useState("");

  const [
    newExperience,
    setNewExperience,
  ] = useState("");

  const [
    newTitle,
    setNewTitle,
  ] = useState("");

  const [
    createError,
    setCreateError,
  ] = useState("");

  const [
    creating,
    setCreating,
  ] = useState(false);


  const loadPage =
    useCallback(
      async () => {
        const id =
          Number(
            projectId ||
            0
          );

        if (id <= 0) {
          setPalettes([]);
          setItems([]);
          setLoading(false);
          setError("");
          return;
        }

        setLoading(true);
        setError("");

        try {
          const paletteParams =
            new URLSearchParams({
              project_id:
                String(
                  id
                ),

              _:
                String(
                  Date.now()
                ),
            });

          const paletteData =
            await readJson(
              await fetch(
                `${PROJECT_PALETTES_URL}?${paletteParams.toString()}`,
                {
                  credentials:
                    "include",

                  cache:
                    "no-store",
                }
              ),

              "Failed to load project palettes"
            );

          const projectPalettes =
            Array.isArray(
              paletteData?.items
            )
              ? paletteData.items
              : [];

          setPalettes(
            projectPalettes
          );

          const paletteIds =
            projectPalettes
              .map(
                (row) =>
                  Number(
                    row?.saved_palette_id
                    ||
                    0
                  )
              )
              .filter(
                (value) =>
                  value > 0
              );

          if (!paletteIds.length) {
            setItems([]);
            return;
          }

          const pvParams =
            new URLSearchParams({
              saved_palette_ids:
                paletteIds.join(
                  ","
                ),

              _:
                String(
                  Date.now()
                ),
            });

          const pvData =
            await readJson(
              await fetch(
                `${PV_LIST_URL}?${pvParams.toString()}`,
                {
                  credentials:
                    "include",

                  cache:
                    "no-store",
                }
              ),

              "Failed to load PVs"
            );

          setItems(
            Array.isArray(
              pvData?.items
            )
              ? pvData.items
              : []
          );

        } catch (err) {
          setPalettes([]);
          setItems([]);

          setError(
            err?.message
            ||
            "Failed to load PVs."
          );

        } finally {
          setLoading(
            false
          );
        }
      },
      [
        projectId,
      ]
    );


  useEffect(
    () => {
      void loadPage();
    },
    [
      loadPage,
    ]
  );


  useEffect(
    () => {
      setSelectedKey(
        null
      );

      setNewOpen(
        false
      );

      setNewPaletteId(
        ""
      );

      setNewExperience(
        ""
      );

      setNewTitle(
        ""
      );

      setCreateError(
        ""
      );
    },
    [
      projectId,
    ]
  );


  const columns =
    useMemo(
      () => [
        {
          key:
            "handle",

          label:
            "Handle",

          sortable:
            true,

          value:
            (item) =>
              cleanText(
                item?.handle
              )
              ||
              derivedHandle(
                item
              ),
        },

        {
          key:
            "palette",

          label:
            "Palette",

          sortable:
            true,

          value:
            (item) =>
              cleanText(
                item?.palette_name
              )
              ||
              `Palette #${item?.saved_palette_id || ""}`,
        },

        {
          key:
            "experience",

          label:
            "Experience",

          sortable:
            true,

          value:
            (item) =>
              experienceLabel(
                item?.format
                ??
                item?.experience_key
              ),
        },

        {
          key:
            "photos",

          label:
            "Photos",

          sortable:
            true,

          value:
            (item) =>
              Number(
                item?.photo_count
                ??
                0
              ),
        },

      ],
      []
    );


  async function viewPVFromGrid(
    item
  ) {
    const pvId =
      Number(
        item?.palette_viewer_id
        ||
        0
      );

    if (
      pvId <= 0
    ) {
      setError(
        "This PV does not have a valid ID."
      );

      return;
    }

    setError(
      ""
    );

    try {
      const data =
        await readJson(
          await fetch(
            `${PV_ADMIN_URL}?id=${encodeURIComponent(
              pvId
            )}&_=${Date.now()}`,
            {
              credentials:
                "include",

              cache:
                "no-store",
            }
          ),

          "Failed to load PV"
        );

      const rexUrl =
        rexUrlFromDetail(
          data?.item
        );

      if (
        !rexUrl
      ) {
        throw new Error(
          "This PV does not currently have a viewer URL."
        );
      }

      window.location.href =
        rexAdminUrl(
          rexUrl
        );

    } catch (err) {
      setError(
        err?.message
        ||
        "Could not open PV."
      );
    }
  }


  function openNewPV() {
    const onePalette =
      palettes.length === 1
        ? palettes[0]
        : null;

    setNewPaletteId(
      onePalette
        ? String(
            onePalette
              .saved_palette_id
          )
        : ""
    );

    setNewExperience(
      ""
    );

    setNewTitle(
      onePalette
        ? paletteLabel(
            onePalette
          )
        : ""
    );

    setCreateError(
      ""
    );

    setNewOpen(
      true
    );
  }


  function closeNewPV() {
    if (creating) {
      return;
    }

    setNewOpen(
      false
    );

    setCreateError(
      ""
    );
  }


  function handlePaletteChange(
    value
  ) {
    const nextValue =
      String(
        value ||
        ""
      );

    const previousPalette =
      palettes.find(
        (row) =>
          String(
            row?.saved_palette_id
            ??
            ""
          ) ===
          String(
            newPaletteId
          )
      );

    const nextPalette =
      palettes.find(
        (row) =>
          String(
            row?.saved_palette_id
            ??
            ""
          ) ===
          nextValue
      );

    const previousAutoTitle =
      previousPalette
        ? paletteLabel(
            previousPalette
          )
        : "";

    setNewPaletteId(
      nextValue
    );

    if (
      !cleanText(
        newTitle
      )
      ||
      (
        previousAutoTitle
        &&
        cleanText(
          newTitle
        ) ===
        previousAutoTitle
      )
    ) {
      setNewTitle(
        nextPalette
          ? paletteLabel(
              nextPalette
            )
          : ""
      );
    }

    setCreateError(
      ""
    );
  }


  const canCreate =
    Number(
      newPaletteId ||
      0
    ) > 0
    &&
    cleanText(
      newExperience
    ) !== ""
    &&
    cleanText(
      newTitle
    ) !== ""
    &&
    !creating;


  async function createPV() {
    if (!canCreate) {
      return;
    }

    setCreating(
      true
    );

    setCreateError(
      ""
    );

    try {
      const data =
        await readJson(
          await fetch(
            PV_CREATE_URL,
            {
              method:
                "POST",

              credentials:
                "include",

              headers: {
                "Content-Type":
                  "application/json",
              },

              body:
                JSON.stringify({
                  saved_palette_id:
                    Number(
                      newPaletteId
                    ),

                  experience:
                    cleanText(
                      newExperience
                    ),

                  title:
                    cleanText(
                      newTitle
                    ),
                }),
            }
          ),

          "Failed to create PV"
        );

      const created =
        data?.item
        ||
        null;

      if (
        !created
        ||
        Number(
          created
            ?.palette_viewer_id
          ||
          0
        ) <= 0
      ) {
        throw new Error(
          "PV create did not return a valid PV."
        );
      }

      setItems(
        (current) => [
          ...current.filter(
            (row) =>
              Number(
                row?.palette_viewer_id
                ||
                0
              ) !==
              Number(
                created
                  .palette_viewer_id
              )
          ),

          created,
        ]
      );

      setSelectedKey(
        Number(
          created
            .palette_viewer_id
        )
      );

      setNewOpen(
        false
      );

      setNewPaletteId(
        ""
      );

      setNewExperience(
        ""
      );

      setNewTitle(
        ""
      );

    } catch (err) {
      setCreateError(
        err?.message
        ||
        "Failed to create PV."
      );

    } finally {
      setCreating(
        false
      );
    }
  }


  const selectedPV =
    items.find(
      (item) =>
        Number(
          item?.palette_viewer_id
          ||
          0
        ) ===
        Number(
          selectedKey
          ||
          0
        )
    )
    ||
    null;


  const detailActions = (
    <>
      <AdminButton
        type="button"
        onClick={openNewPV}
        disabled={loading}
      >
        New PV
      </AdminButton>

      <AdminButton
        type="button"
        variant="secondary"
        disabled={!selectedPV}
        onClick={() => {
          if (selectedPV) {
            void viewPVFromGrid(
              selectedPV
            );
          }
        }}
      >
        View
      </AdminButton>

      <FetchRexButton
        buttonLabel="R↗"
        disabled={!selectedPV}
        request={
          selectedPV
            ? {
                label:
                  cleanText(
                    selectedPV?.title
                  )
                  ||
                  derivedHandle(
                    selectedPV
                  ),

                resolverKey:
                  "viewer",

                resourceType:
                  "palette_viewer",

                resourceId:
                  Number(
                    selectedPV?.palette_viewer_id
                    ||
                    0
                  ),

                context: {
                  format:
                    cleanText(
                      selectedPV?.format
                      ??
                      selectedPV?.experience_key
                    )
                    ||
                    "public",
                },
              }
            : null
        }
        resolveExistingUrl={async () => {
          const pvId =
            Number(
              selectedPV?.palette_viewer_id
              ||
              0
            );

          if (pvId <= 0) {
            return "";
          }

          const data =
            await readJson(
              await fetch(
                `${PV_ADMIN_URL}?id=${encodeURIComponent(
                  pvId
                )}&_=${Date.now()}`,
                {
                  credentials:
                    "include",

                  cache:
                    "no-store",
                }
              ),

              "Failed to load PV"
            );

          return rexUrlFromDetail(
            data?.item
          );
        }}
        onCreated={() => {
          void loadPage();
        }}
      />

      <AdminMetaText as="div">
        {items.length} PV{items.length === 1 ? "" : "s"}
      </AdminMetaText>
    </>
  );


  const dialogActions = [
    {
      key:
        "cancel",

      label:
        "Cancel",

      variant:
        "secondary",

      disabled:
        creating,

      onClick:
        closeNewPV,
    },

    {
      key:
        "create",

      label:
        creating
          ? "Creating…"
          : "Create",

      variant:
        "primary",

      disabled:
        !canCreate,

      onClick:
        createPV,
    },
  ];


  return (
    <>
      <AdminDetailPane
        ariaLabel="Project PVs"
        title="PVs"
        actions={detailActions}
      >
        {
          error
            ? (
                <AdminNotice variant="danger">
                  {error}
                </AdminNotice>
              )
            : null
        }

        <AdminSmartGrid
          items={
            items
          }

        columns={
          columns
        }

        getRowKey={(
          item
        ) =>
          Number(
            item?.palette_viewer_id
            ||
            0
          )
        }

        selectedKey={
          selectedKey
        }

        onSelectionChange={(
          item,
          key
        ) =>
          setSelectedKey(
            key
            ??
            item?.palette_viewer_id
            ??
            null
          )
        }

        defaultSortKey="handle"
        defaultSortDirection="asc"
        ariaLabel="Project PVs"
        verticalAlign="middle"

        drawer={{
          title:
            (item) =>
              cleanText(
                item?.handle
              )
              ||
              derivedHandle(
                item
              ),

          width:
            720,

          padded:
            true,

          closeLabel:
            "Save & Close",

          beforeClose:
            async () => {
              const saved =
                await pvEditorRef.current?.save?.();

              if (!saved) {
                return false;
              }

              // The drawer is the single commit point.
              // Reload so the PV list and editor both come back from the DB.
              window.location.reload();

              return false;
            },

          footer:
            ({
              close,
              closing,
            }) => (
              <button
                type="button"
                className="admin-button admin-button--primary"
                disabled={closing}
                onClick={close}
              >
                {closing
                  ? "Saving..."
                  : "Save & Close"}
              </button>
            ),

          render:
            ({
              item,
            }) => (
              <PVDrawerEditor
                ref={pvEditorRef}
                item={
                  item
                }
                palettes={
                  palettes
                }
                playlistId={
                  playlistId
                }
                onSaved={() => {
                  void loadPage();
                }}
              />
            ),
        }}
      />

      {
        loading
        &&
        !items.length
          ? (
              <AdminEmptyState
                title="PVs"
                message="Loading project PVs..."
              />
            )
          : null
      }

        {
          !loading
          &&
          !error
          &&
          !items.length
            ? (
                <AdminEmptyState
                  title="No PVs yet"
                  message="Click New PV to create the first PV for this project."
                />
              )
            : null
        }
      </AdminDetailPane>


      <AdminDialog
        open={
          newOpen
        }

        title="New PV"

        width={
          480
        }

        actions={
          dialogActions
        }

        onCancel={
          closeNewPV
        }

        onClose={
          closeNewPV
        }

        dismissOnBackdrop={
          !creating
        }
      >
        <AdminStack gap="md">
          {
            createError
              ? (
                  <AdminNotice variant="danger">
                    {createError}
                  </AdminNotice>
                )
              : null
          }

          {
            !palettes.length
              ? (
                  <AdminNotice variant="warning">
                    This project has no palettes yet.
                  </AdminNotice>
                )
              : null
          }

          <AdminField label="Palette">
            <select
              className="admin-field__control"
              value={
                newPaletteId
              }
              disabled={
                creating
                ||
                !palettes.length
              }
              onChange={(
                event
              ) =>
                handlePaletteChange(
                  event.target.value
                )
              }
            >
              <option value="">
                Choose a palette...
              </option>

              {
                palettes.map(
                  (palette) => (
                    <option
                      key={
                        palette
                          .saved_palette_id
                      }
                      value={
                        palette
                          .saved_palette_id
                      }
                    >
                      {
                        paletteLabel(
                          palette
                        )
                      }
                    </option>
                  )
                )
              }
            </select>
          </AdminField>

          <AdminField label="Experience">
            <select
              className="admin-field__control"
              value={
                newExperience
              }
              disabled={
                creating
              }
              onChange={(
                event
              ) => {
                setNewExperience(
                  event.target.value
                );

                setCreateError(
                  ""
                );
              }}
            >
              <option value="">
                Choose an experience...
              </option>

              {
                EXPERIENCE_OPTIONS.map(
                  (option) => (
                    <option
                      key={
                        option.value
                      }
                      value={
                        option.value
                      }
                    >
                      {
                        option.label
                      }
                    </option>
                  )
                )
              }
            </select>
          </AdminField>

          <AdminField label="Title">
            <input
              className="admin-field__control"
              type="text"
              value={
                newTitle
              }
              disabled={
                creating
              }
              onChange={(
                event
              ) => {
                setNewTitle(
                  event.target.value
                );

                setCreateError(
                  ""
                );
              }}
              onKeyDown={(
                event
              ) => {
                if (
                  event.key ===
                  "Enter"
                  &&
                  canCreate
                ) {
                  event.preventDefault();

                  void createPV();
                }
              }}
            />
          </AdminField>
        </AdminStack>
      </AdminDialog>
    </>
  );
}

import {
  useEffect,
  useMemo,
  useState,
} from "react";

import {
  createPortal,
} from "react-dom";

import PubVideoPlayer from "./PubVideoPlayer";
import PubMusicEditor from "./PubMusicEditor";
import FuzzySearchColorSelect from "@components/FuzzySearchColorSelect";
import { API_FOLDER } from "@helpers/config";


const REX_PLAYLIST_EXPERIENCES_URL =
  `${API_FOLDER}/v2/admin/rex/playlist-experiences.php`;

const COLORFIX_PUBLIC_ORIGIN =
  "https://colorfix.terrymarr.com";

const COLORFIX_HOME_URL =
  `${COLORFIX_PUBLIC_ORIGIN}/`;

const DEFAULT_YOUTUBE_THUMBNAIL_TEXT_COLOR =
  "#FFFFFF";


const VIDEO_INGREDIENT_FIELDS = {
  pin_before_after_video: [
    {
      key: "end_slide_text",
      label: "End Slide Text",
      type: "textarea",
      rows: 1,
    },
  ],

  youtube_video: [],
};


export default function PubVideoAssetEditor({
  asset,
  ingredientValues = {},
  ingredientBindings = null,
  saving = false,
  recreating = false,
  sendingToPackaging = false,
  approvalSaving = false,
  onSetApproval,
  onSave,
  onRecreate,
  onSendToPackaging,
  onRefreshAssets,
  onClose,
}) {
  const [
    title,
    setTitle,
  ] = useState("");

  const [
    description,
    setDescription,
  ] = useState("");

  const [
    originalTitle,
    setOriginalTitle,
  ] = useState("");

  const [
    originalDescription,
    setOriginalDescription,
  ] = useState("");

  const [
    insideValues,
    setInsideValues,
  ] = useState({});

  const [
    originalInsideValues,
    setOriginalInsideValues,
  ] = useState({});

  const [
    successMessage,
    setSuccessMessage,
  ] = useState("");

  const [
    actionError,
    setActionError,
  ] = useState("");

  const [
    musicOpen,
    setMusicOpen,
  ] = useState(false);

  const [
    redoWarningOpen,
    setRedoWarningOpen,
  ] = useState(false);

  const [
    rexLinks,
    setRexLinks,
  ] = useState({
    colorsUsedUrl: "",
    playlistUrl: "",
  });

  const [
    rexLinksLoading,
    setRexLinksLoading,
  ] = useState(false);

  const [
    rexLinksError,
    setRexLinksError,
  ] = useState("");

  /*
   * Preview cache-buster.
   *
   * PUB deliberately reuses the same durable MP4/JPEG URLs across REDO.
   * Do not depend on updated_at/checksum being present in the admin payload.
   * A fresh editor mount gets a fresh token, forcing the browser to fetch
   * the current bytes for both video and thumbnail.
   */
  const [
    previewVersion,
  ] = useState(
    () => Date.now()
  );


  const assetType =
    useMemo(
      () =>
        String(
          asset?.asset_type ||
          ""
        )
          .trim()
          .toLowerCase(),
      [
        asset?.asset_type,
      ]
    );


  const ingredientFields =
    useMemo(
      () =>
        VIDEO_INGREDIENT_FIELDS[
          assetType
        ] || [],
      [
        assetType,
      ]
    );


  const isYouTubeVideo =
    assetType ===
      "youtube_video";


  const sourcePlaylistId =
    useMemo(
      () => {
        const sourceType =
          String(
            asset?.source_type ||
            ""
          )
            .trim()
            .toLowerCase();

        if (
          sourceType === "playlist"
          &&
          Number(
            asset?.source_id ||
            0
          ) > 0
        ) {
          return Number(
            asset.source_id
          );
        }

        const candidates = [
          asset?.playlist_id,
          asset?.source_playlist_id,
          ingredientValues?.playlist_id,
          ingredientValues?.source_playlist_id,
          ingredientValues?.playlist?.playlist_id,
        ];

        for (
          const candidate
          of candidates
        ) {
          const id =
            Number(
              candidate ||
              0
            );

          if (id > 0) {
            return id;
          }
        }

        return 0;
      },
      [
        asset?.source_type,
        asset?.source_id,
        asset?.playlist_id,
        asset?.source_playlist_id,
        ingredientValues,
      ]
    );


  const thumbnailTextColor =
    normalizeThumbnailTextColor(
      insideValues
        ?.cover
        ?.text_color
    );

  const thumbnailTextColorPickerValue =
    useMemo(
      () =>
        thumbnailPickerValueFromHex(
          thumbnailTextColor
        ),
      [
        thumbnailTextColor,
      ]
    );


  /*
   * A field may look like outside metadata in the editor and still
   * be baked into the physical asset.
   *
   * Prefer explicit contract bindings supplied by the parent/backend.
   * Also fall back to a same-name ingredient binding when that field
   * already exists in the loaded ingredient box. This protects older
   * asset types while their contract plumbing catches up.
   */
  const effectiveIngredientBindings =
    useMemo(
      () =>
        resolveIngredientBindings(
          ingredientBindings ??
          asset?.ingredient_bindings ??
          [],
          ingredientValues
        ),
      [
        ingredientBindings,
        asset?.ingredient_bindings,
        ingredientValues,
      ]
    );


  useEffect(() => {
    const nextTitle =
      asset?.search_title ||
      "";

    const nextDescription =
      asset?.description ||
      "";


    setTitle(
      nextTitle
    );

    setDescription(
      nextDescription
    );

    setOriginalTitle(
      nextTitle
    );

    setOriginalDescription(
      nextDescription
    );


    const editable =
      buildEditableIngredientValues(
        assetType,
        ingredientFields,
        ingredientValues
      );


    setInsideValues(
      editable
    );

    setOriginalInsideValues(
      cloneValue(
        editable
      )
    );

    setSuccessMessage(
      ""
    );

    setActionError(
      ""
    );

    setMusicOpen(
      false
    );

    setRedoWarningOpen(
      false
    );

    setRexLinks({
      colorsUsedUrl: "",
      playlistUrl: "",
    });

    setRexLinksError(
      ""
    );
  }, [
    asset?.pub_asset_id,
    asset?.search_title,
    asset?.description,
    assetType,
    ingredientFields,
    ingredientValues,
  ]);


  useEffect(() => {
    if (
      !isYouTubeVideo
      ||
      sourcePlaylistId <= 0
    ) {
      setRexLinks({
        colorsUsedUrl: "",
        playlistUrl: "",
      });

      setRexLinksError(
        ""
      );

      return;
    }

    let cancelled =
      false;

    async function loadRexLinks() {
      setRexLinksLoading(
        true
      );

      setRexLinksError(
        ""
      );

      try {
        const params =
          new URLSearchParams({
            playlist_id:
              String(
                sourcePlaylistId
              ),

            _:
              String(
                Date.now()
              ),
          });

        const response =
          await fetch(
            `${REX_PLAYLIST_EXPERIENCES_URL}?${params.toString()}`,
            {
              credentials:
                "include",
            }
          );

        const payload =
          await response.json();

        if (
          !response.ok
          ||
          !payload?.ok
        ) {
          throw new Error(
            payload?.error ||
            "Could not load REX links."
          );
        }

        const publicExperience =
          payload?.data
            ?.experiences
            ?.public ||
          null;

        const resolved =
          resolveYouTubeDescriptionRexLinks(
            publicExperience
          );

        if (!cancelled) {
          setRexLinks(
            resolved
          );

          if (
            !resolved.colorsUsedUrl
            &&
            Number(
              publicExperience
                ?.primary_viewer_count ||
              0
            ) > 0
          ) {
            setRexLinksError(
              "Colors Used REX is not ready. Reconcile the Playlist REX graph."
            );
          }
        }
      } catch (
        error
      ) {
        if (!cancelled) {
          setRexLinks({
            colorsUsedUrl: "",
            playlistUrl: "",
          });

          setRexLinksError(
            error?.message ||
            "Could not load REX links."
          );
        }
      } finally {
        if (!cancelled) {
          setRexLinksLoading(
            false
          );
        }
      }
    }

    loadRexLinks();

    return () => {
      cancelled =
        true;
    };
  }, [
    isYouTubeVideo,
    sourcePlaylistId,
    asset?.pub_asset_id,
  ]);


  if (!asset) {
    return null;
  }


  const busy =
    saving ||
    recreating ||
    sendingToPackaging;


  /*
   * SHIPPED is terminal historical state.
   *
   * Once Dispatch succeeds, the production order is archived/deleted.
   * This editor may still display the finished asset, but it must not
   * imply that the historical record can be edited, REDO, or re-packaged.
   */
  const pipelineStage =
    String(
      asset?.pipeline_stage ||
      ""
    )
      .trim()
      .toLowerCase();

  const isShipping =
    pipelineStage ===
      "shipping";

  const isShipped =
    pipelineStage ===
      "shipped";

  const isDispatchLocked =
    isShipping ||
    isShipped;

  const editorLocked =
    busy ||
    isDispatchLocked;


  const isApproved =
    Number(
      asset?.approved ||
      0
    ) === 1;


  const approvalEditable =
    pipelineStage ===
      "created" &&
    !isDispatchLocked;


  const directIngredientChanges =
    diffTopLevel(
      originalInsideValues,
      insideValues
    );


  const metadataValues = {
    search_title:
      title,

    description:
      description,
  };


  const originalMetadataValues = {
    search_title:
      originalTitle,

    description:
      originalDescription,
  };


  const boundIngredientChanges =
    buildBoundIngredientChanges(
      effectiveIngredientBindings,
      ingredientValues,
      originalMetadataValues,
      metadataValues
    );


  const ingredientChanges =
    mergeDeep(
      directIngredientChanges,
      boundIngredientChanges
    );


  const hasIngredientChanges =
    Object.keys(
      ingredientChanges
    ).length > 0;


  const redoReasons =
    describeAllIngredientChanges(
      directIngredientChanges,
      boundIngredientChanges,
      originalInsideValues,
      insideValues,
      ingredientFields,
      effectiveIngredientBindings
    );


  function currentChanges(
    includeIngredients
  ) {
    return {
      search_title:
        title,

      description:
        description,

      /*
       * Metadata is always sent as metadata.
       *
       * If a metadata field is also bound into the Creator ingredients,
       * buildBoundIngredientChanges() mirrors its edited value into the
       * ingredient patch. That makes REDO depend on the actual dish
       * contract, not on where a control happens to appear in the UI.
       *
       * ingredient_changes is included only when the caller is
       * deliberately committing baked-in production changes.
       */
      ingredient_changes:
        includeIngredients
          ? ingredientChanges
          : {},
    };
  }

function addYouTubeSource(
  url
) {
  const value =
    String(
      url ||
      ""
    )
      .trim();

  if (!value) {
    return "";
  }

  try {
    const parsed =
      new URL(
        value,
        COLORFIX_PUBLIC_ORIGIN
      );

    parsed.searchParams.set(
      "src",
      "yt"
    );

    return parsed.toString();
  } catch {
    return value;
  }
}




function appendDescriptionLink(
  label,
  url
) {
  const cleanUrl =
    addYouTubeSource(
      url
    );

  if (
    !cleanUrl
    ||
    editorLocked
  ) {
    return;
  }

  setDescription(
    (
      current
    ) =>
      appendDescriptionLine(
        current,
        `${label}: ${cleanUrl}`
      )
  );

  setSuccessMessage(
    ""
  );

  setActionError(
    ""
  );
}


  async function handleSave(
    event
  ) {
    event.preventDefault();

    if (isDispatchLocked) {
      return;
    }

    setSuccessMessage(
      ""
    );

    setActionError(
      ""
    );


    /*
     * Any actual ingredient diff means the current physical asset
     * would become stale. Do not save yet. Ask first.
     */
    if (
      hasIngredientChanges
    ) {
      setRedoWarningOpen(
        true
      );

      return;
    }


    const result =
      await onSave?.(
        currentChanges(
          false
        )
      );


    if (
      result !== false
    ) {
      setSuccessMessage(
        "Changes saved."
      );
    }
  }


  /**
   * Commit the current order first, then REDO from durable storage.
   *
   * This method is used by:
   *   - confirmed Save & Redo after ingredient edits
   *   - the explicit Redo Video button for recipe-only testing
   */
  async function commitAndRedo() {
    if (isDispatchLocked) {
      return;
    }

    setRedoWarningOpen(
      false
    );

    setSuccessMessage(
      ""
    );

    setActionError(
      ""
    );


    /*
     * 1. Persist the current metadata + any baked-in ingredient changes.
     *
     * REDO must always cook from the durable current order, never from
     * transient editor state.
     */
    const saved =
      await onSave?.(
        currentChanges(
          true
        )
      );


    if (
      saved === false
    ) {
      return;
    }


    /*
     * 2. Queue REDO.
     *
     * onRecreate should resolve as soon as CREATE has accepted/queued the
     * replacement asset. We do NOT wait for the physical video render.
     */
    const recreated =
      await onRecreate?.(
        asset.pub_asset_id
      );


    if (
      recreated === false
    ) {
      setSuccessMessage(
        "Changes were saved, but the redo could not be queued."
      );

      return;
    }


    /*
     * 3. The editor's work is done.
     *
     * Close immediately and refresh the asset table so the user returns
     * to the durable pipeline view and sees pipeline_stage = creating.
     *
     * Refresh is deliberately a parent responsibility because the table
     * owns the asset-list query.
     */
    onClose?.();

    await onRefreshAssets?.();
  }


  async function handleApprovalChange(
    event
  ) {
    const checked =
      event
        .target
        .checked;


    setSuccessMessage(
      ""
    );

    setActionError(
      ""
    );


    const result =
      await onSetApproval?.(
        asset,
        checked
      );


    if (
      result === false
      ||
      result?.ok === false
    ) {
      setActionError(
        result?.error ||
        "Could not change asset approval."
      );
    }
  }


  async function handleSendToPackaging() {
    if (isDispatchLocked) {
      return;
    }


    if (!isApproved) {
      setActionError(
        "Approve this asset before sending it to Packaging."
      );

      return;
    }

    setSuccessMessage(
      ""
    );

    setActionError(
      ""
    );


    /*
     * Do not hand a stale physical video to Package.
     *
     * If any current editor value belongs inside the Creator ingredients,
     * the existing video no longer matches the order and must be REDO first.
     */
    if (hasIngredientChanges) {
      setRedoWarningOpen(
        true
      );

      return;
    }


    /*
     * Metadata-only edits are safe to save immediately before handoff.
     */
    const saved =
      await onSave?.(
        currentChanges(
          false
        )
      );


    if (saved === false) {
      setActionError(
        "Could not save this video before Packaging."
      );

      return;
    }


    const result =
      await onSendToPackaging?.(
        asset.pub_asset_id
      );


    if (
      result === false
      ||
      result?.ok === false
    ) {
      setActionError(
        result?.error ||
        "Could not send this video to Packaging."
      );
    }
  }


  return createPortal(
    <div
      style={
        overlayStyle
      }

      onMouseDown={(
        event
      ) => {
        if (
          event.target ===
          event.currentTarget
          &&
          !busy
          &&
          !musicOpen
          &&
          !redoWarningOpen
        ) {
          onClose?.();
        }
      }}
    >
      <form
        style={
          dialogStyle
        }

        onSubmit={
          handleSave
        }
      >
        <div
          style={
            headerStyle
          }
        >
          <strong>
            Edit Video Asset
          </strong>

          <div
            style={
              headerRightStyle
            }
          >
            {isDispatchLocked ? (
              <div
                style={
                  shippedBadgeStyle
                }
              >
                {
                  isShipping
                    ? "SHIPPING"
                    : "SHIPPED"
                }
              </div>
            ) : null}

            <div
              style={
                assetIdStyle
              }
            >
              Runtime {
                formatDurationMs(
                  asset.duration_ms
                )
              }
            </div>

            <div
              style={
                assetIdStyle
              }
            >
              Asset #
              {
                asset
                  .pub_asset_id
              }
            </div>

            <button
              type="button"

              style={
                quietButtonStyle
              }

              disabled={
                busy
              }

              onClick={
                onClose
              }
            >
              ×
            </button>
          </div>
        </div>


        <div
          style={
            bodyStyle
          }
        >
          <div
            style={
              playerColumnStyle
            }
          >
            <PubVideoPlayer
              src={
                versionedPreviewUrl(
                  asset.url,
                  previewVersion
                )
              }

              title={
                asset.search_title ||
                "Video preview"
              }
            />

            <div
              style={
                thumbnailBlockStyle
              }
            >
              <div
                style={
                  thumbnailLabelStyle
                }
              >
                Thumbnail
              </div>

              {asset.thumbnail_url ? (
                <img
                  src={
                    versionedPreviewUrl(
                      asset.thumbnail_url,
                      previewVersion
                    )
                  }

                  alt="Video thumbnail"

                  style={
                    thumbnailPreviewStyle
                  }
                />
              ) : (
                <div
                  style={
                    thumbnailEmptyStyle
                  }
                >
                  No thumbnail yet
                </div>
              )}

            </div>
          </div>


          <div
            style={
              fieldsStyle
            }
          >
            <label
              className="admin-field"
            >
              <span
                className="admin-field__label"
              >
                Title
              </span>

              <input
                className="admin-field__control"

                type="text"

                value={
                  title
                }

                disabled={
                  editorLocked
                }

                onChange={(
                  event
                ) => {
                  setTitle(
                    event
                      .target
                      .value
                  );

                  setSuccessMessage(
                    ""
                  );

                  setActionError(
                    ""
                  );
                }}

                style={{
                  width:
                    "100%",
                }}
              />
            </label>


            <label
              className="admin-field"
            >
              <span
                className="admin-field__label"
              >
                Description
              </span>

              <textarea
                className="admin-field__control"

                rows={9}

                value={
                  description
                }

                disabled={
                  editorLocked
                }

                onChange={(
                  event
                ) => {
                  setDescription(
                    event
                      .target
                      .value
                  );

                  setSuccessMessage(
                    ""
                  );

                  setActionError(
                    ""
                  );
                }}

                style={{
                  width:
                    "100%",

                  resize:
                    "vertical",

                  boxSizing:
                    "border-box",
                }}
              />
            </label>


            {isYouTubeVideo ? (
              <div
                style={
                  descriptionHelpersStyle
                }
              >
                <button
                  type="button"

                  style={
                    descriptionHelperButtonStyle(
                      Boolean(
                        rexLinks.colorsUsedUrl
                        &&
                        descriptionHasUrl(
                          description,
                          rexLinks.colorsUsedUrl
                        )
                      )
                    )
                  }

                  disabled={
                    editorLocked
                    ||
                    rexLinksLoading
                    ||
                    !rexLinks.colorsUsedUrl
                    ||
                    descriptionHasUrl(
                      description,
                      rexLinks.colorsUsedUrl
                    )
                  }

                  title={
                    rexLinks.colorsUsedUrl
                      ? "Append the current Public Colors Used REX URL."
                      : rexLinksLoading
                        ? "Loading Colors Used REX..."
                        : "No ready Colors Used REX for this Playlist."
                  }

                  onClick={() => {
                    appendDescriptionLink(
                      "Colors Used",
                      rexLinks.colorsUsedUrl
                    );
                  }}
                >
                  {
                    rexLinks.colorsUsedUrl
                    &&
                    descriptionHasUrl(
                      description,
                      rexLinks.colorsUsedUrl
                    )
                      ? "✓ Colors Used"
                      : "+ Colors Used"
                  }
                </button>


                <button
                  type="button"

                  style={
                    descriptionHelperButtonStyle(
                      Boolean(
                        rexLinks.playlistUrl
                        &&
                        descriptionHasUrl(
                          description,
                          rexLinks.playlistUrl
                        )
                      )
                    )
                  }

                  disabled={
                    editorLocked
                    ||
                    rexLinksLoading
                    ||
                    !rexLinks.playlistUrl
                    ||
                    descriptionHasUrl(
                      description,
                      rexLinks.playlistUrl
                    )
                  }

                  title={
                    rexLinks.playlistUrl
                      ? "Append this Playlist's permanent Public REX URL."
                      : rexLinksLoading
                        ? "Loading Playlist REX..."
                        : "No Public Playlist REX is ready."
                  }

                  onClick={() => {
                    appendDescriptionLink(
                      "View the Playlist",
                      rexLinks.playlistUrl
                    );
                  }}
                >
                  {
                    rexLinks.playlistUrl
                    &&
                    descriptionHasUrl(
                      description,
                      rexLinks.playlistUrl
                    )
                      ? "✓ Playlist Link"
                      : "+ Playlist Link"
                  }
                </button>


                <button
                  type="button"

                  style={
                    descriptionHelperButtonStyle(
                      descriptionHasUrl(
                        description,
                        COLORFIX_HOME_URL
                      )
                    )
                  }

                  disabled={
                    editorLocked
                    ||
                    descriptionHasUrl(
                      description,
                      COLORFIX_HOME_URL
                    )
                  }

                  title="Append the ColorFix home page."
                  onClick={() => {
                    appendDescriptionLink(
                      "More from ColorFix",
                      COLORFIX_HOME_URL
                    );
                  }}
                >
                  {
                    descriptionHasUrl(
                      description,
                      COLORFIX_HOME_URL
                    )
                      ? "✓ ColorFix Home"
                      : "+ ColorFix Home"
                  }
                </button>


                {rexLinksLoading ? (
                  <span
                    style={
                      descriptionHelperNoteStyle
                    }
                  >
                    Loading REX links…
                  </span>
                ) : rexLinksError ? (
                  <span
                    style={
                      descriptionHelperErrorStyle
                    }
                  >
                    {
                      rexLinksError
                    }
                  </span>
                ) : sourcePlaylistId > 0 ? (
                  <span
                    style={
                      descriptionHelperNoteStyle
                    }
                  >
                    Playlist #
                    {
                      sourcePlaylistId
                    }
                  </span>
                ) : null}
              </div>
            ) : null}


            {isYouTubeVideo ? (
              <div
                style={
                  thumbnailColorEditorRightStyle
                }
              >
                <div
                  style={
                    thumbnailColorLabelStyle
                  }
                >
                  Thumbnail Text Color
                </div>

                <div
                  style={
                    thumbnailColorPickerWrapStyle
                  }
                >
                  <FuzzySearchColorSelect
                    value={
                      thumbnailTextColorPickerValue
                    }

                    compact

                    autoFocus={
                      false
                    }

                    preventAutoFocus

                    showLabel={
                      false
                    }

                    mobileBreakpoint={
                      0
                    }

                    onSelect={(
                      color
                    ) => {
                      const nextColor =
                        color
                          ? colorObjectToHex(
                              color
                            )
                          : DEFAULT_YOUTUBE_THUMBNAIL_TEXT_COLOR;

                      setInsideValues(
                        (
                          current
                        ) => ({
                          ...current,

                          cover: {
                            ...(
                              current
                                ?.cover ||
                              {}
                            ),

                            text_color:
                              normalizeThumbnailTextColor(
                                nextColor
                              ),
                          },
                        })
                      );

                      setSuccessMessage(
                        ""
                      );

                      setActionError(
                        ""
                      );
                    }}
                  />
                </div>

                <div
                  style={
                    thumbnailColorHintStyle
                  }
                >
                  Default is white. Choosing another color changes the
                  thumbnail itself and requires REDO.
                </div>
              </div>
            ) : null}


            {ingredientFields.map(
              (
                field
              ) => (
                <label
                  className="admin-field"

                  key={
                    field.key
                  }
                >
                  <span
                    className="admin-field__label"
                  >
                    {
                      field.label
                    }
                  </span>

                  {field.type ===
                  "textarea" ? (
                    <textarea
                      className="admin-field__control"

                      rows={
                        field.rows ||
                        3
                      }

                      value={
                        insideValues[
                          field.key
                        ] ??
                        ""
                      }

                      disabled={
                        editorLocked
                      }

                      onChange={(
                        event
                      ) => {
                        setInsideValues(
                          (
                            current
                          ) => ({
                            ...current,

                            [
                              field.key
                            ]:
                              event
                                .target
                                .value,
                          })
                        );

                        setSuccessMessage(
                          ""
                        );
                      }}

                      style={{
                        width:
                          "100%",

                        resize:
                          field.rows === 1
                            ? "none"
                            : "vertical",

                        boxSizing:
                          "border-box",
                      }}
                    />
                  ) : (
                    <input
                      className="admin-field__control"

                      type="text"

                      value={
                        insideValues[
                          field.key
                        ] ??
                        ""
                      }

                      disabled={
                        editorLocked
                      }

                      onChange={(
                        event
                      ) => {
                        setInsideValues(
                          (
                            current
                          ) => ({
                            ...current,

                            [
                              field.key
                            ]:
                              event
                                .target
                                .value,
                          })
                        );

                        setSuccessMessage(
                          ""
                        );
                      }}

                      style={{
                        width:
                          "100%",
                      }}
                    />
                  )}
                </label>
              )
            )}



            {isYouTubeVideo ? (
              <div
                style={
                  musicControlStyle
                }
              >
                <div>
                  <div
                    style={
                      musicLabelStyle
                    }
                  >
                    Music
                  </div>

                  <div
                    style={
                      musicValueStyle
                    }
                  >
                    {
                      isShipping
                        ? "Dispatch in progress"
                        : isShipped
                          ? "Production order archived after shipment"
                        : Number(
                            insideValues
                              ?.music
                              ?.asset_library_id ||
                            0
                          ) > 0
                            ? `Asset #${insideValues.music.asset_library_id} · volume ${formatVolume(
                                insideValues.music.volume
                              )}`
                            : "No music selected"
                    }
                  </div>
                </div>

                <button
                  type="button"

                  style={
                    quietButtonStyle
                  }

                  disabled={
                    editorLocked
                  }

                  onClick={() => {
                    setMusicOpen(
                      true
                    );

                    setSuccessMessage(
                      ""
                    );
                  }}
                >
                  ♪ Music
                </button>
              </div>
            ) : null}


            {!isDispatchLocked &&
            hasIngredientChanges ? (
              <div
                style={
                  redoNoticeStyle
                }
              >
                A baked-in value has changed. Saving will require a new render.
              </div>
            ) : null}


            {successMessage ? (
              <div
                style={
                  successStyle
                }
              >
                {
                  successMessage
                }
              </div>
            ) : null}


            {actionError ? (
              <div
                style={
                  actionErrorStyle
                }
              >
                {
                  actionError
                }
              </div>
            ) : null}
          </div>
        </div>


        <div
          style={
            footerStyle
          }
        >
          <label
            style={
              approvalControlStyle
            }
            title={
              approvalEditable
                ? isApproved
                  ? "Uncheck to revoke approval."
                  : "Approve this asset for Packaging."
                : "Approval is locked after the asset leaves CREATED."
            }
          >
            <input
              type="checkbox"

              checked={
                isApproved
              }

              disabled={
                !approvalEditable ||
                approvalSaving ||
                busy
              }

              onChange={
                handleApprovalChange
              }
            />

            <span>
              Approved
            </span>
          </label>


          <button
            type="button"

            style={
              quietButtonStyle
            }

            disabled={
              busy
            }

            onClick={
              onClose
            }
          >
            Close
          </button>


          <button
            type="submit"

            style={
              quietButtonStyle
            }

            disabled={
              editorLocked
            }
          >
            {
              saving
                ? "Saving..."
                : hasIngredientChanges
                  ? "Save & Redo"
                  : "Save"
            }
          </button>


          <button
            type="button"

            disabled={
              editorLocked
            }

            onClick={
              commitAndRedo
            }
          >
            {
              recreating
                ? "Recreating..."
                : "Redo Video"
            }
          </button>


          <button
            type="button"

            disabled={
              editorLocked ||
              approvalSaving ||
              !isApproved
            }

            title={
              isApproved
                ? "Send this approved asset to Packaging."
                : "Approve this asset before sending it to Packaging."
            }

            onClick={
              handleSendToPackaging
            }
          >
            {
              sendingToPackaging
                ? "Sending..."
                : "Send to Packaging"
            }
          </button>
        </div>
      </form>


      {musicOpen ? (
        <PubMusicEditor
          value={
            insideValues
              ?.music ||
            null
          }

          disabled={
            editorLocked
          }

          onApply={(
            music
          ) => {
            setInsideValues(
              (
                current
              ) => ({
                ...current,

                music:
                  music,
              })
            );

            setMusicOpen(
              false
            );

            setSuccessMessage(
              ""
            );
          }}

          onClose={() => {
            setMusicOpen(
              false
            );
          }}
        />
      ) : null}


      {redoWarningOpen ? (
        <div
          style={
            warningOverlayStyle
          }

          onMouseDown={(
            event
          ) => {
            if (
              event.target ===
              event.currentTarget
              &&
              !busy
            ) {
              setRedoWarningOpen(
                false
              );
            }
          }}
        >
          <div
            role="dialog"
            aria-modal="true"
            aria-label="Redo required"

            style={
              warningDialogStyle
            }
          >
            <div
              style={
                warningHeaderStyle
              }
            >
              <strong>
                This change requires a new render
              </strong>
            </div>


            <div
              style={
                warningBodyStyle
              }
            >
              <div>
                One or more edited values are part of the Creator ingredient box.
                Saving them will queue a REDO. This editor will close immediately;
                the asset table will show the video as creating while the render runs.
              </div>

              {redoReasons.length ? (
                <div>
                  <strong>
                    Changed:
                  </strong>

                  <ul
                    style={
                      warningListStyle
                    }
                  >
                    {redoReasons.map(
                      (
                        reason
                      ) => (
                        <li
                          key={
                            reason
                          }
                        >
                          {reason}
                        </li>
                      )
                    )}
                  </ul>
                </div>
              ) : null}

              <div
                style={
                  warningHintStyle
                }
              >
                Choose Keep Editing if you want to make more changes before starting the render.
              </div>
            </div>


            <div
              style={
                warningFooterStyle
              }
            >
              <button
                type="button"

                style={
                  quietButtonStyle
                }

                disabled={
                  busy
                }

                onClick={() => {
                  setRedoWarningOpen(
                    false
                  );
                }}
              >
                Keep Editing
              </button>

              <button
                type="button"

                disabled={
                  busy
                }

                onClick={
                  commitAndRedo
                }
              >
                {
                  saving
                    ? "Saving..."
                    : recreating
                      ? "Queueing..."
                      : "Save & Redo"
                }
              </button>
            </div>
          </div>
        </div>
      ) : null}
    </div>,

    document.body
  );
}


/**
 * Append a client-local version token to a durable preview URL.
 *
 * This guarantees a fresh browser request even when the backend payload
 * does not expose updated_at/checksum and REDO keeps the same asset URL.
 */
function resolveYouTubeDescriptionRexLinks(
  publicExperience
) {
  if (
    !publicExperience
    ||
    typeof publicExperience !==
      "object"
  ) {
    return {
      colorsUsedUrl: "",
      playlistUrl: "",
    };
  }

  const playlistRex =
    publicExperience
      ?.playlist_rex ||
    null;

  const playlistUrl =
    String(
      playlistRex?.status ||
      ""
    )
      .trim()
      .toLowerCase() === "active"
      ? absolutePublicRexUrl(
          playlistRex?.url
        )
      : "";

  const children =
    Array.isArray(
      publicExperience
        ?.children
    )
      ? publicExperience.children
      : [];

  const primaryViewerCount =
    Number(
      publicExperience
        ?.primary_viewer_count ||
      0
    );

  let colorsUsedUrl =
    "";

  if (
    primaryViewerCount ===
    1
  ) {
    const viewer =
      children.find(
        (
          child
        ) =>
          child?.type ===
            "viewer"
          &&
          child?.is_primary ===
            true
          &&
          child?.status ===
            "ready"
          &&
          child?.rex?.url
      );

    colorsUsedUrl =
      absolutePublicRexUrl(
        viewer?.rex?.url
      );
  } else if (
    primaryViewerCount > 1
  ) {
    const thumbs =
      children.find(
        (
          child
        ) =>
          child?.type ===
            "thumbs"
          &&
          child?.required !==
            false
          &&
          child?.status ===
            "ready"
          &&
          child?.rex?.url
      );

    colorsUsedUrl =
      absolutePublicRexUrl(
        thumbs?.rex?.url
      );
  }

  return {
    colorsUsedUrl:
      colorsUsedUrl,

    playlistUrl:
      playlistUrl,
  };
}


function absolutePublicRexUrl(
  url
) {
  const value =
    String(
      url ||
      ""
    )
      .trim();

  if (!value) {
    return "";
  }

  try {
    return new URL(
      value,
      COLORFIX_PUBLIC_ORIGIN
    ).toString();
  } catch {
    return value;
  }
}


function descriptionHasUrl(
  description,
  url
) {
  const target =
    String(
      url ||
      ""
    )
      .trim();

  if (!target) {
    return false;
  }

  return String(
    description ||
    ""
  )
    .split(
      /\s+/
    )
    .some(
      (token) =>
        token
          .replace(
            /[),.;!?]+$/g,
            ""
          ) === target
    );
}


function appendDescriptionLine(
  description,
  line
) {
  const current =
    String(
      description ||
      ""
    )
      .replace(
        /\s+$/g,
        ""
      );

  const nextLine =
    String(
      line ||
      ""
    )
      .trim();

  if (!nextLine) {
    return current;
  }

  if (
    current.includes(
      nextLine
    )
  ) {
    return current;
  }

  return current
    ? `${current}\n\n${nextLine}`
    : nextLine;
}


function versionedPreviewUrl(
  url,
  version
) {
  const value =
    String(
      url ||
      ""
    )
      .trim();


  if (!value) {
    return "";
  }


  const separator =
    value.includes(
      "?"
    )
      ? "&"
      : "?";


  return `${value}${separator}v=${encodeURIComponent(
    String(
      version
    )
  )}`;
}


function buildEditableIngredientValues(
  assetType,
  ingredientFields,
  ingredientValues
) {
  const next = {};


  for (
    const field
    of ingredientFields
  ) {
    next[
      field.key
    ] =
      cloneValue(
        ingredientValues?.[
          field.key
        ] ??
        ""
      );
  }


  if (
    assetType ===
    "youtube_video"
  ) {
    next.music =
      normalizeMusicIngredient(
        ingredientValues
          ?.music
      );

    /*
     * Keep only the editable thumbnail override in local Creator state.
     *
     * The durable cover ingredient contains the source file/title too,
     * but this editor must not resend those untouched values. A deep
     * ingredient patch of cover.text_color is enough.
     *
     * Old orders without text_color inherit the Recipe's white default.
     */
    next.cover = {
      text_color:
        normalizeThumbnailTextColor(
          ingredientValues
            ?.cover
            ?.text_color
        ),
    };
  }


  return next;
}


function normalizeThumbnailTextColor(
  value
) {
  const raw =
    String(
      value ||
      ""
    )
      .trim()
      .toUpperCase();

  if (
    /^#[0-9A-F]{6}$/.test(
      raw
    )
  ) {
    return raw;
  }

  if (
    /^[0-9A-F]{6}$/.test(
      raw
    )
  ) {
    return `#${raw}`;
  }

  return DEFAULT_YOUTUBE_THUMBNAIL_TEXT_COLOR;
}


function thumbnailPickerValueFromHex(
  value
) {
  const hex =
    normalizeThumbnailTextColor(
      value
    );

  return {
    id:
      `thumbnail-text-${hex}`,

    name:
      hex,

    code:
      hex,

    hex6:
      hex.replace(
        "#",
        ""
      ),
  };
}


function colorObjectToHex(
  color
) {
  const hex6 =
    String(
      color
        ?.hex6 ||
      ""
    )
      .trim()
      .replace(
        /^#/,
        ""
      );

  if (
    /^[0-9A-F]{6}$/i.test(
      hex6
    )
  ) {
    return `#${hex6.toUpperCase()}`;
  }

  const hex =
    String(
      color
        ?.hex ||
      ""
    )
      .trim();

  if (
    /^#?[0-9A-F]{6}$/i.test(
      hex
    )
  ) {
    return `#${hex.replace(
      "#",
      ""
    ).toUpperCase()}`;
  }

  return DEFAULT_YOUTUBE_THUMBNAIL_TEXT_COLOR;
}


function normalizeMusicIngredient(
  value
) {
  if (
    !value
    ||
    typeof value !==
      "object"
    ||
    Array.isArray(
      value
    )
  ) {
    return null;
  }


  const id =
    Number(
      value
        .asset_library_id ||
      0
    );


  if (!id) {
    return null;
  }


  const volume =
    Number(
      value.volume
    );


  return {
    asset_library_id:
      id,

    volume:
      Number.isFinite(
        volume
      )
        ? Math.max(
            0,
            Math.min(
              1,
              Math.round(
                volume * 100
              ) / 100
            )
          )
        : 0.35,
  };
}


function diffTopLevel(
  original,
  current
) {
  const changes = {};

  const keys =
    new Set([
      ...Object.keys(
        original ||
        {}
      ),

      ...Object.keys(
        current ||
        {}
      ),
    ]);


  for (
    const key
    of keys
  ) {
    const before =
      original?.[
        key
      ];

    const after =
      current?.[
        key
      ];


    if (
      stableSerialize(
        before
      ) !==
      stableSerialize(
        after
      )
    ) {
      changes[
        key
      ] =
        cloneValue(
          after
        );
    }
  }


  return changes;
}


function describeAllIngredientChanges(
  directChanges,
  boundChanges,
  originalInside,
  currentInside,
  ingredientFields,
  bindings
) {
  const labels = [];


  labels.push(
    ...describeDirectIngredientChanges(
      directChanges,
      originalInside,
      currentInside,
      ingredientFields
    )
  );


  for (
    const binding
    of bindings
  ) {
    const path =
      String(
        binding
          ?.ingredientPath ||
        ""
      )
        .trim();


    if (
      !path
      ||
      !hasPath(
        boundChanges,
        path
      )
    ) {
      continue;
    }


    labels.push(
      binding.label ||
      humanize(
        binding.boxField
      )
    );
  }


  return [
    ...new Set(
      labels
    ),
  ];
}


function describeDirectIngredientChanges(
  changes,
  original,
  current,
  ingredientFields
) {
  const labels = [];

  const fieldsByKey =
    new Map(
      ingredientFields.map(
        (
          field
        ) => [
          field.key,
          field,
        ]
      )
    );


  for (
    const key
    of Object.keys(
      changes
    )
  ) {
    if (
      key === "cover"
    ) {
      const beforeColor =
        normalizeThumbnailTextColor(
          original
            ?.cover
            ?.text_color
        );

      const afterColor =
        normalizeThumbnailTextColor(
          current
            ?.cover
            ?.text_color
        );

      if (
        beforeColor !==
        afterColor
      ) {
        labels.push(
          "Thumbnail text color"
        );
      } else {
        labels.push(
          "Thumbnail"
        );
      }

      continue;
    }


    if (
      key === "music"
    ) {
      const before =
        original?.music ||
        null;

      const after =
        current?.music ||
        null;


      if (
        Number(
          before?.asset_library_id ||
          0
        ) !==
        Number(
          after?.asset_library_id ||
          0
        )
      ) {
        labels.push(
          "Music track"
        );
      }


      if (
        normalizeComparableVolume(
          before?.volume
        ) !==
        normalizeComparableVolume(
          after?.volume
        )
      ) {
        labels.push(
          "Music volume"
        );
      }


      if (
        !labels.includes(
          "Music track"
        )
        &&
        !labels.includes(
          "Music volume"
        )
      ) {
        labels.push(
          "Music"
        );
      }


      continue;
    }


    labels.push(
      fieldsByKey
        .get(
          key
        )
        ?.label ||
      humanize(
        key
      )
    );
  }


  return labels;
}


/**
 * Resolve the Chef's metadata -> ingredient bindings.
 *
 * Explicit PubContract bindings win.
 *
 * Compatibility fallback:
 * if the loaded ingredient box already contains search_title or
 * description at the top level, treat that metadata field as baked-in
 * even when the parent has not yet supplied its contract bindings.
 */
function resolveIngredientBindings(
  suppliedBindings,
  ingredientValues
) {
  const normalized = [];

  const raw =
    Array.isArray(
      suppliedBindings
    )
      ? suppliedBindings
      : [];


  for (
    const binding
    of raw
  ) {
    const boxField =
      String(
        binding
          ?.boxField ??
        binding
          ?.box_field ??
        ""
      )
        .trim();

    const ingredientPath =
      String(
        binding
          ?.ingredientPath ??
        binding
          ?.ingredient_path ??
        ""
      )
        .trim();


    if (
      !boxField
      ||
      !ingredientPath
    ) {
      continue;
    }


    normalized.push({
      boxField:
        boxField,

      ingredientPath:
        ingredientPath,

      label:
        binding?.label ||
        humanize(
          boxField
        ),
    });
  }


  for (
    const field
    of [
      "search_title",
      "description",
    ]
  ) {
    if (
      !Object.prototype
        .hasOwnProperty
        .call(
          ingredientValues ||
          {},
          field
        )
    ) {
      continue;
    }


    const alreadyBound =
      normalized.some(
        (
          binding
        ) =>
          binding.boxField ===
            field
      );


    if (
      !alreadyBound
    ) {
      normalized.push({
        boxField:
          field,

        ingredientPath:
          field,

        label:
          humanize(
            field
          ),
      });
    }
  }


  return normalized;
}


/**
 * Mirror edited metadata into the ingredient patch only when:
 *   1. the metadata field actually changed, and
 *   2. the mapped ingredient value would actually change.
 */
function buildBoundIngredientChanges(
  bindings,
  ingredientValues,
  originalMetadata,
  currentMetadata
) {
  const changes = {};


  for (
    const binding
    of bindings
  ) {
    const boxField =
      binding.boxField;

    const ingredientPath =
      binding.ingredientPath;


    if (
      stableSerialize(
        originalMetadata?.[
          boxField
        ]
      ) ===
      stableSerialize(
        currentMetadata?.[
          boxField
        ]
      )
    ) {
      continue;
    }


    const nextValue =
      currentMetadata?.[
        boxField
      ];


    const currentIngredientValue =
      getPath(
        ingredientValues,
        ingredientPath
      );


    if (
      stableSerialize(
        currentIngredientValue
      ) ===
      stableSerialize(
        nextValue
      )
    ) {
      continue;
    }


    setPath(
      changes,
      ingredientPath,
      cloneValue(
        nextValue
      )
    );
  }


  return changes;
}


function mergeDeep(
  left,
  right
) {
  const result =
    cloneValue(
      left ||
      {}
    ) || {};


  for (
    const [
      key,
      value,
    ]
    of Object.entries(
      right ||
      {}
    )
  ) {
    if (
      value
      &&
      typeof value ===
        "object"
      &&
      !Array.isArray(
        value
      )
      &&
      result[
        key
      ]
      &&
      typeof result[
        key
      ] ===
        "object"
      &&
      !Array.isArray(
        result[
          key
        ]
      )
    ) {
      result[
        key
      ] =
        mergeDeep(
          result[
            key
          ],
          value
        );

      continue;
    }


    result[
      key
    ] =
      cloneValue(
        value
      );
  }


  return result;
}


function getPath(
  object,
  path
) {
  const parts =
    String(
      path ||
      ""
    )
      .split(".")
      .filter(Boolean);


  let cursor =
    object;


  for (
    const part
    of parts
  ) {
    if (
      cursor === null
      ||
      cursor === undefined
      ||
      typeof cursor !==
        "object"
    ) {
      return undefined;
    }


    cursor =
      cursor[
        part
      ];
  }


  return cursor;
}


function setPath(
  object,
  path,
  value
) {
  const parts =
    String(
      path ||
      ""
    )
      .split(".")
      .filter(Boolean);


  if (!parts.length) {
    return;
  }


  let cursor =
    object;


  for (
    let index = 0;
    index < parts.length - 1;
    index += 1
  ) {
    const key =
      parts[
        index
      ];


    if (
      !cursor[
        key
      ]
      ||
      typeof cursor[
        key
      ] !==
        "object"
      ||
      Array.isArray(
        cursor[
          key
        ]
      )
    ) {
      cursor[
        key
      ] = {};
    }


    cursor =
      cursor[
        key
      ];
  }


  cursor[
    parts[
      parts.length - 1
    ]
  ] =
    value;
}


function hasPath(
  object,
  path
) {
  const sentinel = {};

  return (
    getPathWithFallback(
      object,
      path,
      sentinel
    ) !== sentinel
  );
}


function getPathWithFallback(
  object,
  path,
  fallback
) {
  const value =
    getPath(
      object,
      path
    );


  return value ===
    undefined
      ? fallback
      : value;
}


function normalizeComparableVolume(
  value
) {
  const number =
    Number(
      value
    );


  return Number.isFinite(
    number
  )
    ? Math.round(
        number * 100
      ) / 100
    : null;
}


function stableSerialize(
  value
) {
  return JSON.stringify(
    normalizeForCompare(
      value
    )
  );
}


function normalizeForCompare(
  value
) {
  if (
    Array.isArray(
      value
    )
  ) {
    return value.map(
      normalizeForCompare
    );
  }


  if (
    value
    &&
    typeof value ===
      "object"
  ) {
    const result = {};

    for (
      const key
      of Object.keys(
        value
      ).sort()
    ) {
      result[
        key
      ] =
        normalizeForCompare(
          value[
            key
          ]
        );
    }

    return result;
  }


  return value;
}


function cloneValue(
  value
) {
  if (
    value === undefined
  ) {
    return undefined;
  }


  return JSON.parse(
    JSON.stringify(
      value
    )
  );
}


function formatDurationMs(
  value
) {
  const milliseconds =
    Number(
      value
    );


  if (
    !Number.isFinite(
      milliseconds
    )
    ||
    milliseconds <= 0
  ) {
    return "—";
  }


  const totalSeconds =
    Math.round(
      milliseconds / 1000
    );

  const minutes =
    Math.floor(
      totalSeconds / 60
    );

  const seconds =
    totalSeconds % 60;


  return `${minutes}:${String(
    seconds
  ).padStart(
    2,
    "0"
  )}`;
}


function formatVolume(
  value
) {
  const number =
    Number(
      value
    );


  if (
    !Number.isFinite(
      number
    )
  ) {
    return "—";
  }


  return number
    .toFixed(
      2
    );
}


function humanize(
  value
) {
  return String(
    value ||
    ""
  )
    .replace(
      /[_-]+/g,
      " "
    )
    .replace(
      /\b\w/g,
      (
        character
      ) =>
        character
          .toUpperCase()
    );
}


const overlayStyle = {
  position:
    "fixed",

  inset:
    0,

  zIndex:
    2147483647,

  display:
    "grid",

  placeItems:
    "center",

  padding:
    30,

  background:
    "rgba(0, 0, 0, 0.55)",
};


const dialogStyle = {
  width:
    "min(1040px, 96vw)",

  maxHeight:
    "94vh",

  display:
    "flex",

  flexDirection:
    "column",

  background:
    "#ffffff",

  border:
    "1px solid #cfd5dc",

  borderRadius:
    5,

  boxShadow:
    "0 18px 50px rgba(0,0,0,0.28)",
};


const headerStyle = {
  display:
    "flex",

  alignItems:
    "center",

  justifyContent:
    "space-between",

  padding:
    "10px 12px",

  borderBottom:
    "1px solid #d8dde3",
};


const headerRightStyle = {
  display:
    "flex",

  alignItems:
    "center",

  gap:
    10,
};


const shippedBadgeStyle = {
  padding:
    "3px 7px",

  border:
    "1px solid #9bbda7",

  borderRadius:
    999,

  background:
    "#eef8f1",

  color:
    "#2f6b43",

  fontSize:
    11,

  fontWeight:
    800,

  letterSpacing:
    "0.04em",

  whiteSpace:
    "nowrap",
};


const assetIdStyle = {
  color:
    "#586675",

  fontSize:
    12,

  fontWeight:
    600,

  whiteSpace:
    "nowrap",
};


const bodyStyle = {
  display:
    "grid",

  gridTemplateColumns:
    "minmax(320px, 1.15fr) minmax(0, 1fr)",

  gap:
    20,

  padding:
    16,

  overflow:
    "auto",
};


const playerColumnStyle = {
  minWidth:
    0,

  display:
    "flex",

  flexDirection:
    "column",

  gap:
    14,
};


const fieldsStyle = {
  display:
    "flex",

  flexDirection:
    "column",

  gap:
    14,

  minWidth:
    0,
};


const thumbnailBlockStyle = {
  width:
    "min(300px, 70%)",
};


const thumbnailColorEditorRightStyle = {
  display:
    "flex",

  flexDirection:
    "column",

  gap:
    5,

  padding:
    "10px 12px",

  border:
    "1px solid #d8dde3",

  borderRadius:
    4,

  background:
    "#f8fafc",

  overflow:
    "visible",
};


const thumbnailColorPickerWrapStyle = {
  position:
    "relative",

  zIndex:
    20,

  width:
    "100%",

  overflow:
    "visible",
};


const thumbnailColorLabelStyle = {
  fontSize:
    12,

  fontWeight:
    700,

  color:
    "#334155",
};


const thumbnailColorHintStyle = {
  color:
    "#64748b",

  fontSize:
    11,

  lineHeight:
    1.35,
};


const thumbnailLabelStyle = {
  marginBottom:
    5,

  fontSize:
    12,

  fontWeight:
    700,

  color:
    "#334155",
};


const thumbnailPreviewStyle = {
  display:
    "block",

  width:
    "100%",

  maxHeight:
    170,

  objectFit:
    "contain",

  background:
    "#f4f5f6",

  border:
    "1px solid #d8dde3",

  borderRadius:
    3,
};


const thumbnailEmptyStyle = {
  minHeight:
    72,

  display:
    "grid",

  placeItems:
    "center",

  background:
    "#f8fafc",

  border:
    "1px dashed #cfd5dc",

  borderRadius:
    3,

  color:
    "#64748b",

  fontSize:
    12,
};


const descriptionHelpersStyle = {
  display:
    "flex",

  alignItems:
    "center",

  flexWrap:
    "wrap",

  gap:
    7,

  marginTop:
    -6,
};


const descriptionHelperButtonStyle = (
  added
) => ({
  ...quietButtonStyle,

  background:
    added
      ? "#eef8f1"
      : "#f8fafc",

  borderColor:
    added
      ? "#9bbda7"
      : "#cfd5dc",

  color:
    added
      ? "#2f6b43"
      : "#334155",

  fontWeight:
    added
      ? 700
      : 600,
});


const descriptionHelperNoteStyle = {
  color:
    "#64748b",

  fontSize:
    11,
};


const descriptionHelperErrorStyle = {
  color:
    "#8a3b32",

  fontSize:
    11,

  fontWeight:
    600,
};


const musicControlStyle = {
  display:
    "flex",

  alignItems:
    "center",

  justifyContent:
    "space-between",

  gap:
    12,

  padding:
    "10px 12px",

  border:
    "1px solid #d8dde3",

  borderRadius:
    4,

  background:
    "#f8fafc",
};


const musicLabelStyle = {
  fontSize:
    12,

  fontWeight:
    700,

  color:
    "#334155",
};


const musicValueStyle = {
  marginTop:
    3,

  fontSize:
    12,

  color:
    "#64748b",
};


const redoNoticeStyle = {
  padding:
    "8px 10px",

  border:
    "1px solid #e5c07b",

  background:
    "#fffaf0",

  color:
    "#75530d",

  fontSize:
    12,
};


const approvalControlStyle = {
  display:
    "flex",

  alignItems:
    "center",

  gap:
    7,

  marginRight:
    "auto",

  color:
    "#334155",

  fontSize:
    12,

  fontWeight:
    700,

  cursor:
    "pointer",
};


const footerStyle = {
  display:
    "flex",

  justifyContent:
    "flex-end",

  gap:
    8,

  padding:
    "10px 12px",

  borderTop:
    "1px solid #d8dde3",
};


const successStyle = {
  padding:
    "8px 10px",

  border:
    "1px solid #cfd5dc",

  background:
    "#f6f8f9",

  fontSize:
    12,
};


const actionErrorStyle = {
  padding:
    "8px 10px",

  border:
    "1px solid #e2baba",

  background:
    "#fff7f7",

  color:
    "#7d2e2e",

  fontSize:
    12,
};


const warningOverlayStyle = {
  position:
    "fixed",

  inset:
    0,

  zIndex:
    2147483647,

  display:
    "grid",

  placeItems:
    "center",

  padding:
    30,

  background:
    "rgba(0, 0, 0, 0.45)",
};


const warningDialogStyle = {
  width:
    "min(520px, 94vw)",

  background:
    "#ffffff",

  border:
    "1px solid #cfd5dc",

  borderRadius:
    5,

  boxShadow:
    "0 18px 50px rgba(0,0,0,0.28)",
};


const warningHeaderStyle = {
  padding:
    "12px 14px",

  borderBottom:
    "1px solid #d8dde3",
};


const warningBodyStyle = {
  display:
    "flex",

  flexDirection:
    "column",

  gap:
    14,

  padding:
    16,

  fontSize:
    13,

  lineHeight:
    1.45,

  color:
    "#334155",
};


const warningListStyle = {
  margin:
    "7px 0 0 20px",

  padding:
    0,
};


const warningHintStyle = {
  color:
    "#64748b",

  fontSize:
    12,
};


const warningFooterStyle = {
  display:
    "flex",

  justifyContent:
    "flex-end",

  gap:
    8,

  padding:
    "10px 12px",

  borderTop:
    "1px solid #d8dde3",
};


const quietButtonStyle = {
  padding:
    "4px 8px",

  minHeight:
    0,

  border:
    "1px solid #cfd5dc",

  borderRadius:
    3,

  background:
    "#ffffff",

  color:
    "#334155",

  fontSize:
    12,

  lineHeight:
    1.2,

  fontWeight:
    500,

  cursor:
    "pointer",
};
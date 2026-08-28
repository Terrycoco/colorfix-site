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
  }, [
    asset?.pub_asset_id,
    asset?.search_title,
    asset?.description,
    assetType,
    ingredientFields,
    ingredientValues,
  ]);


  if (!asset) {
    return null;
  }


  const busy =
    saving ||
    recreating ||
    sendingToPackaging;


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


  async function handleSave(
    event
  ) {
    event.preventDefault();

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


  async function handleSendToPackaging() {
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
                  busy
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
                  busy
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
                        busy
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
                        busy
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


            <div>
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
                      Number(
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
                    busy
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


            {hasIngredientChanges ? (
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
              busy
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
              busy
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
              busy
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
            busy
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
  }


  return next;
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
    220,

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
import {
  useEffect,
  useMemo,
  useState,
} from "react";

import {
  createPortal,
} from "react-dom";

import PubVideoPlayer from "./PubVideoPlayer";


const VIDEO_INGREDIENT_FIELDS = {
  pin_before_after_video: [
    {
      key: "end_slide_text",
      label: "End Slide Text",
      type: "textarea",
      rows: 3,
    },
  ],

  youtube_video: [],
};


export default function PubVideoAssetEditor({
  asset,
  ingredientValues = {},
  saving = false,
  recreating = false,
  onSave,
  onRecreate,
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
    insideValues,
    setInsideValues,
  ] = useState({});

  const [
    successMessage,
    setSuccessMessage,
  ] = useState("");


  const ingredientFields =
    useMemo(
      () => {
        const assetType =
          String(
            asset?.asset_type ||
            ""
          )
            .trim()
            .toLowerCase();

        return VIDEO_INGREDIENT_FIELDS[
          assetType
        ] || [];
      },
      [
        asset?.asset_type,
      ]
    );


  useEffect(() => {
    setTitle(
      asset?.search_title ||
      ""
    );

    setDescription(
      asset?.description ||
      ""
    );

    const nextInsideValues = {};

    for (
      const field
      of ingredientFields
    ) {
      nextInsideValues[
        field.key
      ] =
        ingredientValues?.[
          field.key
        ] ??
        "";
    }

    setInsideValues(
      nextInsideValues
    );

    setSuccessMessage(
      ""
    );
  }, [
    asset,
    ingredientFields,
    ingredientValues,
  ]);


  if (!asset) {
    return null;
  }


  const busy =
    saving ||
    recreating;


  function currentChanges() {
    return {
      search_title:
        title,

      description:
        description,

      /*
       * Only editable inside ingredients are returned.
       * Source files/URLs and all other production
       * ingredients stay untouched.
       */
      ingredient_changes:
        insideValues,
    };
  }


  async function handleSave(
    event
  ) {
    event.preventDefault();

    setSuccessMessage(
      ""
    );

    const result =
      await onSave?.(
        currentChanges()
      );

    if (
      result !== false
    ) {
      setSuccessMessage(
        "Changes saved."
      );
    }
  }


async function handleRecreate() {
  setSuccessMessage("");

  /*
   * Save current editor values first so REDO uses
   * the edited durable ingredients.
   */
  const saved =
    await onSave?.(
      currentChanges()
    );

  if (
    saved === false
  ) {
    return;
  }

  /*
   * REDO only queues asynchronous production.
   * There is no new video to preview yet.
   */
  const recreated =
    await onRecreate?.(
      asset.pub_asset_id
    );

  if (
    recreated === false
  ) {
    return;
  }

  onClose?.();
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
                asset.url ||
                ""
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
                          "vertical",

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
            {saving
              ? "Saving..."
              : "Save"}
          </button>


          <button
            type="button"

            disabled={
              busy
            }

            onClick={
              handleRecreate
            }
          >
            {recreating
              ? "Recreating..."
              : "Redo Video"}
          </button>
        </div>
      </form>
    </div>,

    document.body
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
